<?php

namespace Tests\Feature\SourceMigration;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Support\FinanceResetBoundary;
use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\EvidenceReports;
use App\Support\SourceMigration\FileReferenceVerifier;
use App\Support\SourceMigration\ImportOrder;
use App\Support\SourceMigration\MigrationPlan;
use App\Support\SourceMigration\OvertimeChainVerifier;
use App\Support\SourceMigration\PlanGenerator;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Report 51: the source-to-target migration tooling, end to end on scratch databases.
 */
class SourceMigrationToolingTest extends SourceMigrationTestCase
{
    // ── Safety guards ────────────────────────────────────────────────────

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $before = ['users' => DB::table('users')->count(), 'projects' => DB::table('projects')->count(), 'leave_types' => DB::table('leave_types')->count()];

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame($before, ['users' => DB::table('users')->count(), 'projects' => DB::table('projects')->count(), 'leave_types' => DB::table('leave_types')->count()]);
        $this->assertContains('users', $this->latestReport('dry_run')['would_load']);
    }

    public function test_an_unconfigured_source_connection_is_refused(): void
    {
        config(['database.connections.source_staging.database' => null]);

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('has no database configured')
            ->assertFailed();
    }

    public function test_the_same_database_as_source_and_target_is_refused(): void
    {
        config(['database.connections.source_staging.database' => $this->targetDatabase()]);
        DB::purge('source_staging');

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('same database')
            ->assertFailed();
    }

    public function test_the_live_source_database_is_refused_by_name(): void
    {
        config(['source_migration.live_source_databases' => [DB::connection('source_staging')->getDatabaseName()]]);

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('LIVE source ERP')
            ->assertFailed();
    }

    public function test_the_source_session_is_read_only_once_guarded(): void
    {
        $guard = ConnectionGuard::make();
        $guard->assertDistinctDatabases();
        $guard->makeSourceReadOnly();

        $this->expectException(QueryException::class);
        $this->staging()->table('clients')->insert(['id' => 999]);
    }

    public function test_execution_requires_the_typed_target_name_and_cutover_for_a_live_target(): void
    {
        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--execute' => true, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('requires --confirm=')->assertFailed();
        $this->execute(['--confirm' => 'some_other_db'])->expectsOutputToContain('requires --confirm=')->assertFailed();

        config(['source_migration.live_target_databases' => [$this->targetDatabase()]]);
        $this->execute()->expectsOutputToContain('LIVE target')->assertFailed();

        $this->assertSame(0, DB::table('users')->count(), 'nothing may load after a refusal');
    }

    public function test_an_invalid_plan_is_refused(): void
    {
        $plan = json_decode((string) file_get_contents($this->planPath), true);
        $plan['tables']['enquiry_payments']['mode'] = 'import';   // DATA-1 Finance history
        $plan['tables']['clients']['mode'] = 'copy-everything';    // not a mode
        file_put_contents($this->planPath, json_encode($plan));

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain("'enquiry_payments' is DATA-1 Finance history")
            ->expectsOutputToContain("invalid mode 'copy-everything'")
            ->assertFailed();
    }

    public function test_schema_drift_is_refused_and_nothing_is_altered(): void
    {
        $this->staging()->statement('ALTER TABLE clients ADD COLUMN legacy_note VARCHAR(20) NULL');
        try {
            $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
                ->expectsOutputToContain('Schema drift: clients.legacy_note exists on staging but not on the target')
                ->assertFailed();
            $this->assertArrayNotHasKey('legacy_note', (new SchemaInspector('mysql'))->columns('clients'));
        } finally {
            $this->buildStagingSchema(force: true);
        }
    }

    public function test_column_narrowing_needs_data_proof_and_explicit_acceptance(): void
    {
        // The drift the local dress rehearsal found on real data: varchar(255) staging → varchar(191) target.
        $this->staging()->statement('ALTER TABLE clients MODIFY full_name VARCHAR(255) NOT NULL');
        try {
            $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
                ->expectsOutputToContain('narrows; every existing value fits')
                ->assertFailed();
            $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir, '--accept-verified-narrowing' => true])
                ->expectsOutputToContain('Schema gate: PASS (1 classified drift(s) accepted')
                ->assertSuccessful();
            $this->assertSame('narrowing', $this->latestReport('schema_gate')['drift'][0]['category']);

            // A value that would not fit is refused even with the flag — nothing is truncated.
            DB::purge('source_staging');
            $this->staging()->table('clients')->where('id', 11)->update(['full_name' => str_repeat('x', 200)]);
            $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir, '--accept-verified-narrowing' => true])
                ->expectsOutputToContain('existing data does NOT fit')
                ->assertFailed();
        } finally {
            $this->buildStagingSchema(force: true);
        }

        $conn = DB::connection();
        $this->assertSame('char(36)', \App\Support\SourceMigration\ColumnDrift::normalise('uuid'));
        $this->assertSame('bigint unsigned', \App\Support\SourceMigration\ColumnDrift::normalise('bigint(20) unsigned'));
        $classify = fn ($s, $t) => \App\Support\SourceMigration\ColumnDrift::classify($conn, 'clients', 'id', ['type' => $s, 'nullable' => false], ['type' => $t, 'nullable' => false])['category'];
        $this->assertSame('equivalent', $classify('uuid', 'char(36)'));
        $this->assertSame('widening', $classify("enum('a','b')", "enum('a','b','c')"));
        $this->assertSame('widening', $classify('varchar(100)', 'varchar(191)'));
        $this->assertSame('widening', $classify('int(11)', 'bigint(20)'));
        $this->assertSame('incompatible', $classify('bigint(20)', 'int(11)'));
        $this->assertSame('incompatible', $classify('date', 'varchar(20)'));
    }

    public function test_an_incomplete_stage_1_is_refused(): void
    {
        $this->staging()->table('migrations')->where('migration', 'like', '2026_09_28%')->delete();

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])
            ->expectsOutputToContain('Stage 1 incomplete')
            ->assertFailed();
    }

    // ── Load: IDs, order, seeded tables, exclusions ───────────────────────

    public function test_execute_loads_with_original_ids_and_reconciles(): void
    {
        $this->execute()->expectsOutputToContain('VALIDATION: PASS')->assertSuccessful();

        $this->assertSame([5, 42, 77], DB::table('users')->orderBy('id')->pluck('id')->all());
        $this->assertSame([7, 12, 900], DB::table('employees')->orderBy('id')->pluck('id')->all());
        $this->assertSame([101, 102], DB::table('project_enquiries')->orderBy('id')->pluck('id')->all());
        $this->assertSame(7, (int) DB::table('users')->where('id', 5)->value('employee_id'));
        $this->assertSame(3, (int) DB::table('departments')->where('id', 3)->value('id'));

        // The next id continues after the highest preserved id — never reuses one.
        $next = DB::table('clients')->insertGetId(['created_at' => now(), 'updated_at' => now()] + $this->requiredDefaults('clients'));
        $this->assertGreaterThan(11, $next);

        $validation = $this->latestReport('validation');
        $this->assertTrue($validation['passes']);
        $this->assertTrue($validation['reconciliation']['users']['checksum_match']);
        $this->assertSame(['5', '77'], $validation['reconciliation']['users']['target_key_range']);
    }

    public function test_excluded_transient_and_pending_tables_are_not_loaded(): void
    {
        $chartBefore = DB::table('chart_of_accounts')->count();
        $this->execute()->assertSuccessful();

        $this->assertSame(0, DB::table('enquiry_payments')->count(), 'DATA-1 Q2 excluded');
        $this->assertSame(0, DB::table('sessions')->count(), 'transient');
        $this->assertSame(0, DB::table('purchase_orders')->count(), 'D2 pending');
        $this->assertSame($chartBefore, DB::table('chart_of_accounts')->count(), 'D3 pending: target chart untouched');
        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'SRC-1')->exists());
        $this->assertFalse(DB::table('permissions')->where('name', 'finance.obsolete_legacy_permission')->exists(), 'permissions are regenerated, not imported');

        $exclusions = EvidenceReports::exclusions(new SchemaInspector('source_staging'), new SchemaInspector('mysql'), MigrationPlan::load($this->planPath));
        $this->assertTrue($exclusions['passes']);
        $this->assertTrue($exclusions['data1_exclusions']['enquiry_payments (client receipts)']['excluded']);
        $this->assertSame(1, $exclusions['tables']['enquiry_payments']['staging_rows']);
    }

    public function test_the_import_order_is_dependency_aware_and_reports_cycles(): void
    {
        $plan = MigrationPlan::load($this->planPath);
        $order = ImportOrder::compute($plan->tablesIn(...MigrationPlan::LOAD_MODES), (new SchemaInspector('mysql'))->foreignKeys());
        $at = array_flip($order->order);

        $this->assertLessThan($at['projects'], $at['project_enquiries']);
        $this->assertLessThan($at['task_budget_data'], $at['enquiry_tasks']);
        $this->assertLessThan($at['element_materials'], $at['project_elements']);
        $this->assertLessThan($at['teams_members'], $at['teams_tasks']);
        $cycle = collect($order->cycles)->first(fn ($c) => in_array('employees', $c, true));
        $this->assertNotNull($cycle, 'employees ↔ departments must be reported as a cycle');
        $this->assertContains('departments', $cycle);
        $this->assertSame($order->order, ImportOrder::compute(array_reverse($plan->tablesIn(...MigrationPlan::LOAD_MODES)), (new SchemaInspector('mysql'))->foreignKeys())->order, 'deterministic');
    }

    public function test_seeded_tables_are_replaced_by_staging_without_duplicate_ids(): void
    {
        $seededCodes = DB::table('leave_types')->pluck('code')->all();
        $this->execute()->assertSuccessful();

        $this->assertSame(
            $this->staging()->table('leave_types')->orderBy('id')->pluck('id')->all(),
            DB::table('leave_types')->orderBy('id')->pluck('id')->all(),
        );
        foreach ($seededCodes as $code) {
            $this->assertSame(1, DB::table('leave_types')->where('code', $code)->count(), "{$code} exactly once");
        }
        $this->assertTrue(DB::table('leave_types')->where('code', 'STUDY')->exists());
    }

    public function test_a_seeded_table_holding_rows_staging_lacks_is_refused(): void
    {
        DB::table('leave_types')->insert(['name' => 'Target-only', 'code' => 'ZZZ'] + $this->requiredDefaults('leave_types'));

        $this->execute()->expectsOutputToContain("Target table 'leave_types' holds 1 row(s) not present on staging")->assertFailed();
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_an_import_table_must_be_empty_on_the_target(): void
    {
        DB::table('clients')->insert(['id' => 77777] + $this->requiredDefaults('clients'));

        $this->execute()->expectsOutputToContain("Target table 'clients' already has 1 row(s)")->assertFailed();
    }

    public function test_resume_skips_loaded_tables_and_a_plain_rerun_is_refused(): void
    {
        $this->execute()->assertSuccessful();
        $this->execute()->expectsOutputToContain('already has')->assertFailed();
        $this->execute(['--resume' => true])->expectsOutputToContain('VALIDATION: PASS')->assertSuccessful();
        $this->assertSame([5, 42, 77], DB::table('users')->orderBy('id')->pluck('id')->all());
    }

    // ── Orphans ──────────────────────────────────────────────────────────

    public function test_orphans_are_reported_block_execution_and_are_never_dropped(): void
    {
        $this->staging()->table('quote_approvals')->insert(['id' => 2, 'task_id' => 99999, 'enquiry_id' => 101] + $this->requiredDefaults('quote_approvals', ['task_id', 'enquiry_id']));

        $this->artisan('migration:import-source', ['--plan' => $this->planPath, '--report-dir' => $this->reportDir])->assertSuccessful();
        $finding = collect($this->latestReport('orphan_scan_staging')['blocking'])->firstWhere('column', 'task_id');
        $this->assertSame('quote_approvals', $finding['table']);
        $this->assertSame(1, $finding['orphans']);
        $this->assertSame(['2'], $finding['sample_child_ids']);
        $this->assertSame(['99999'], $finding['sample_missing_parent_ids']);
        $this->assertFalse($finding['enforced_by_fk']);
        $this->assertSame('HIGH', $finding['severity']);

        $this->execute()->expectsOutputToContain('Orphans found in loaded tables')->assertFailed();

        // Loaded as-is only when explicitly allow-listed — and still present, not dropped.
        $this->execute(['--allow-orphans' => ['quote_approvals.task_id']])->assertSuccessful();
        $this->assertTrue(DB::table('quote_approvals')->where('id', 2)->where('task_id', 99999)->exists());
    }

    // ── Security ─────────────────────────────────────────────────────────

    public function test_direct_permission_grants_are_rekeyed_by_name_and_unmapped_ones_reported(): void
    {
        $this->execute()->assertSuccessful();
        $this->assertSame(0, DB::table('model_has_permissions')->count(), 'mapped grants wait for regenerated permissions');

        $this->execute(['--only' => ['model_has_permissions']])->assertSuccessful();

        $targetId = DB::table('permissions')->where('name', $this->knownPermission)->value('id');
        $this->assertSame([(int) $targetId], DB::table('model_has_permissions')->where('model_id', 5)->pluck('permission_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['finance.obsolete_legacy_permission' => 1], $this->latestReport('load')['completed']['model_has_permissions']['unmapped']);
    }

    public function test_the_role_mapping_report_classifies_every_source_role_and_deletes_none(): void
    {
        $report = (new EvidenceReports(new SchemaInspector('source_staging')))->roles();
        $by = collect($report['roles'])->keyBy('name');

        $this->assertSame('source_role_mapping_report', $report['report']);
        $this->assertSame('exact-target-equivalent', $by['Admin']['classification']);
        $this->assertSame('renamed-target-equivalent', $by['project manager']['classification']);
        $this->assertSame('Project Manager', $by['project manager']['proposed_mapping']);
        $this->assertSame('requires-wng-mapping', $by['Procurement Officer']['classification']);
        $this->assertGreaterThan(0, $by['Procurement Officer']['code_references']);
        $this->assertSame('obsolete-retire-candidate', $by['Legacy Role']['classification']);
        $this->assertTrue($by['Legacy Role']['wng_decision_required']);

        $this->execute()->assertSuccessful();
        $this->assertSame(['Admin', 'Procurement Officer', 'Legacy Role', 'project manager'], DB::table('roles')->orderBy('id')->pluck('name')->all(), 'every source role survives');
        $this->assertTrue(DB::table('model_has_roles')->where('role_id', 2)->where('model_id', 42)->exists());
    }

    public function test_authentication_survives_the_load_without_exposing_hashes(): void
    {
        $this->execute()->assertSuccessful();
        $auth = EvidenceReports::authentication(new SchemaInspector('source_staging'), new SchemaInspector('mysql'));

        $this->assertTrue($auth['password_hashes_unchanged']);
        $this->assertTrue($auth['employee_user_links_identical']);
        $this->assertTrue($auth['role_assignments']['identical']);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-42', DB::table('users')->where('id', 42)->value('password')));

        $json = json_encode($this->latestReport('authentication'));
        foreach (DB::table('users')->pluck('password') as $hash) {
            $this->assertStringNotContainsString($hash, $json);
        }
    }

    // ── Preservation reports ─────────────────────────────────────────────

    public function test_project_and_employee_reports_flag_broken_relationships(): void
    {
        $projects = (new EvidenceReports(new SchemaInspector('source_staging')))->projects();
        $this->assertSame([], $projects['broken_relationships']);
        $this->assertEquals(['completed' => 1, 'in_progress' => 1], $projects['project_status']);
        $this->assertSame(2, $projects['coverage']['enquiries_with_budget_data']);

        $this->staging()->statement('SET FOREIGN_KEY_CHECKS = 0');
        $this->stage('projects', ['id' => 303, 'enquiry_id' => 99999, 'status' => 'planning']);
        $this->staging()->table('employees')->where('id', 900)->update(['department_id' => 4444]);
        $this->staging()->statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertSame(1, (new EvidenceReports(new SchemaInspector('source_staging')))->projects()['broken_relationships']['projects.enquiry_id → project_enquiries']);
        $this->assertSame(1, (new EvidenceReports(new SchemaInspector('source_staging')))->employees()['broken_relationships']['employees.department_id → departments']);
    }

    public function test_the_employee_report_never_contains_salary_values(): void
    {
        $this->artisan('migration:evidence', ['report' => 'employees', '--report-dir' => $this->reportDir])->assertSuccessful();
        $json = json_encode($this->latestReport('employees'));

        $this->assertStringNotContainsString('98765', $json);
        $this->assertStringNotContainsString('55555', $json);
        $this->assertSame(1, $this->latestReport('employees')['coverage']['employees_with_salary_history']);
    }

    public function test_d2_evidence_is_reported_and_d2_is_not_imported(): void
    {
        $this->artisan('migration:evidence', ['report' => 'd2', '--report-dir' => $this->reportDir])->assertSuccessful();
        $po = $this->latestReport('d2')['tables']['purchase_orders'];

        $this->assertSame(1, $po['rows']);
        $this->assertSame(['approved' => 1], $po['status']);
        $this->assertSame(1, $po['supplier_linkage']['with_supplier']);
        $this->assertSame(1, $po['test_keyword_hits']['rows']);
        $this->assertSame(1, $po['zero_or_negative_amounts']);
        $this->assertStringContainsString('D2 PENDING', $this->latestReport('d2')['decision']);

        $this->execute()->assertSuccessful();
        $this->assertSame(0, DB::table('purchase_orders')->count());
    }

    public function test_budget_authority_and_w7_labour_remain_compatible_after_the_load(): void
    {
        $this->execute()->assertSuccessful();
        $target = new EvidenceReports(new SchemaInspector('mysql'));

        $authority = $target->budgetAuthority();
        $this->assertSame(2, $authority['enquiries_with_finalized_budget']);
        $this->assertTrue($authority['class_agrees_with_sql']);
        $this->assertStringContainsString("'approved' is not a criterion", $authority['authority_rule']);

        $w7 = $target->w7();
        $this->assertSame(4, $w7['labour_allocation_lines']);
        $this->assertTrue($w7['w7_service_agrees'], 'W7 reads every imported labour line');
        $this->assertSame([101 => 0, 102 => 0], $w7['w7_recordable_lines_by_enquiry'], 'not recordable until planned CostLines are regenerated');

        // Regeneration (D5) makes the open project's labour recordable — and only it.
        $this->artisan('migration:regenerate', ['step' => 'planned-cost-lines', '--execute' => true, '--confirm' => $this->targetDatabase()])->assertSuccessful();
        $this->assertSame([101 => 2, 102 => 0], (new EvidenceReports(new SchemaInspector('mysql')))->w7()['w7_recordable_lines_by_enquiry']);
        $this->assertTrue($w7['labour_record_links_to_employee']);
        $this->assertFalse($w7['labour_record_has_technical_labour_link'], 'technical labour is not an active labour master');
        $this->assertSame(2, $w7['departments_labour_classification']['unclassified (NULL → indirect)'], 'D6: not guessed');
    }

    public function test_the_overtime_chain_verifies_and_a_migration_break_is_detected(): void
    {
        $this->execute()->assertSuccessful();
        $staging = (new OvertimeChainVerifier('source_staging'))->verify();
        $target = (new OvertimeChainVerifier('mysql'))->verify();
        $this->assertTrue($staging['passes']);
        $this->assertTrue($target['passes']);
        $this->assertSame(2, $target['entries']);

        DB::table('ledger_entries')->where('id', 2)->update(['hours' => '9.00']);
        $tampered = (new OvertimeChainVerifier('mysql'))->verify();
        $this->assertSame([2], OvertimeChainVerifier::introducedByMigration($staging, $tampered));
    }

    public function test_file_verification_reports_present_missing_and_absolute_references(): void
    {
        $storage = sys_get_temp_dir().'/srcmig-storage-'.getmypid();
        @mkdir("{$storage}/app/public/employee-documents", 0775, true);
        file_put_contents("{$storage}/app/public/employee-documents/cv.pdf", 'x');
        $this->stage('employee_documents', ['id' => 2, 'employee_id' => 7, 'file_path' => 'employee-documents/missing.pdf']);
        $this->stage('employee_documents', ['id' => 3, 'employee_id' => 7, 'file_path' => 'https://www.woodnorkgreen.co.ke/storage/x.pdf']);

        $result = (new FileReferenceVerifier(new SchemaInspector('source_staging')))->verify($storage);
        $docs = $result['by_column']['employee_documents.file_path'];

        $this->assertSame(1, $docs['present']);
        $this->assertSame(1, $docs['missing']);
        $this->assertSame(1, $docs['absolute_url']);
        $this->assertSame(['id' => '2', 'path' => 'employee-documents/missing.pdf'], $docs['missing_samples'][0]);
        $this->assertFalse($result['passes']);
        $this->assertSame(1, $result['by_module']['HR']['missing']);

        $this->artisan('migration:verify-files', ['--dry-run' => true, '--report-dir' => $this->reportDir])->expectsOutputToContain('DRY RUN')->assertSuccessful();
    }

    // ── D5 regeneration ──────────────────────────────────────────────────

    public function test_planned_cost_lines_are_regenerated_for_open_projects_only(): void
    {
        $this->execute()->assertSuccessful();

        $this->assertSame([401], EvidenceReports::eligibleBudgetIds(new SchemaInspector('mysql')), 'enquiry 102 / project 302 are completed');

        $this->artisan('migration:regenerate', ['step' => 'planned-cost-lines', '--execute' => true, '--confirm' => $this->targetDatabase()])
            ->expectsOutputToContain('1 eligible budget(s) of 2')
            ->assertSuccessful();
        $this->assertSame(0, CostLine::query()->where('project_enquiry_id', 102)->count(), 'no redesigned history for completed projects');
        $this->assertGreaterThan(0, CostLine::query()->where('project_enquiry_id', 101)->where('nature', CostLine::NATURE_PLANNED)->count());
    }

    public function test_regeneration_requires_confirmation_and_never_seeds_the_chart_while_d3_is_pending(): void
    {
        $this->artisan('migration:regenerate', ['step' => 'reference', '--execute' => true])->expectsOutputToContain('REFUSED')->assertFailed();

        config(['finance_accounts.seed_reference_chart' => true]);
        $before = DB::table('chart_of_accounts')->count();
        $this->artisan('migration:regenerate', ['step' => 'reference', '--execute' => true, '--confirm' => $this->targetDatabase()])->assertSuccessful();
        $this->assertSame($before, DB::table('chart_of_accounts')->count(), 'D3 pending: the reference chart is never seeded by the migration');
    }

    // ── Plan integrity ───────────────────────────────────────────────────

    public function test_the_generator_classifies_every_table_and_catches_unknown_seeded_tables(): void
    {
        $target = new SchemaInspector('mysql');
        $plan = (new PlanGenerator)->generate($target->tables());

        $this->assertSame([], $plan->problems());
        $this->assertEqualsCanonicalizing($target->tables(), array_keys($plan->tables));
        foreach (config('source_migration.d2_tables') as $table) {
            $this->assertSame(MigrationPlan::DECISION_PENDING, $plan->mode($table));
        }
        $this->assertSame(MigrationPlan::DECISION_PENDING, $plan->mode('chart_of_accounts'));
        foreach (FinanceResetBoundary::RESET_ORDER as $finance) {
            $this->assertNotContains($plan->mode($finance), MigrationPlan::LOAD_MODES, "{$finance} must not load");
        }

        // Every table the migration chain seeds must be classified as something other than
        // a plain `import` — or the load would refuse it as non-empty. This fails the day a
        // new migration seeds a table nobody classified.
        foreach ($target->tables() as $table) {
            if ($target->count($table) > 0 && $table !== 'migrations') {
                $this->assertNotSame(MigrationPlan::IMPORT, $plan->mode($table), "Migrations seed '{$table}': classify it (replace-seeded, skip, exclude…)");
            }
        }
    }

    public function test_the_committed_plan_matches_the_rules(): void
    {
        $committed = MigrationPlan::load(database_path('source-migration/plan.json'));
        $generated = (new PlanGenerator)->generate(array_keys($committed->tables));

        $this->assertSame([], $committed->problems());
        foreach ($generated->tables as $table => $entry) {
            $this->assertSame($entry['mode'], $committed->mode($table), "plan.json disagrees with config/source_migration.php for '{$table}' — regenerate or record the review decision");
        }
    }

    public function test_configured_references_and_file_columns_exist_in_the_target_schema(): void
    {
        $target = new SchemaInspector('mysql');
        foreach (array_keys(config('source_migration.unenforced_references')) as $ref) {
            [$table, $column] = explode('.', $ref);
            $this->assertTrue($target->hasColumn($table, $column), "unenforced reference {$ref}");
        }
        foreach (config('source_migration.file_columns') as [$table, $column]) {
            $this->assertTrue($target->hasColumn($table, $column), "file column {$table}.{$column}");
        }
        foreach (config('source_migration.replace_seeded') as $table => $key) {
            foreach ($key as $column) {
                $this->assertTrue($target->hasColumn($table, $column), "natural key {$table}.{$column}");
            }
        }
    }

    // ── Stage 1 and target readiness ─────────────────────────────────────

    public function test_stage_1_records_renamed_asset_migrations_only_when_their_schema_is_present(): void
    {
        $renamed = config('source_migration.renamed_migrations');
        $s = $this->staging();
        foreach ($renamed as $old => $spec) {
            $s->table('migrations')->where('migration', $spec['renamed_to'])->delete();
            $s->table('migrations')->insert(['migration' => $old, 'batch' => 3]);
        }

        $this->artisan('migration:stage1')->expectsOutputToContain('Ledger reconciliation: record 2026_06_29_000010')->assertSuccessful();
        $this->artisan('migration:stage1', ['--execute' => true])->expectsOutputToContain('requires --confirm=')->assertFailed();

        $staging = $s->getDatabaseName();
        $this->artisan('migration:stage1', ['--execute' => true, '--confirm' => $staging])->expectsOutputToContain('Stage 1 complete')->assertSuccessful();
        foreach ($renamed as $spec) {
            $this->assertSame(3, (int) $s->table('migrations')->where('migration', $spec['renamed_to'])->value('batch'));
        }

        // Marker absent → genuinely pending → refused, never recorded.
        $s->table('migrations')->where('migration', $renamed['2024_01_10_create_asset_service_logs_table']['renamed_to'])->delete();
        $s->statement('DROP TABLE asset_service_logs');
        try {
            $this->artisan('migration:stage1')->expectsOutputToContain('its schema effect is not present')->assertFailed();
        } finally {
            $this->buildStagingSchema(force: true);
        }
    }

    public function test_target_readiness_reports_queue_tables_and_never_prints_grants(): void
    {
        $this->artisan('migration:target-readiness', ['--report-dir' => $this->reportDir])
            ->doesntExpectOutputToContain('IDENTIFIED')
            ->run();
        $report = $this->latestReport('target_readiness');

        $this->assertSame(['jobs' => true, 'job_batches' => true, 'failed_jobs' => true], $report['queue']['tables']);
        $this->assertSame(0, $report['migrations']['pending']);
        $this->assertStringContainsString('flock -n', $report['queue']['recommended_cron']);
        $this->assertStringContainsString('--stop-when-empty', $report['queue']['recommended_cron']);
        $this->assertContains('QUEUE_CONNECTION is sync, expected database.', $report['issues'], 'the test environment runs the sync queue');
        $this->assertArrayHasKey('CREATE', $report['privileges']['required']);
        $this->assertStringNotContainsString('PASSWORD', json_encode($report));
    }

    /** Values for every NOT NULL column without a default, for direct target inserts. */
    private function requiredDefaults(string $table, array $except = []): array
    {
        $values = [];
        foreach (DB::select("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'", [$table]) as $c) {
            if (in_array($c->COLUMN_NAME, $except, true)) {
                continue;
            }
            $values[$c->COLUMN_NAME] = match (true) {
                str_contains($c->DATA_TYPE, 'int') => 1,
                in_array($c->DATA_TYPE, ['decimal', 'float', 'double'], true) => 0,
                $c->DATA_TYPE === 'enum' => trim(explode(',', substr($c->COLUMN_TYPE, 5, -1))[0], "'"),
                $c->DATA_TYPE === 'date' => '2026-01-01',
                in_array($c->DATA_TYPE, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
                in_array($c->DATA_TYPE, ['longtext', 'json'], true) => '[]',
                default => 'req-'.uniqid(),
            };
        }

        return $values;
    }
}
