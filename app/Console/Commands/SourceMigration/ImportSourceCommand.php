<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\EvidenceReports;
use App\Support\SourceMigration\ImportOrder;
use App\Support\SourceMigration\LoadValidator;
use App\Support\SourceMigration\MigrationPlan;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\OrphanScanner;
use App\Support\SourceMigration\ReportWriter;
use App\Support\SourceMigration\SchemaGate;
use App\Support\SourceMigration\SchemaInspector;
use App\Support\SourceMigration\TableLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stage 2 of the source-to-target migration (Reports 50/51): load the reviewed plan's
 * tables from the Stage 1 staging COPY (connection `source_staging`, read-only) into
 * the application's database, with original IDs.
 *
 * Dry run is the default and writes nothing. Loading requires --execute, the target
 * database name typed in --confirm, and --cutover as well if the target is the live
 * one. Every guard fails closed.
 */
class ImportSourceCommand extends Command
{
    protected $signature = 'migration:import-source
        {--plan= : Reviewed plan file (default: config source_migration.plan_path)}
        {--execute : Load data. Without this flag the command is a dry run}
        {--confirm= : The target database name, typed exactly (required with --execute)}
        {--cutover : Required as well when the target is the LIVE target (D8)}
        {--only=* : Restrict to these tables (e.g. model_has_permissions after permissions:sync)}
        {--resume : Skip tables whose target rows already equal staging; refuse partial ones}
        {--allow-orphans=* : table.column references whose pre-existing orphans WNG approved loading as-is}
        {--accept-verified-narrowing : Accept column narrowings whose every existing value was proven to fit (reviewed)}
        {--validate : Run post-load reconciliation only}
        {--report-dir= : Report directory base (default: storage/app/source-migration)}';

    protected $description = 'Load the Stage 1 staging copy into the clean target (dry run by default)';

    public function handle(): int
    {
        $reports = new ReportWriter(null, $this->option('report-dir') ?: null);

        try {
            return $this->perform($reports);
        } catch (MigrationRefused $refused) {
            $this->error('REFUSED');
            foreach (explode("\n", $refused->getMessage()) as $line) {
                $this->line("  - {$line}");
            }
            $reports->write('refusal', ['refused' => explode("\n", $refused->getMessage())]);

            return self::FAILURE;
        }
    }

    private function perform(ReportWriter $reports): int
    {
        $guard = ConnectionGuard::make();
        $identities = $guard->assertDistinctDatabases();
        $guard->makeSourceReadOnly();
        $guard->assertSameSessionTimezone();

        $this->info("Source (staging copy, read-only): {$identities['source']['database']} on {$identities['source']['server']}");
        $this->info("Target: {$identities['target']['database']} on {$identities['target']['server']}");

        $plan = MigrationPlan::load((string) ($this->option('plan') ?: config('source_migration.plan_path')));
        $source = new SchemaInspector($guard->sourceConnection());
        $target = new SchemaInspector($guard->targetConnection());

        $gate = (new SchemaGate($source, $target, $plan, (bool) $this->option('accept-verified-narrowing')))->check();
        $reports->write('schema_gate', [
            'passes' => $gate->passes(),
            'problems' => $gate->problems,
            'drift_summary' => collect($gate->drift)->countBy('category')->all(),
            'drift' => $gate->drift,
            'notes' => $gate->notes,
        ]);
        if (! $gate->passes()) {
            throw MigrationRefused::because(['Schema gate failed:', ...$gate->problems]);
        }
        $this->info('Schema gate: PASS'.($gate->drift ? ' ('.count($gate->drift).' classified drift(s) accepted; see schema_gate report)' : ''));

        $only = array_filter((array) $this->option('only'));
        $loadTables = array_values(array_filter(
            $plan->tablesIn(...MigrationPlan::LOAD_MODES),
            fn ($t) => $only !== [] ? in_array($t, $only, true) : $plan->mode($t) !== MigrationPlan::IMPORT_MAPPED,
        ));
        foreach (array_diff($only, $loadTables) as $unknown) {
            throw MigrationRefused::because(["--only table '{$unknown}' is not a loaded table in the plan."]);
        }

        $order = ImportOrder::compute($loadTables, $target->foreignKeys());
        $orphans = (new OrphanScanner($source, $target, $plan))->scan();
        $allowed = array_flip((array) $this->option('allow-orphans'));
        $blocking = array_values(array_filter($orphans, fn ($f) => in_array($f['table'], $loadTables, true)
            && ($f['orphans'] ?? 0) > 0 && ! isset($allowed["{$f['table']}.{$f['column']}"])));

        $reports->write('import_order', ['order' => $order->order, 'cycles' => $order->cycles, 'self_references' => $order->selfReferences]);
        $reports->write('orphan_scan_staging', ['findings' => $orphans, 'blocking' => $blocking, 'allowed' => array_keys($allowed)]);
        $reports->write('plan_summary', [
            'modes' => collect(MigrationPlan::MODES)->mapWithKeys(fn ($m) => [$m => $plan->tablesIn($m)])->all(),
            'staging_counts' => collect($loadTables)->mapWithKeys(fn ($t) => [$t => $source->count($t)])->all(),
            'decision_pending' => collect($plan->tablesIn(MigrationPlan::DECISION_PENDING))
                ->mapWithKeys(fn ($t) => [$t => $source->hasTable($t) ? $source->count($t) : null])->all(),
            'excluded' => collect($plan->tablesIn(MigrationPlan::EXCLUDE))
                ->mapWithKeys(fn ($t) => [$t => $source->hasTable($t) ? $source->count($t) : null])->all(),
            // Skipped tables with rows are listed with their counts: nothing is left behind unseen.
            'skipped' => collect($plan->tablesIn(MigrationPlan::SKIP))
                ->mapWithKeys(fn ($t) => [$t => $source->hasTable($t) ? $source->count($t) : null])->all(),
        ]);

        $this->line('Load order: '.count($order->order).' tables; cycles: '.(collect($order->cycles)->map(fn ($c) => implode(' ↔ ', $c))->implode('; ') ?: 'none'));
        $this->line('Orphan scan (staging): '.count($orphans).' broken relationship(s), '.count($blocking).' blocking.');
        foreach ($blocking as $f) {
            $this->warn("  {$f['severity']} {$f['table']}.{$f['column']} → {$f['parent']}: {$f['orphans']} orphan(s), e.g. child ids ".implode(',', $f['sample_child_ids']));
        }

        if ($this->option('validate')) {
            return $this->validateOnly($source, $target, $plan, $orphans, $reports);
        }

        $loader = new TableLoader($source, $target, $plan, (int) config('source_migration.chunk_size', 500));
        $skipped = [];
        $problems = [];
        foreach ($order->order as $table) {
            if ($this->option('resume') && $loader->alreadyLoaded($table)) {
                $skipped[] = $table;

                continue;
            }
            array_push($problems, ...$loader->preconditionProblems($table));
        }

        if (! $this->option('execute')) {
            $reports->write('dry_run', ['would_load' => array_values(array_diff($order->order, $skipped)), 'already_loaded' => $skipped,
                'refusals_if_executed' => [...$problems, ...array_map(fn ($f) => "Orphans in {$f['table']}.{$f['column']} not allow-listed", $blocking)]]);
            $this->info('DRY RUN — nothing was written. Reports: '.$reports->directory);
            foreach ($problems as $problem) {
                $this->warn("Execution would refuse: {$problem}");
            }

            return self::SUCCESS;
        }

        $guard->assertExecutionConfirmed($this->option('confirm'), (bool) $this->option('cutover'));
        if ($blocking !== []) {
            throw MigrationRefused::because(['Orphans found in loaded tables. Resolve them, or load them as-is with WNG approval via --allow-orphans=table.column:',
                ...array_map(fn ($f) => "{$f['table']}.{$f['column']} ({$f['orphans']})", $blocking)]);
        }
        if ($problems !== []) {
            throw MigrationRefused::because($problems);
        }

        $results = [];
        $loader->openLoadSession();
        try {
            foreach ($order->order as $table) {
                if (in_array($table, $skipped, true)) {
                    continue;
                }
                $results[$table] = $loader->load($table);
                $this->line(sprintf('  %-45s %-15s %8d rows', $table, $results[$table]['mode'], $results[$table]['rows']));
            }
        } catch (Throwable $e) {
            $reports->write('load', ['completed' => $results, 'failed_at' => $table ?? null, 'error' => $e->getMessage()]);
            throw MigrationRefused::because(["Load failed at '".($table ?? '?')."' (that table was rolled back; earlier tables remain loaded): ".$e->getMessage(),
                'Rebuild the rehearsal target from the migration chain, or fix the cause and re-run with --resume.']);
        } finally {
            $loader->closeLoadSession();
        }
        $reports->write('load', ['completed' => $results, 'resumed_skipped' => $skipped]);

        return $this->validateOnly($source, $target, $plan, $orphans, $reports, array_keys($results));
    }

    private function validateOnly(SchemaInspector $source, SchemaInspector $target, MigrationPlan $plan, array $preOrphans, ReportWriter $reports, ?array $tables = null): int
    {
        $tables = $tables === null ? null : array_values(array_filter($tables, fn ($t) => $plan->mode($t) !== MigrationPlan::IMPORT_MAPPED));
        $validation = (new LoadValidator($source, $target, $plan))->validate($tables, $preOrphans);
        $reports->write('validation', $validation);
        $reports->write('authentication', EvidenceReports::authentication($source, $target));

        $this->line(sprintf('Reconciled %d table(s); mismatched: %s; orphans introduced: %d; exclusions: %s.',
            $validation['tables_reconciled'],
            $validation['mismatched_tables'] ? implode(', ', $validation['mismatched_tables']) : 'none',
            count($validation['orphans_introduced_by_load']),
            $validation['exclusions']['passes'] ? 'PASS' : 'FAIL'));
        $this->info('Reports: '.$reports->directory);
        $this->{$validation['passes'] ? 'info' : 'error'}('VALIDATION: '.($validation['passes'] ? 'PASS' : 'FAIL'));

        return $validation['passes'] ? self::SUCCESS : self::FAILURE;
    }
}
