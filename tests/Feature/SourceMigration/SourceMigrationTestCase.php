<?php

namespace Tests\Feature\SourceMigration;

use App\Modules\HR\Models\LedgerEntry;
use App\Support\SourceMigration\PlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Throwable;

/**
 * Two-database fixture for the source-to-target migration tooling.
 *
 *   target  — the test database, built by the full migration chain (RefreshDatabase).
 *   staging — a separate scratch database (db_srcmig_staging_test) standing in for the
 *             Stage 1 staging COPY. Its tables are created from the target's own
 *             SHOW CREATE TABLE, so the two schemas are genuinely identical, and its
 *             ledger records every migration (Stage 1 complete).
 *
 * The staging database must exist once, created as root like db_test:
 *   ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS db_srcmig_staging_test;
 *     GRANT ALL ON db_srcmig_staging_test.* TO 'db'@'%';"
 */
abstract class SourceMigrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected const STAGING = 'source_staging';

    /** Tables the fixture creates on staging (parents of every NOT NULL FK used included). */
    protected const FIXTURE_TABLES = [
        'migrations', 'clients', 'departments', 'employees', 'users', 'roles', 'model_has_roles', 'permissions',
        'model_has_permissions', 'role_has_permissions', 'project_enquiries', 'projects', 'enquiry_tasks', 'task_budget_data',
        'task_quote_data', 'quote_approvals', 'team_categories', 'team_types', 'teams_tasks', 'teams_members', 'technical_labours',
        'project_deliverables', 'task_materials_data', 'project_elements', 'element_materials', 'leave_types', 'ledger_entries',
        'employee_salary_histories', 'employee_documents', 'enquiry_payments', 'sessions', 'suppliers', 'purchase_orders',
        'chart_of_accounts', 'assets', 'asset_service_logs',
    ];

    /** Row ids the autofill uses for required FK columns. */
    protected array $parentDefaults = [
        'users' => 5, 'employees' => 7, 'departments' => 3, 'clients' => 11, 'project_enquiries' => 101, 'enquiry_tasks' => 201,
        'projects' => 301, 'suppliers' => 1, 'team_categories' => 1, 'team_types' => 1, 'task_materials_data' => 1001,
        'project_elements' => 1101, 'teams_tasks' => 601, 'technical_labours' => 801, 'roles' => 1, 'task_budget_data' => 401,
    ];

    protected string $planPath;

    protected string $reportDir;

    protected string $knownPermission;

    private static bool $stagingBuilt = false;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::STAGING => array_merge(config('database.connections.mysql'), [
            'database' => env('SOURCE_MIGRATION_TEST_STAGING_DB', 'db_srcmig_staging_test'),
        ])]);
        DB::purge(self::STAGING);

        try {
            DB::connection(self::STAGING)->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Staging scratch database unavailable ('.$e->getMessage().'). Create it: see '.self::class);
        }

        config(['source_migration.live_source_databases' => ['woodnork_erpsystem']]);
        config(['source_migration.live_target_databases' => ['woodnork_erp']]);

        $this->reportDir = sys_get_temp_dir().'/srcmig-reports-'.getmypid();
        config(['source_migration.report_path' => $this->reportDir]);

        $this->buildStagingSchema();
        $this->resetStaging();
        $this->knownPermission = (string) DB::table('permissions')->orderBy('id')->value('name');
        $this->seedStaging();
        $this->planPath = $this->writePlan();
    }

    protected function tearDown(): void
    {
        DB::purge(self::STAGING); // drops the read-only session the guard set
        parent::tearDown();
    }

    protected function staging(): \Illuminate\Database\Connection
    {
        return DB::connection(self::STAGING);
    }

    protected function targetDatabase(): string
    {
        return (string) DB::selectOne('SELECT DATABASE() AS d')->d;
    }

    /** Recreate the fixture tables once per process from the target's own DDL. */
    protected function buildStagingSchema(bool $force = false): void
    {
        if (self::$stagingBuilt && ! $force) {
            return;
        }
        // A fresh session: the command's guard leaves this one READ ONLY (as it must).
        DB::purge(self::STAGING);
        self::$stagingBuilt = false;
        $staging = $this->staging();
        $staging->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($staging->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $staging->statement('DROP TABLE IF EXISTS `'.array_values((array) $row)[0].'`');
        }
        foreach (self::FIXTURE_TABLES as $table) {
            $ddl = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $staging->statement(array_values($ddl)[1]);
        }
        $staging->statement('SET FOREIGN_KEY_CHECKS = 1');
        self::$stagingBuilt = true;
    }

    protected function resetStaging(): void
    {
        DB::purge(self::STAGING);
        $staging = $this->staging();
        $staging->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::FIXTURE_TABLES as $table) {
            $staging->table($table)->delete();
        }
        $staging->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function writePlan(): string
    {
        $tables = collect($this->staging()->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($r) => (string) array_values((array) $r)[0])->sort()->values()->all();
        $path = sys_get_temp_dir().'/srcmig-plan-'.getmypid().'.json';
        file_put_contents($path, json_encode((new PlanGenerator)->generate($tables)->toArray()));

        return $path;
    }

    /** Insert into staging, filling every NOT NULL column without a default from the real schema. */
    protected function stage(string $table, array $values): void
    {
        $required = DB::select(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO'
               AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
            [$table],
        );
        $parents = collect(DB::select(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table],
        ))->pluck('REFERENCED_TABLE_NAME', 'COLUMN_NAME')->all();

        foreach ($required as $column) {
            $name = $column->COLUMN_NAME;
            if (array_key_exists($name, $values)) {
                continue;
            }
            $this->sequence++;
            $values[$name] = match (true) {
                isset($parents[$name]) => $this->parentDefaults[$parents[$name]] ?? 1,
                str_contains($column->DATA_TYPE, 'int') => 1,
                in_array($column->DATA_TYPE, ['decimal', 'float', 'double'], true) => 0,
                $column->DATA_TYPE === 'enum' => trim(explode(',', substr($column->COLUMN_TYPE, 5, -1))[0], "'"),
                $column->DATA_TYPE === 'date' => '2026-01-01',
                in_array($column->DATA_TYPE, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
                $column->DATA_TYPE === 'time' => '00:00:00',
                $column->DATA_TYPE === 'year' => 2026,
                in_array($column->DATA_TYPE, ['longtext', 'json'], true) => '[]',
                default => "f{$this->sequence}",
            };
        }

        $this->staging()->table($table)->insert($values);
    }

    /**
     * A small, realistic source: gapped IDs, the employee/department/user cycle,
     * two projects (one open, one completed), budgets with labour lines, roles that
     * exercise every D4 class, a D2 purchase order, excluded Finance rows, transient
     * rows, and an overtime hash chain.
     */
    protected function seedStaging(): void
    {
        $s = $this->staging();
        $s->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (DB::table('migrations')->orderBy('id')->get() as $m) {
            $s->table('migrations')->insert(['migration' => $m->migration, 'batch' => $m->batch]);
        }

        $this->stage('departments', ['id' => 3, 'name' => 'Production', 'manager_id' => 7]);
        $this->stage('departments', ['id' => 8, 'name' => 'Finance', 'manager_id' => null]);
        $this->stage('employees', ['id' => 7, 'first_name' => 'Asha', 'last_name' => 'K', 'email' => 'asha@example.test', 'department_id' => 3, 'salary' => '98765.43', 'status' => 'active']);
        $this->stage('employees', ['id' => 12, 'first_name' => 'Ben', 'last_name' => 'O', 'email' => 'ben@example.test', 'department_id' => 8, 'manager_id' => 7, 'salary' => '41234.56', 'status' => 'active']);
        $this->stage('employees', ['id' => 900, 'first_name' => 'Cleo', 'last_name' => 'M', 'email' => 'cleo@example.test', 'department_id' => 3, 'status' => 'active']);
        foreach ([[5, 7], [42, 12], [77, null]] as [$id, $employee]) {
            $this->stage('users', ['id' => $id, 'name' => "User {$id}", 'email' => "user{$id}@example.test", 'employee_id' => $employee,
                'password' => Hash::make("secret-{$id}")]);
        }
        $this->stage('employee_salary_histories', ['id' => 1, 'employee_id' => 7, 'salary' => '55555.55', 'valid_from' => '2026-01-01', 'created_by' => 5]);
        $this->stage('employee_documents', ['id' => 1, 'employee_id' => 7, 'file_path' => 'employee-documents/cv.pdf']);
        $this->stage('clients', ['id' => 11]);
        $this->stage('suppliers', ['id' => 1]);

        $this->stage('project_enquiries', ['id' => 101, 'client_id' => 11, 'project_officer_id' => 5, 'created_by' => 5, 'status' => 'in_progress']);
        $this->stage('project_enquiries', ['id' => 102, 'client_id' => 11, 'project_officer_id' => 42, 'created_by' => 5, 'status' => 'completed']);
        $this->stage('projects', ['id' => 301, 'enquiry_id' => 101, 'status' => 'in_progress']);
        $this->stage('projects', ['id' => 302, 'enquiry_id' => 102, 'status' => 'completed']);
        $this->stage('enquiry_tasks', ['id' => 201, 'project_enquiry_id' => 101, 'type' => 'budget', 'status' => 'completed', 'department_id' => 3, 'created_by' => 5]);
        $this->stage('enquiry_tasks', ['id' => 202, 'project_enquiry_id' => 102, 'type' => 'budget', 'status' => 'completed', 'department_id' => 3, 'created_by' => 5]);
        $this->stage('enquiry_tasks', ['id' => 203, 'project_enquiry_id' => 101, 'type' => 'quote', 'status' => 'completed', 'department_id' => 3, 'created_by' => 5]);
        $labour = json_encode([
            ['id' => 'lab-1', 'type' => 'Carpenter', 'category' => 'workshop', 'unit' => 'PAX', 'quantity' => 2, 'days' => 3, 'unitRate' => 1500, 'amount' => 9000],
            ['id' => 'lab-2', 'type' => 'Painter', 'category' => 'workshop', 'unit' => 'PAX', 'quantity' => 1, 'days' => 2, 'unitRate' => 1200, 'amount' => 2400],
        ]);
        foreach ([[401, 201], [402, 202]] as [$id, $task]) {
            $this->stage('task_budget_data', ['id' => $id, 'enquiry_task_id' => $task, 'project_info' => '{}', 'materials_data' => '[]',
                'labour_data' => $labour, 'expenses_data' => '[]', 'logistics_data' => '[]', 'budget_summary' => '{}', 'status' => 'draft']);
        }
        $this->stage('task_quote_data', ['id' => 501, 'enquiry_task_id' => 203]);
        $this->stage('quote_approvals', ['id' => 1, 'task_id' => 203, 'enquiry_id' => 101]);
        $this->stage('team_categories', ['id' => 1]);
        $this->stage('team_types', ['id' => 1]);
        $this->stage('teams_tasks', ['id' => 601, 'task_id' => 201, 'project_id' => 301, 'category_id' => 1, 'team_type_id' => 1]);
        $this->stage('technical_labours', ['id' => 801, 'employee_id' => 7]);
        $this->stage('teams_members', ['id' => 701, 'teams_task_id' => 601, 'technical_labour_id' => 801]);
        $this->stage('project_deliverables', ['id' => 901, 'enquiry_id' => 101]);
        $this->stage('task_materials_data', ['id' => 1001, 'enquiry_task_id' => 201]);
        $this->stage('project_elements', ['id' => 1101, 'task_materials_data_id' => 1001]);
        $this->stage('element_materials', ['id' => 1201, 'project_element_id' => 1101, 'library_material_id' => null]);

        // D4: one exact, one renamed equivalent, one code-referenced custom role, one unused.
        $this->stage('roles', ['id' => 1, 'name' => 'Admin', 'guard_name' => 'web']);
        $this->stage('roles', ['id' => 2, 'name' => 'Procurement Officer', 'guard_name' => 'web']);
        $this->stage('roles', ['id' => 3, 'name' => 'Legacy Role', 'guard_name' => 'web']);
        $this->stage('roles', ['id' => 4, 'name' => 'project manager', 'guard_name' => 'web']);
        foreach ([[1, 5], [2, 42], [4, 77]] as [$role, $user]) {
            $s->table('model_has_roles')->insert(['role_id' => $role, 'model_type' => 'App\\Models\\User', 'model_id' => $user]);
        }
        $s->table('permissions')->insert([
            ['id' => 900, 'name' => $this->knownPermission, 'guard_name' => 'web'],
            ['id' => 901, 'name' => 'finance.obsolete_legacy_permission', 'guard_name' => 'web'],
        ]);
        $s->table('role_has_permissions')->insert(['permission_id' => 900, 'role_id' => 1]);
        $s->table('model_has_permissions')->insert([
            ['permission_id' => 900, 'model_type' => 'App\\Models\\User', 'model_id' => 5],
            ['permission_id' => 901, 'model_type' => 'App\\Models\\User', 'model_id' => 5],
        ]);

        // Seeded master: staging = every target seed (by code, other ids) + one of its own.
        foreach (DB::table('leave_types')->orderBy('id')->get() as $row) {
            $s->table('leave_types')->insert(array_merge((array) $row, ['id' => $row->id + 100]));
        }
        $this->stage('leave_types', ['id' => 500, 'name' => 'Study Leave', 'code' => 'STUDY']);

        // Overtime chain for employee 7, hashed exactly as OvertimeService does.
        $previous = null;
        foreach ([[1, '2026-03-01 10:00:00', '4.00', '4.00'], [2, '2026-03-05 10:00:00', '2.00', '6.00']] as [$id, $at, $hours, $balance]) {
            $entry = new LedgerEntry(['employee_id' => 7, 'kind' => 'credit', 'hours' => $hours, 'balance_after' => $balance, 'occurred_at' => Carbon::parse($at)]);
            $hash = LedgerEntry::generateHash($entry, $previous);
            $this->stage('ledger_entries', ['id' => $id, 'employee_id' => 7, 'kind' => 'credit', 'hours' => $hours, 'balance_after' => $balance,
                'occurred_at' => $at, 'chain_hash' => $hash]);
            $previous = $hash;
        }

        // Excluded Finance history, transient state, D2 and D3 rows.
        $this->stage('enquiry_payments', ['id' => 1, 'project_enquiry_id' => 101, 'recorded_by' => 5]);
        $s->table('sessions')->insert(['id' => 'sess-1', 'user_id' => 5, 'payload' => 'x', 'last_activity' => 1]);
        $this->stage('purchase_orders', ['id' => 1, 'po_number' => 'PO-TEST-0001', 'supplier_id' => 1, 'status' => 'approved', 'total_amount' => '0.00']);
        $this->stage('chart_of_accounts', ['id' => 9001, 'name' => 'Source Chart Account', 'code' => 'SRC-1']);

        $this->stage('assets', ['id' => 1]);

        $s->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function execute(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('migration:import-source', array_merge([
            '--plan' => $this->planPath, '--execute' => true, '--confirm' => $this->targetDatabase(), '--report-dir' => $this->reportDir,
        ], $options));
    }

    protected function latestReport(string $name): array
    {
        $files = glob("{$this->reportDir}/*/{$name}.json");
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));

        return json_decode((string) file_get_contents(end($files)), true);
    }
}
