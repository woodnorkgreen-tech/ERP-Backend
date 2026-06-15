<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Services\HikvisionSyncService;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncHikvisionAttendance extends Command
{
    protected $signature = 'attendance:sync-hikvision
                            {--from= : Start datetime (Y-m-d H:i:s). Defaults to the configured lookback.}
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

        $defaultRange = (int) config('hikvision.sync_lookback_days', 30);
        $this->info('Syncing Hikvision attendance' . ($from ? " from {$from}" : " (last {$defaultRange} days)") . '...');

        $log = $this->syncService->sync($from, $to);

        if ($log->status === AttendanceDeviceSyncLog::STATUS_SUCCESS) {
            $this->info("Sync complete. Imported: {$log->records_imported} events, processed: {$log->records_processed} records.");
            return Command::SUCCESS;
        }

        if ($log->status === AttendanceDeviceSyncLog::STATUS_PARTIAL) {
            $this->warn($log->error ?: 'Sync completed with attendance processing exceptions.');
            return Command::SUCCESS;
        }

        $this->error("Sync failed: {$log->error}");
        return Command::FAILURE;
    }
}
