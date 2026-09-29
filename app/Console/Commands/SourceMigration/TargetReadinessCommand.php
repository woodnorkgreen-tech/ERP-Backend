<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ReportWriter;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only readiness and deployment diagnostic for the TARGET (Report 51 §23–§24).
 *
 * Answers, from inside the checkout that runs it: which environment and database it
 * resolves to, whether its database user can create a schema, whether the migration
 * chain has run, whether the queue tables exist, whether storage is linked, and what
 * cron line the queue drain needs. It exists because deploy.yml has run
 * `migrate --force` against woodnork_erp on every master push while the database
 * stayed empty. It never prints credentials or raw GRANT lines.
 */
class TargetReadinessCommand extends Command
{
    protected $signature = 'migration:target-readiness {--report-dir= : Report directory base}';

    protected $description = 'Read-only target readiness: DB identity, privileges, migrations, queue, storage link, cron';

    private const REQUIRED_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'DROP', 'REFERENCES'];

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $report = [
            'checkout' => [
                'base_path' => base_path(),
                'environment' => app()->environment(),
                'env_file' => app()->environmentFilePath(),
                'env_file_exists' => is_file(app()->environmentFilePath()),
                'config_cached' => app()->configurationIsCached(),
                'php_binary' => PHP_BINARY,
                'php_version' => PHP_VERSION,
            ],
            'database_config' => [
                'connection' => $connection,
                'driver' => $config['driver'] ?? null,
                'host' => $config['host'] ?? null,
                'port' => $config['port'] ?? null,
                'database' => $config['database'] ?? null,
            ],
        ];

        $issues = [];
        try {
            $server = DB::connection()->selectOne('SELECT DATABASE() AS db, @@hostname AS server, @@version AS version, CURRENT_USER() AS user_host');
            $report['database_resolved'] = ['database' => $server->db, 'server' => $server->server, 'version' => $server->version];
        } catch (Throwable $e) {
            $report['database_resolved'] = ['error' => $e->getMessage()];
            $issues[] = 'Cannot connect with this checkout\'s configuration — a deploy-time migrate would fail here.';
            $this->finish($report, $issues);

            return self::FAILURE;
        }

        $report['privileges'] = $this->privileges((string) $server->db);
        $missingPrivileges = array_keys(array_filter($report['privileges']['required'], fn ($held) => ! $held));
        if ($missingPrivileges !== []) {
            $issues[] = 'Database user lacks '.implode(', ', $missingPrivileges).' on '.$server->db.' — migrations cannot build the schema.';
        }

        $db = new SchemaInspector($connection);
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        $ran = $db->ranMigrations();
        $pending = array_values(array_diff($files, $ran));
        $report['migrations'] = [
            'tables_in_database' => count($db->tables()),
            'ledger_exists' => $db->hasTable('migrations'),
            'migration_files' => count($files),
            'ran' => count($ran),
            'pending' => count($pending),
            'first_pending' => $pending[0] ?? null,
        ];
        if ($pending !== []) {
            $issues[] = count($pending).' migration(s) pending'.($db->tables() === [] ? ' — the database is EMPTY: migrate has never run successfully against it.' : '.');
        }

        $queueTables = (array) config('source_migration.queue.tables', []);
        $report['queue'] = [
            'default_connection' => config('queue.default'),
            'expected_connection' => config('source_migration.queue.connection'),
            'failed_driver' => config('queue.failed.driver'),
            'tables' => collect($queueTables)->mapWithKeys(fn ($t) => [$t => $db->hasTable($t)])->all(),
            'jobs_waiting' => $db->hasTable('jobs') ? $db->count('jobs') : null,
            'failed_jobs' => $db->hasTable('failed_jobs') ? $db->count('failed_jobs') : null,
            'recommended_cron' => strtr((string) config('source_migration.queue.cron'), ['{base_path}' => base_path(), '{php}' => PHP_BINARY]),
            'cron_installed' => $this->cronInstalled(),
            'worker' => 'Not started by this command. Enable the cron only after the migration chain has created the queue tables.',
        ];
        if (config('queue.default') !== config('source_migration.queue.connection')) {
            $issues[] = 'QUEUE_CONNECTION is '.config('queue.default').', expected '.config('source_migration.queue.connection').'.';
        }
        foreach ($report['queue']['tables'] as $table => $exists) {
            if (! $exists) {
                $issues[] = "Queue table '{$table}' is missing.";
            }
        }
        if ($report['queue']['cron_installed'] === false) {
            $issues[] = 'No queue:work cron line found in this user\'s crontab (check the hosting panel too).';
        }

        $link = public_path('storage');
        $report['storage'] = [
            'public_storage_link' => is_link($link),
            'points_to' => is_link($link) ? readlink($link) : null,
            'expected' => storage_path('app/public'),
        ];
        if (! is_link($link)) {
            $issues[] = 'public/storage is not linked (php artisan storage:link).';
        }

        $this->finish($report, $issues);

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Privileges derived from SHOW GRANTS; the raw lines (which may carry password hashes) are never output. */
    private function privileges(string $database): array
    {
        $held = [];
        try {
            foreach (DB::select('SHOW GRANTS FOR CURRENT_USER()') as $row) {
                $line = (string) array_values((array) $row)[0];
                if (! preg_match('/^GRANT (.+?) ON (\S+) TO /i', $line, $m)) {
                    continue;
                }
                $scope = str_replace(['`', '\\'], '', $m[2]);
                if ($scope !== '*.*' && $scope !== "{$database}.*" && ! fnmatch($scope, "{$database}.*")) {
                    continue;
                }
                foreach (array_map('trim', explode(',', strtoupper($m[1]))) as $privilege) {
                    $held[$privilege] = true;
                }
            }
        } catch (Throwable $e) {
            return ['required' => array_fill_keys(self::REQUIRED_PRIVILEGES, false), 'error' => 'SHOW GRANTS failed: '.$e->getMessage()];
        }

        $all = isset($held['ALL PRIVILEGES']) || isset($held['ALL']);

        return ['required' => collect(self::REQUIRED_PRIVILEGES)->mapWithKeys(fn ($p) => [$p => $all || isset($held[$p])])->all()];
    }

    private function cronInstalled(): ?bool
    {
        try {
            $process = new Process(['crontab', '-l']);
            $process->setTimeout(5)->run();

            return $process->isSuccessful() ? str_contains($process->getOutput(), 'queue:work') : false;
        } catch (Throwable) {
            return null; // not determinable from here (e.g. crontab unavailable): check the hosting panel
        }
    }

    private function finish(array $report, array $issues): void
    {
        $report['issues'] = $issues;
        $report['ready'] = $issues === [];
        $path = (new ReportWriter(null, $this->option('report-dir') ?: null))->write('target_readiness', $report);

        $this->line('Checkout: '.$report['checkout']['base_path'].' (env '.$report['checkout']['environment'].', .env '.($report['checkout']['env_file_exists'] ? 'present' : 'MISSING').')');
        $this->line('Database: '.($report['database_config']['database'] ?? '?').' on '.($report['database_config']['host'] ?? '?'));
        foreach ($issues as $issue) {
            $this->warn("  - {$issue}");
        }
        $this->{$issues === [] ? 'info' : 'error'}('TARGET '.($issues === [] ? 'READY' : 'NOT READY')." — report: {$path}");
    }
}
