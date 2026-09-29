<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;

/**
 * Stage 1 of the source-to-target migration (Report 50 §21): upgrade the staging
 * COPY of the source with this codebase's migrations, so every backfill and seed the
 * target's migrations perform runs on the real source rows.
 *
 * This is the only command that writes to `source_staging`, and only to a copy: it
 * refuses the live source database, the application's database and the live target.
 * Its one ledger write before migrating is the marker-verified reconciliation of the
 * two renamed asset migrations.
 */
class Stage1TransformCommand extends Command
{
    protected $signature = 'migration:stage1
        {--execute : Apply the reconciliation and run the pending migrations on the staging COPY}
        {--confirm= : The staging database name, typed exactly (required with --execute)}';

    protected $description = 'Stage 1: upgrade the staging copy of the source with the target migrations (dry run by default)';

    public function handle(): int
    {
        try {
            return $this->perform();
        } catch (MigrationRefused $refused) {
            $this->error('REFUSED');
            foreach (explode("\n", $refused->getMessage()) as $line) {
                $this->line("  - {$line}");
            }

            return self::FAILURE;
        }
    }

    private function perform(): int
    {
        $guard = ConnectionGuard::make();
        $identities = $guard->assertDistinctDatabases();
        $staging = $identities['source']['database'];
        if (in_array($staging, (array) config('source_migration.live_target_databases', []), true)) {
            throw MigrationRefused::because(["'{$staging}' is the live target, not a staging copy."]);
        }
        $this->info("Stage 1 on the staging COPY: {$staging} on {$identities['source']['server']}");

        $db = new SchemaInspector($guard->sourceConnection());
        if (! $db->hasTable('migrations')) {
            throw MigrationRefused::because(['The staging copy has no migrations ledger. It must be a restored dump of the source ERP, which has one.']);
        }

        $reconcile = $this->reconciliationPlan($db);
        foreach ($reconcile['refused'] as $reason) {
            $this->error($reason);
        }
        if ($reconcile['refused'] !== []) {
            throw MigrationRefused::because($reconcile['refused']);
        }
        foreach ($reconcile['insert'] as $row) {
            $this->line("Ledger reconciliation: record {$row['migration']} (batch {$row['batch']}) — markers verified.");
        }

        $pending = $this->pending($db, array_column($reconcile['insert'], 'migration'));
        $this->line('Target migrations pending on the staging copy: '.count($pending).($pending ? " ({$pending[0]} … ".end($pending).')' : ''));

        if (! $this->option('execute')) {
            $this->info('DRY RUN — nothing was written.');

            return self::SUCCESS;
        }
        if ($this->option('confirm') !== $staging) {
            throw MigrationRefused::because(["Stage 1 execution requires --confirm={$staging} (the staging copy's database name)."]);
        }

        foreach ($reconcile['insert'] as $row) {
            $db->connection()->table('migrations')->insert($row);
        }

        $exit = Artisan::call('migrate', ['--database' => $guard->sourceConnection(), '--force' => true]);
        $this->output->write(Artisan::output());

        $remaining = $this->pending(new SchemaInspector($guard->sourceConnection()));
        if ($exit !== 0 || $remaining !== []) {
            $this->error('Stage 1 did not complete: '.count($remaining).' migration(s) still pending.');

            return self::FAILURE;
        }
        $this->info('Stage 1 complete: every target migration is recorded on the staging copy.');

        return self::SUCCESS;
    }

    /** @return array{insert: list<array{migration: string, batch: int}>, refused: list<string>} */
    private function reconciliationPlan(SchemaInspector $db): array
    {
        $ledger = $db->connection()->table('migrations')->pluck('batch', 'migration')->all();
        $insert = [];
        $refused = [];

        foreach ((array) config('source_migration.renamed_migrations', []) as $old => $spec) {
            if (! isset($ledger[$old]) || isset($ledger[$spec['renamed_to']])) {
                continue;
            }
            $failed = array_values(array_filter($spec['markers'], fn ($marker) => ! $this->markerHolds($db, $marker)));
            if ($failed !== []) {
                $refused[] = "Ledger has {$old} but its schema effect is not present (".implode(', ', $failed).'); refusing to record '.$spec['renamed_to'].'.';

                continue;
            }
            $insert[] = ['migration' => $spec['renamed_to'], 'batch' => (int) $ledger[$old]];
        }

        return ['insert' => $insert, 'refused' => $refused];
    }

    private function markerHolds(SchemaInspector $db, string $marker): bool
    {
        [$kind, $subject] = explode(':', $marker, 2);

        return match ($kind) {
            'table' => $db->hasTable($subject),
            'column' => $db->hasColumn(...explode('.', $subject, 2)),
            default => false,
        };
    }

    /** @return list<string> */
    private function pending(SchemaInspector $db, array $alsoRecorded = []): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        $ran = array_flip([...$db->ranMigrations(), ...$alsoRecorded]);

        return array_values(array_filter($files, fn ($m) => ! isset($ran[$m])));
    }
}
