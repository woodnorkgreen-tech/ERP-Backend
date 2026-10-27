<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialRegistrationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Report 79C — people registering the same new material at the same moment.
 *
 * Real processes, real commits (RefreshDatabase's transaction would be
 * invisible to the other racers); fixtures are removed by id afterwards.
 * Runs only against a database whose name ends in _test.
 */
class MaterialRegistrationConcurrencyTest extends TestCase
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
        if (! DB::getSchemaBuilder()->hasColumn('library_materials', 'registration_review')) {
            $this->markTestSkipped('The test schema is not migrated yet; run the Feature suite first.');
        }

        foreach (collect(DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
            [$database],
        ))->pluck('t') as $table) {
            $this->snapshot[$table] = (int) DB::table($table)->max('id');
        }
        $this->dir = sys_get_temp_dir().'/r79c-race-'.uniqid();
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

    public function test_simultaneous_registrations_of_one_new_material_create_one(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $name = 'Race Glue '.uniqid();

        $results = $this->race(4, function (int $i) use ($user, $name) {
            auth()->setUser($user);
            // Different spellings of the same identity.
            $spellings = [$name, mb_strtolower($name), mb_strtoupper($name), $name.' '];
            app(MaterialRegistrationService::class)->create(['material_name' => trim($spellings[$i])], (int) $user->id);
        });

        $this->assertSame(1, LibraryMaterial::where('material_name', 'like', 'Race Glue%')->count(), 'Exactly one racer may register it.');
        $this->assertSame(1, collect($results)->filter(fn ($r) => $r === 'ok')->count());
        foreach ($results as $result) {
            if ($result !== 'ok') {
                $this->assertStringContainsString('already exists', $result);
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
