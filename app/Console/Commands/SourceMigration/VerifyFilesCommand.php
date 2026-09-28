<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\FileReferenceVerifier;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\ReportWriter;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Console\Command;

/**
 * Verify that every file referenced by a preserved row exists in a storage tree
 * (Report 51 §17). Read-only: it never copies, moves or deletes a file.
 */
class VerifyFilesCommand extends Command
{
    protected $signature = 'migration:verify-files
        {--connection= : Connection whose rows are checked (default: source_staging)}
        {--storage-path= : The storage directory to check against (the source storage archive, extracted)}
        {--dry-run : List the file columns and row counts only; do not touch the filesystem}
        {--report-dir= : Report directory base}';

    protected $description = 'Verify file references in preserved tables against a storage tree (read-only)';

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('source_migration.source_connection'));
        if ($connection === config('source_migration.source_connection')) {
            try {
                ConnectionGuard::make()->assertDistinctDatabases();
                ConnectionGuard::make()->makeSourceReadOnly();
            } catch (MigrationRefused $refused) {
                $this->error('REFUSED: '.$refused->getMessage());

                return self::FAILURE;
            }
        }

        $verifier = new FileReferenceVerifier(new SchemaInspector($connection));
        $writer = new ReportWriter(null, $this->option('report-dir') ?: null);

        if ($this->option('dry-run')) {
            $inventory = $verifier->inventory();
            $this->table(['Table', 'Column', 'Kind', 'Module', 'Rows with a value'],
                array_map(fn ($i) => [$i['table'], $i['column'], $i['kind'], $i['module'], $i['present'] ? $i['rows'] : 'column absent'], $inventory));
            $writer->write('file_inventory', ['connection' => $connection, 'columns' => $inventory]);
            $this->info('DRY RUN — the filesystem was not read. Reports: '.$writer->directory);

            return self::SUCCESS;
        }

        $storage = (string) $this->option('storage-path');
        if ($storage === '' || ! is_dir($storage)) {
            $this->error('--storage-path must be an existing directory (the extracted source storage archive).');

            return self::FAILURE;
        }

        $result = $verifier->verify($storage);
        $writer->write('file_verification', ['connection' => $connection] + $result);
        $this->table(['Module', 'References', 'Present', 'Missing', 'Absolute URL', 'Inline data'],
            collect($result['by_module'])->map(fn ($m, $module) => [$module, $m['references'], $m['present'], $m['missing'], $m['absolute_url'], $m['inline_data']])->values()->all());
        $this->{$result['passes'] ? 'info' : 'error'}("Files: {$result['totals']['missing']} missing of {$result['totals']['references']}. Reports: {$writer->directory}");

        return $result['passes'] ? self::SUCCESS : self::FAILURE;
    }
}
