<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateQuoteExcelToPrivateDisk extends Command
{
    protected $signature = 'quotes:migrate-excel-files {--dry-run : List files that would be moved without moving them}';

    protected $description = 'Move Excel quote files from the public disk to the private local disk so they are only reachable via signed download URLs';

    public function handle(): int
    {
        $files = Storage::disk('public')->allFiles('quote_excel');

        if (empty($files)) {
            $this->info('No Excel quote files found on the public disk. Nothing to migrate.');
            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;

        foreach ($files as $path) {
            if ($this->option('dry-run')) {
                $this->line("Would move: {$path}");
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                $this->warn("Already on private disk, deleting public copy: {$path}");
                Storage::disk('public')->delete($path);
                $skipped++;
                continue;
            }

            Storage::disk('local')->writeStream($path, Storage::disk('public')->readStream($path));

            // Only remove the public copy once the private copy is verified.
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('public')->delete($path);
                $moved++;
            } else {
                $this->error("Failed to copy {$path} to the private disk; public copy kept.");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete: ' . count($files) . ' file(s) would be moved.');
        } else {
            $this->info("Migration complete: {$moved} moved, {$skipped} duplicate public copies removed.");
        }

        return self::SUCCESS;
    }
}
