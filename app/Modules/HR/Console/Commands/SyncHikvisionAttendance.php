<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Services\HikvisionSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncHikvisionAttendance extends Command
{
    protected $signature = 'attendance:sync-hikvision
                            {--from= : Start datetime (Y-m-d H:i:s). Defaults to 24 hours ago.}
                            {--to=   : End datetime (Y-m-d H:i:s). Defaults to now.}';

    protected $description = 'Sync attendance data from the Hikvision fingerprint access-control device';

    public function __construct(private readonly HikvisionSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
        $to   = $this->option('to')   ? Carbon::parse($this->option('to'))   : null;

        $this->info('Syncing Hikvision attendance' . ($from ? " from {$from}" : ' (last 24 hours)') . '...');

        $log = $this->syncService->sync($from, $to);

        if ($log->status === 'success') {
            $this->info("Sync complete. Imported: {$log->records_imported} events, processed: {$log->records_processed} records.");
            return Command::SUCCESS;
        }

        $this->error("Sync failed: {$log->error}");
        return Command::FAILURE;
    }
}
