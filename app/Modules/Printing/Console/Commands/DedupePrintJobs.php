<?php

namespace App\Modules\Printing\Console\Commands;

use App\Modules\Printing\Models\PrintJob;
use Illuminate\Console\Command;

class DedupePrintJobs extends Command
{
    protected $signature = 'printing:dedupe-jobs {--dry-run : Show duplicates without deleting them}';

    protected $description = 'Remove duplicate original Print Jobs created from the same Design item when they have no material consumption.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $skipped = 0;

        $groups = PrintJob::query()
            ->whereNotNull('design_item_id')
            ->where('order_type', 'original')
            ->whereNull('reprint_of_job_id')
            ->selectRaw('design_item_id, COUNT(*) as total')
            ->groupBy('design_item_id')
            ->having('total', '>', 1)
            ->pluck('total', 'design_item_id');

        $this->info("Found {$groups->count()} duplicated Design item group(s).");

        foreach ($groups as $designItemId => $total) {
            $jobs = PrintJob::query()
                ->withCount('consumptions')
                ->where('design_item_id', $designItemId)
                ->where('order_type', 'original')
                ->whereNull('reprint_of_job_id')
                ->oldest()
                ->get();

            $keeper = $jobs->first();
            $duplicates = $jobs->skip(1);

            $this->line("Design item #{$designItemId}: keeping print job #{$keeper?->id}, checking " . ($total - 1) . ' duplicate(s).');

            foreach ($duplicates as $job) {
                if ($job->consumptions_count > 0 || $job->isLocked()) {
                    $this->warn("Skipped print job #{$job->id}; it has usage or is locked.");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] Would delete print job #{$job->id}: {$job->title}");
                    $deleted++;
                    continue;
                }

                $job->events()->delete();
                $job->delete();
                $this->line("Deleted print job #{$job->id}: {$job->title}");
                $deleted++;
            }
        }

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$deleted}; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
