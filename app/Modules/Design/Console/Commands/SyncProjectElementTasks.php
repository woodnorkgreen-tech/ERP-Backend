<?php

namespace App\Modules\Design\Console\Commands;

use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Services\DesignProjectSyncService;
use Illuminate\Console\Command;

class SyncProjectElementTasks extends Command
{
    protected $signature = 'design:sync-element-tasks {--dry-run : Preview changes without writing} {--job= : Limit to a Design job ID}';

    protected $description = 'Backfill Graphic and Structural primary tasks for project-linked Design jobs';

    public function handle(DesignProjectSyncService $sync): int
    {
        $totals = ['created' => 0, 'adopted' => 0, 'ambiguous' => 0];
        $query = DesignJob::query()->whereNotNull('project_enquiry_id');
        if ($this->option('job')) $query->whereKey((int) $this->option('job'));

        $query->chunkById(100, function ($jobs) use ($sync, &$totals) {
            foreach ($jobs as $job) {
                $result = $sync->syncItemsForJob($job, (bool) $this->option('dry-run'));
                foreach (array_keys($totals) as $key) $totals[$key] += $result[$key];
                foreach ($result['ambiguous_keys'] as $key) $this->warn("Review ambiguous primary task: {$key}");
            }
        });

        $label = $this->option('dry-run') ? 'Would create/adopt' : 'Created/adopted';
        $this->info("{$label}: {$totals['created']} / {$totals['adopted']}; ambiguous: {$totals['ambiguous']}.");
        return $totals['ambiguous'] ? self::FAILURE : self::SUCCESS;
    }
}
