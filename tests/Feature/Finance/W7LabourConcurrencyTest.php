<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineTransfer;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActual;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Finance\CostCollector\Services\CostTransferService;
use App\Modules\Finance\CostCollector\Services\ProjectLabourActualService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * W7 concurrency — genuine races, not serialized calls.
 *
 * Each scenario commits its fixture, forks N child processes, gives every child
 * its own database connection, releases them together at a barrier, and has each
 * perform the same transition at the same moment. The parent then asserts one
 * authoritative outcome: no duplicate CostLine, no branching successor, no second
 * transfer, no double financial effect.
 *
 * This class cannot use RefreshDatabase: its wrapping transaction is invisible to
 * the children's connections. Instead it snapshots every table's max id and
 * deletes anything newer in tearDown, and it refuses to run against any database
 * other than db_test.
 */
class W7LabourConcurrencyTest extends TestCase
{
    private const RACERS = 4;

    private array $snapshot = [];
    private string $dir;
    private User $financier;
    private User $projectOfficer;
    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for true concurrency tests.');
        }
        if (DB::connection()->getDatabaseName() !== 'db_test') {
            $this->fail('W7LabourConcurrencyTest commits data and must only run against db_test.');
        }
        if (!DB::getSchemaBuilder()->hasTable('project_labour_actual_returns')) {
            $this->markTestSkipped('db_test schema is not migrated yet; run the Feature suite first.');
        }

        $this->takeSnapshot();
        $this->dir = sys_get_temp_dir() . '/w7race-' . uniqid();
        mkdir($this->dir);

        config(['finance_accounts.seed_reference_chart' => true, 'finance_accounts.map' => []]);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $perms = [
            Permissions::FINANCE_LABOUR_VIEW, Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_PO_VERIFY, Permissions::FINANCE_LABOUR_FINANCE_VERIFY,
            Permissions::FINANCE_LABOUR_CORRECT,
        ];
        foreach ($perms as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $finance = Role::create(['name' => 'W7 Race Finance ' . uniqid(), 'guard_name' => 'web']);
        $finance->givePermissionTo([Permissions::FINANCE_LABOUR_VIEW, Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_FINANCE_VERIFY, Permissions::FINANCE_LABOUR_CORRECT]);
        $pm = Role::create(['name' => 'W7 Race PM ' . uniqid(), 'guard_name' => 'web']);
        $pm->givePermissionTo([Permissions::FINANCE_LABOUR_VIEW, Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_PO_VERIFY]);

        $this->financier = User::factory()->create(['is_active' => true]);
        $this->financier->assignRole($finance);
        $this->projectOfficer = User::factory()->create(['is_active' => true]);
        $this->projectOfficer->assignRole($pm);
        $this->recorder = User::factory()->create(['is_active' => true]);
        $this->recorder->assignRole($pm);
    }

    protected function tearDown(): void
    {
        if ($this->snapshot !== []) {
            $this->restoreSnapshot();
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    // ── Scenarios ─────────────────────────────────────────────────────────────

    public function test_simultaneous_finance_verify_creates_one_cost_line(): void
    {
        [$enquiry] = $this->project();
        $actual = $this->poVerified($enquiry, 10);

        $results = $this->race(fn () => $this->service()->financeVerify(ProjectLabourActual::findOrFail($actual->id), User::findOrFail($this->financier->id)));

        // Idempotent: every racer ends with the same single outcome.
        $this->assertSame(array_fill(0, self::RACERS, 'ok'), $results);
        $this->assertSame(1, CostLine::where('source_type', 'ProjectLabourActual')->where('source_id', $actual->id)->count());
        $this->assertSame(1, DB::table('governance_audit_logs')->where('model_id', $actual->id)
            ->where('model_type', ProjectLabourActual::class)->where('action_status', 'finance_verify')->count());
        $this->assertSame('20000.00', $this->verifiedLabour($enquiry));
    }

    public function test_simultaneous_corrections_open_one_successor(): void
    {
        [$enquiry] = $this->project();
        $actual = $this->verified($enquiry, 10);

        $results = $this->race(fn (int $i) => $this->service()->correct(
            ProjectLabourActual::findOrFail($actual->id), User::findOrFail($this->financier->id),
            ['actual_quantity' => 5 + $i], "Racer {$i}",
        ));

        $this->assertSame(1, count(array_keys($results, 'ok', true)), json_encode($results));
        $this->assertSame(1, ProjectLabourActual::where('reversal_of_id', $actual->id)->count());
        $this->assertSame('20000.00', $this->verifiedLabour($enquiry)); // opening a correction moves nothing
    }

    public function test_simultaneous_verification_of_a_correction_posts_one_pair(): void
    {
        [$enquiry] = $this->project();
        $actual = $this->verified($enquiry, 10);
        $successor = $this->service()->correct($actual, $this->financier, ['actual_quantity' => 8], 'Fix.');
        $successor = $this->service()->poVerify($successor, $this->projectOfficer);

        $results = $this->race(fn () => $this->service()->financeVerify(ProjectLabourActual::findOrFail($successor->id), User::findOrFail($this->financier->id)));

        $this->assertSame(array_fill(0, self::RACERS, 'ok'), $results);
        $this->assertSame(1, CostLineTransfer::where('source_cost_line_id', $actual->cost_line_id)->count());
        $this->assertSame(ProjectLabourActual::STATUS_SUPERSEDED, $actual->fresh()->status);
        $this->assertSame('16000.00', $this->verifiedLabour($enquiry));
    }

    public function test_simultaneous_rate_resolutions_resolve_once(): void
    {
        [$enquiry] = $this->project();
        $actual = $this->service()->record([
            'project_enquiry_id' => $enquiry->id, 'labour_role' => 'Rigger', 'labour_category' => 'site_labour',
            'budget_unit' => 'PAX', 'actual_quantity' => 2, 'actual_days' => 1, 'work_date' => now()->toDateString(),
            'is_unbudgeted' => true, 'unbudgeted_reason' => 'Extra rigging', 'rework_type' => 'none',
        ], $this->recorder);
        $actual = $this->service()->poVerify($actual, $this->projectOfficer);

        $results = $this->race(fn (int $i) => $this->service()->resolveUnbudgetedRate(
            ProjectLabourActual::findOrFail($actual->id), User::findOrFail($this->financier->id),
            (string) (1000 + $i * 100), "Memo {$i}", "REF-{$i}",
        ));

        $this->assertSame(1, count(array_keys($results, 'ok', true)), json_encode($results));
        $this->assertSame(1, DB::table('governance_audit_logs')->where('model_id', $actual->id)
            ->where('model_type', ProjectLabourActual::class)->where('action_status', 'resolve_unbudgeted_rate')->count());
        $fresh = $actual->fresh();
        $this->assertSame(bcmul('2', (string) $fresh->unit_rate, 2), (string) $fresh->calculated_cost);
        $this->assertSame((string) $fresh->unit_rate, $fresh->rate_source['rate']);
    }

    public function test_simultaneous_w6_transfers_move_a_cost_once(): void
    {
        [$a] = $this->project();
        [$b] = $this->project();
        $actual = $this->verified($a, 10);

        $results = $this->race(fn () => app(CostTransferService::class)->transfer(
            CostLine::findOrFail($actual->cost_line_id), ProjectEnquiry::findOrFail($b->id), $this->financier->id, 'Race',
        ));

        $this->assertSame(1, count(array_keys($results, 'ok', true)), json_encode($results));
        $this->assertSame(1, CostLineTransfer::where('source_cost_line_id', $actual->cost_line_id)->count());
        $this->assertSame('0.00', $this->verifiedLabour($a));
        $this->assertSame('20000.00', $this->verifiedLabour($b));
    }

    // ── Race harness ──────────────────────────────────────────────────────────

    /**
     * Run $work in RACERS forked processes released together. Each child gets its
     * own connection. Returns 'ok' or the exception class per racer.
     *
     * @return array<int, string>
     */
    private function race(callable $work): array
    {
        $barrier = $this->dir . '/go';
        DB::disconnect();
        $pids = [];

        for ($i = 0; $i < self::RACERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                $result = 'ok';
                try {
                    DB::purge();
                    DB::reconnect();
                    while (!file_exists($barrier)) {
                        usleep(200);
                    }
                    $work($i);
                } catch (\Throwable $e) {
                    $result = get_class($e) . ': ' . $e->getMessage();
                }
                file_put_contents("{$this->dir}/result-{$i}", $result);
                // Leave without running PHPUnit's shutdown in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        usleep(300000); // let every child connect and reach the barrier
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        unlink($barrier);
        DB::reconnect();

        $results = [];
        for ($i = 0; $i < self::RACERS; $i++) {
            $raw = @file_get_contents("{$this->dir}/result-{$i}");
            $results[] = $raw === false ? 'missing' : ($raw === 'ok' ? 'ok' : $raw);
        }

        foreach ($results as $r) {
            $this->assertNotSame('missing', $r, 'A racer died without reporting.');
            $this->assertStringNotContainsString('Deadlock', $r, 'Deadlock surfaced to a caller.');
        }

        return $results;
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function service(): ProjectLabourActualService
    {
        return app(ProjectLabourActualService::class);
    }

    /** @return array{0: ProjectEnquiry} */
    private function project(): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Race Client', 'email' => uniqid() . '@race.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company',
            'lead_source' => 'test', 'preferred_contact' => 'email', 'registration_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $enquiry = ProjectEnquiry::findOrFail(DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId, 'title' => 'Race',
            'contact_person' => 'Race', 'enquiry_number' => 'ENQ-RACE-' . uniqid(), 'job_number' => 'WNG-RACE-' . uniqid(),
            'status' => 'in_progress', 'financial_closure_status' => 'open',
            'project_officer_id' => $this->projectOfficer->id, 'assigned_po' => $this->projectOfficer->id,
            'assigned_users' => json_encode([$this->recorder->id]),
            'created_by' => $this->financier->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $task = EnquiryTask::create(['project_enquiry_id' => $enquiry->id, 'title' => 'Budget', 'type' => 'budget', 'status' => 'completed', 'created_by' => $this->financier->id]);
        $budget = TaskBudgetData::create([
            'enquiry_task_id' => $task->id, 'project_info' => [], 'materials_data' => [],
            'labour_data' => [[
                'id' => 'race-line', 'type' => 'Crew', 'category' => 'site_labour', 'description' => 'Crew',
                'unit' => 'PAX', 'quantity' => 10, 'days' => 1, 'unitRate' => 2000, 'amount' => 20000, 'isIncluded' => true,
            ]],
            'expenses_data' => [], 'logistics_data' => [], 'budget_summary' => [], 'status' => 'draft',
        ]);
        app(BudgetProjector::class)->project($budget);

        return [$enquiry];
    }

    private function poVerified(ProjectEnquiry $enquiry, int $quantity): ProjectLabourActual
    {
        $actual = $this->service()->record([
            'project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'race-line', 'actual_quantity' => $quantity,
            'actual_days' => 1, 'work_date' => now()->toDateString(), 'is_unbudgeted' => false, 'rework_type' => 'none',
        ], $this->recorder);

        return $this->service()->poVerify($actual, $this->projectOfficer);
    }

    private function verified(ProjectEnquiry $enquiry, int $quantity): ProjectLabourActual
    {
        return $this->service()->financeVerify($this->poVerified($enquiry, $quantity), $this->financier);
    }

    private function verifiedLabour(ProjectEnquiry $enquiry): string
    {
        return bcadd((string) CostLine::where('project_enquiry_id', $enquiry->id)->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)->where('details->budget_category', 'labour')
            ->sum('net_amount'), '0', 2);
    }

    // ── Committed-data cleanup ────────────────────────────────────────────────

    private function takeSnapshot(): void
    {
        $db = DB::connection()->getDatabaseName();
        $tables = collect(DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
            [$db],
        ))->pluck('t');

        foreach ($tables as $table) {
            $this->snapshot[$table] = (int) DB::table($table)->max('id');
        }
    }

    private function restoreSnapshot(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->snapshot as $table => $maxId) {
                DB::table($table)->where('id', '>', $maxId)->delete();
            }
            foreach (['model_has_roles', 'role_has_permissions'] as $pivot) {
                DB::table($pivot)->where('role_id', '>', $this->snapshot['roles'] ?? PHP_INT_MAX)->delete();
            }
            DB::table('model_has_roles')->where('model_id', '>', $this->snapshot['users'] ?? PHP_INT_MAX)->delete();
            DB::table('model_has_permissions')->where('model_id', '>', $this->snapshot['users'] ?? PHP_INT_MAX)->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
