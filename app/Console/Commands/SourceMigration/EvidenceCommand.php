<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\EvidenceReports;
use App\Support\SourceMigration\MigrationPlan;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\ReportWriter;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Console\Command;

/**
 * Read-only evidence reports for the rehearsal and the WNG decisions.
 *
 *   projects          Project preservation graph (§12)
 *   employees         Employee preservation graph (§13) — no salary values
 *   d2                PO / GRN / bill evidence for decision D2 (§15)
 *   roles             source_role_mapping_report for decision D4 (§16)
 *   budget-authority  ProjectBudgetAuthority compatibility (§19)
 *   w7                W7 labour compatibility (§20)
 *   project-status    status distribution and D5 eligibility
 *   exclusions        DATA-1 exclusion proof (staging vs target) (§21)
 *   all               every report above that applies to the connection
 */
class EvidenceCommand extends Command
{
    protected $signature = 'migration:evidence
        {report : projects|employees|d2|roles|budget-authority|w7|project-status|exclusions|all}
        {--connection= : Connection to read (default: source_staging; use the app connection for the target)}
        {--plan= : Plan file (exclusions report)}
        {--report-dir= : Report directory base}';

    protected $description = 'Read-only migration evidence reports (projects, employees, D2, roles, budget authority, W7, exclusions)';

    private const REPORTS = ['projects', 'employees', 'd2', 'roles', 'budget-authority', 'w7', 'project-status', 'exclusions'];

    public function handle(): int
    {
        $report = (string) $this->argument('report');
        if (! in_array($report, [...self::REPORTS, 'all'], true)) {
            $this->error("Unknown report '{$report}'. One of: ".implode(', ', self::REPORTS).', all.');

            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('source_migration.source_connection'));
        $guard = ConnectionGuard::make();
        if ($connection === $guard->sourceConnection()) {
            try {
                $guard->assertDistinctDatabases();
                $guard->makeSourceReadOnly();
            } catch (MigrationRefused $refused) {
                $this->error('REFUSED: '.$refused->getMessage());

                return self::FAILURE;
            }
        }

        $db = new SchemaInspector($connection);
        $evidence = new EvidenceReports($db);
        $writer = new ReportWriter(null, $this->option('report-dir') ?: null);
        $names = $report === 'all' ? self::REPORTS : [$report];

        foreach ($names as $name) {
            $data = match ($name) {
                'projects' => $evidence->projects(),
                'employees' => $evidence->employees(),
                'd2' => $evidence->d2(),
                'roles' => $evidence->roles(),
                'budget-authority' => $evidence->budgetAuthority(),
                'w7' => $evidence->w7(),
                'project-status' => $evidence->projectStatus(),
                'exclusions' => $this->exclusions($guard),
            };
            if ($data === null) {
                continue;
            }
            $file = $name === 'roles' ? 'source_role_mapping_report' : str_replace('-', '_', $name);
            $path = $writer->write($file, ['connection' => $connection, 'database' => $db->databaseName()] + $data);
            $this->line("{$name}: {$path}");
            $this->summarise($name, $data);
        }

        return self::SUCCESS;
    }

    private function exclusions(ConnectionGuard $guard): ?array
    {
        try {
            $guard->assertDistinctDatabases();
        } catch (MigrationRefused $refused) {
            $this->warn('exclusions needs both the staging copy and the target: '.$refused->getMessage());

            return null;
        }
        $plan = MigrationPlan::load((string) ($this->option('plan') ?: config('source_migration.plan_path')));

        return EvidenceReports::exclusions(new SchemaInspector($guard->sourceConnection()), new SchemaInspector($guard->targetConnection()), $plan);
    }

    private function summarise(string $name, array $data): void
    {
        match ($name) {
            'projects', 'employees' => $data['broken_relationships'] === []
                ? $this->info('  No broken relationships.')
                : collect($data['broken_relationships'])->each(fn ($n, $rel) => $this->warn("  BROKEN {$rel}: {$n}")),
            'roles' => collect($data['roles'])->each(fn ($r) => $this->line(sprintf('  %-28s %-28s users=%d', $r['name'], $r['classification'], $r['users_assigned']))),
            'd2' => collect($data['tables'])->each(fn ($t, $table) => $this->line(sprintf('  %-26s rows=%s', $table, $t['rows'] ?? 'absent'))),
            'exclusions' => $this->{$data['passes'] ? 'info' : 'error'}('  DATA-1 exclusions: '.($data['passes'] ? 'PASS' : 'FAIL')),
            default => null,
        };
    }
}
