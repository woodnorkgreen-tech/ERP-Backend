<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\ProcurementStores\Services\StockMovementPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Report 79E — two Stores users issue the whole remaining quantity of one
 * approved line at the same moment. Real forked processes, real commits on a
 * *_test database; fixtures removed by id afterwards. (Grown from the Report
 * 79D characterisation probe, which recorded the defects these now refuse.)
 */
class IssueConcurrencyTest extends TestCase

{
    use \Tests\Concerns\GrantsMatrixPermissions;

    private array $snapshot = [];
    private string $dir;
    private int $userId;
    private int $projectId;
    private int $lineId;
    private int $materialId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required.');
        }
        $database = DB::connection()->getDatabaseName();
        if (! str_ends_with($database, '_test')) {
            $this->fail("Commits data; refusing to run against {$database}.");
        }
        if (! DB::getSchemaBuilder()->hasTable('stock_movement_submissions')
            || ! DB::getSchemaBuilder()->hasColumn('element_materials', 'requirement_base_factor')) {
            $this->markTestSkipped('Schema not migrated yet; run the Feature suite first.');
        }
        foreach (collect(DB::select("SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'", [$database]))->pluck('t') as $table) {
            $this->snapshot[$table] = (int) DB::table($table)->max('id');
        }
        $this->dir = sys_get_temp_dir().'/r79e-race-'.uniqid();
        mkdir($this->dir);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if (isset($this->userId)) {
                DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $this->userId)->delete();
            }
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

    private function fixture(): void
    {
        $now = now();
        \Spatie\Permission\Models\Role::findOrCreate('Stores', 'web');
        $this->grantMatrixPermissions('Stores');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Stores');
        $this->userId = $user->id;
        $pcs = (int) (DB::table('units_of_measure')->where('code', 'pcs')->value('id')
            ?: DB::table('units_of_measure')->insertGetId(['code' => 'pcs', 'name' => 'Pieces', 'dimension' => 'count', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]));
        $clientId = DB::table('clients')->insertGetId(['full_name' => 'Race Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email', 'registration_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        $enquiryId = DB::table('project_enquiries')->insertGetId(['status' => 'in_progress', 'date_received' => $now->toDateString(), 'client_id' => $clientId, 'title' => 'Race job', 'contact_person' => 'C', 'enquiry_number' => 'ENQ-R79E-'.uniqid(), 'job_number' => 'WNG-R79E-'.uniqid(), 'created_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now]);
        $this->projectId = DB::table('projects')->insertGetId(['enquiry_id' => $enquiryId, 'project_id' => 'WNG-R79E', 'status' => 'in_progress', 'created_at' => $now, 'updated_at' => $now]);
        $taskId = DB::table('enquiry_tasks')->insertGetId(['project_enquiry_id' => $enquiryId, 'type' => 'materials', 'title' => 'Materials', 'status' => 'in_progress', 'created_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now]);
        $dataId = DB::table('task_materials_data')->insertGetId(['enquiry_task_id' => $taskId, 'project_info' => json_encode(['approval_status' => ['project_officer' => ['approved' => true], 'production' => ['approved' => true], 'all_approved' => true]]), 'created_at' => $now, 'updated_at' => $now]);
        $elementId = \App\Models\ProjectElement::create(['persistent_id' => (string) Str::uuid(), 'task_materials_data_id' => $dataId, 'element_type' => 'stand', 'name' => 'DESK', 'category' => 'production', 'is_included' => true])->id;
        $this->materialId = DB::table('library_materials')->insertGetId(['material_name' => 'Race Glue '.uniqid(), 'material_code' => 'RACE-'.uniqid(), 'material_type' => 'consumable', 'unit_of_measure' => 'pcs', 'base_uom_id' => $pcs, 'unit_cost' => 100, 'item_status' => 'Active', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('stocks')->insert(['material_id' => $this->materialId, 'quantity_on_hand' => 20, 'quantity_reserved' => 0, 'warehouse_code' => 'MAIN', 'created_at' => $now, 'updated_at' => $now]);
        $this->lineId = DB::table('element_materials')->insertGetId(['persistent_id' => (string) Str::uuid(), 'project_element_id' => $elementId, 'library_material_id' => $this->materialId, 'description' => 'Race glue', 'unit_of_measurement' => 'pcs', 'quantity' => 5, 'is_included' => true, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function line(): array
    {
        return ['material_id' => $this->materialId, 'project_material_id' => $this->lineId, 'project_id' => $this->projectId, 'quantity' => 5, 'recipient_name' => 'Racer', 'reference_no' => 'WNG-R79E'];
    }

    private function issued(): float
    {
        return (float) DB::table('inventory_logs')->where('project_material_id', $this->lineId)->sum(DB::raw('ABS(quantity)'));
    }

    public function test_the_movements_shape_never_over_issues(): void
    {
        $results = $this->race(2, function () {
            DB::transaction(function () {
                $poster = app(StockMovementPoster::class);
                $batch = $poster->newBatchNumber();
                $poster->post('issue', $this->line() + ['batch_number' => $batch]);
            });
        });

        $this->assertSame(5.0, $this->issued(), 'Only the requirement may be issued: '.json_encode($results));
    }

    /**
     * The case the batch-number lock used to hide: a caller that reads first
     * (fixing its snapshot) and posts with its own batch number. Before 79E
     * the line cap's plain SUM could read that old snapshot.
     */
    public function test_a_caller_that_reads_first_still_cannot_over_issue(): void
    {
        $results = $this->race(2, function (int $i) {
            // MariaDB 11.8 checks snapshots and would stop the second racer
            // with error 1020 whatever the code did. Older MariaDB and MySQL
            // (production may run one) do not: there a plain SUM after the
            // lock read the stale snapshot and over-issued. Simulate them.
            DB::statement('SET SESSION innodb_snapshot_isolation = OFF');
            DB::transaction(function () use ($i) {
                DB::table('projects')->where('id', $this->projectId)->value('enquiry_id');
                usleep(200000);
                app(StockMovementPoster::class)->post('issue', $this->line() + ['batch_number' => 'RACE-'.$i.'-'.uniqid()]);
            });
        });

        $this->assertSame(5.0, $this->issued(), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => $r === 'ok'), json_encode($results));
        // The locking SUM reads the committed issue, so the second racer is
        // refused with a reason a person can act on.
        $refused = array_values(array_filter($results, fn ($r) => $r !== 'ok'))[0] ?? '';
        $this->assertStringContainsString('ValidationException', $refused);
        $this->assertStringContainsString('remaining', $refused);
    }

    public function test_two_resends_of_one_confirmation_at_once_issue_once(): void
    {
        $id = (string) Str::uuid();
        $results = $this->race(2, function () use ($id) {
            Sanctum::actingAs(User::findOrFail($this->userId));
            $response = $this->postJson('/api/procurement-stores/movements', [
                'type' => 'issue', 'client_request_id' => $id, 'lines' => [['quantity' => 2] + $this->line()],
            ]);
            if ($response->status() !== 200) {
                throw new \RuntimeException('HTTP '.$response->status().' '.$response->getContent());
            }
        });

        $this->assertSame(2.0, $this->issued(), json_encode($results));
        $this->assertSame(['ok', 'ok'], $results, 'Both callers get an answer; one is the replay.');
    }

    /** @return array<int, string> */
    private function race(int $racers, callable $work): array
    {
        $barrier = $this->dir.'/go';
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < $racers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $result = 'ok';
                try {
                    DB::purge();
                    DB::reconnect();
                    auth()->setUser(User::findOrFail($this->userId));
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

        return array_map(fn ($i) => (string) @file_get_contents("{$this->dir}/result-{$i}"), range(0, $racers - 1));
    }
}
