<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Modules\ProcurementStores\Services\ProjectMaterialResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report 78B — Stores users resolving the same line at the same moment.
 *
 * Real processes, real commits: RefreshDatabase's transaction would be
 * invisible to the other racers, so fixtures are committed and removed by id
 * afterwards. Runs only against a database whose name ends in _test.
 */
class ResolveMaterialConcurrencyTest extends TestCase
{
    private array $snapshot = [];
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for true concurrency tests.');
        }
        $database = DB::connection()->getDatabaseName();
        if (! str_ends_with($database, '_test')) {
            $this->fail("This test commits data and must only run against a test database, not {$database}.");
        }
        if (! DB::getSchemaBuilder()->hasColumn('element_materials', 'requirement_base_factor')) {
            $this->markTestSkipped('The test schema is not migrated yet; run the Feature suite first.');
        }

        foreach (collect(DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
            [$database],
        ))->pluck('t') as $table) {
            $this->snapshot[$table] = (int) DB::table($table)->max('id');
        }
        $this->dir = sys_get_temp_dir().'/r78b-race-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->snapshot as $table => $maxId) {
                DB::table($table)->where('id', '>', $maxId)->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_simultaneous_resolutions_of_one_line_link_it_once(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Racer', 'email' => uniqid('racer_').'@t.local', 'password' => bcrypt('x'), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi',
            'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
            'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enquiryId = DB::table('project_enquiries')->insertGetId([
            // Report 78C: a job being delivered — the enquiry status is the lifecycle authority.
            'status' => 'in_progress',
            'date_received' => now()->toDateString(), 'client_id' => $clientId, 'title' => 'Race', 'contact_person' => 'C',
            'enquiry_number' => 'ENQ-RACE-'.uniqid(), 'job_number' => 'WNG-RACE-'.uniqid(), 'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiryId, 'type' => 'materials', 'title' => 'Materials', 'status' => 'in_progress',
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId, 'project_info' => json_encode(['approval_status' => ['all_approved' => true]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $elementId = DB::table('project_deliverables')->insertGetId([
            'persistent_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'task_materials_data_id' => $dataId,
            'enquiry_id' => $enquiryId, 'classification' => 'PRE-DEFINED', 'status' => 'original', 'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = DB::table('element_materials')->insertGetId([
            'persistent_id' => (string) Str::uuid(), 'project_element_id' => $elementId, 'description' => 'MDF 18mm (typed)',
            'unit_of_measurement' => 'sheet', 'quantity' => 10, 'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sheet = (int) DB::table('units_of_measure')->where('code', 'sheet')->value('id');
        $materials = [];
        foreach (['MDF Board 18mm A', 'MDF Board 18mm B', 'MDF Board 18mm C', 'MDF Board 18mm D'] as $name) {
            $materials[] = DB::table('library_materials')->insertGetId([
                'material_name' => $name, 'material_code' => 'RACE-'.uniqid(), 'category' => 'Boards', 'unit_of_measure' => 'sheet',
                'base_uom_id' => $sheet, 'unit_cost' => 1000, 'item_status' => 'Active', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $results = $this->race(4, function (int $i) use ($lineId, $materials, $userId) {
            app(ProjectMaterialResolution::class)->resolve(ElementMaterial::findOrFail($lineId), $materials[$i], $userId);
        });

        $winners = array_keys(array_filter($results, fn ($r) => $r === 'ok'));
        $this->assertCount(1, $winners, implode(' | ', $results));
        $line = ElementMaterial::findOrFail($lineId);
        $this->assertSame($materials[$winners[0]], $line->library_material_id);
        $this->assertCount(1, $line->source_metadata['catalogue_resolutions']);
        foreach ($results as $result) {
            if ($result !== 'ok') {
                $this->assertStringContainsString('already linked', $result);
            }
        }
    }

    /** @return array<int, string> */
    private function race(int $racers, callable $work): array
    {
        $barrier = $this->dir.'/go';
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < $racers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                $result = 'ok';
                try {
                    DB::purge();
                    DB::reconnect();
                    while (! file_exists($barrier)) {
                        usleep(200);
                    }
                    $work($i);
                } catch (\Throwable $e) {
                    $result = get_class($e).': '.($e instanceof \Illuminate\Validation\ValidationException ? json_encode($e->errors()) : $e->getMessage());
                }
                file_put_contents("{$this->dir}/result-{$i}", $result);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        usleep(400000);
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        unlink($barrier);
        DB::reconnect();

        $results = [];
        for ($i = 0; $i < $racers; $i++) {
            $raw = @file_get_contents("{$this->dir}/result-{$i}");
            $this->assertNotFalse($raw, 'A racer died without reporting.');
            $results[] = $raw;
        }

        return $results;
    }
}
