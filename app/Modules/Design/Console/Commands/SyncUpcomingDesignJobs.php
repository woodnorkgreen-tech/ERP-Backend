<?php

namespace App\Modules\Design\Console\Commands;

use App\Modules\Design\Services\DesignProjectSyncService;
use Illuminate\Console\Command;

class SyncUpcomingDesignJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'design:sync-upcoming {--days= : Override the sync window in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync design jobs for project enquiries due within the auto-sync window';

    /**
     * Execute the console command.
     */
    public function handle(DesignProjectSyncService $service): int
    {
        $days = $this->option('days');
        $result = $service->syncAllUpcoming($days !== null ? (int) $days : null);

        $this->info("{$result['created']} new design job(s) created, {$result['total']} project(s) due within {$result['days']} days in sync.");

        return self::SUCCESS;
    }
}
