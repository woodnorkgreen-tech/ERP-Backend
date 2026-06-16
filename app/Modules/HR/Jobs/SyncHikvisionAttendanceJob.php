<?php

namespace App\Modules\HR\Jobs;

use App\Modules\HR\Models\AttendanceSyncRequest;
use App\Modules\HR\Services\HikvisionSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncHikvisionAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public readonly string $syncRequestId) {}

    public function handle(HikvisionSyncService $syncService): void
    {
        $request = AttendanceSyncRequest::query()->findOrFail($this->syncRequestId);
        $request->update([
            'status' => AttendanceSyncRequest::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ]);

        $syncLog = $syncService->sync();

        $request->update([
            'status' => AttendanceSyncRequest::STATUS_COMPLETED,
            'sync_log_id' => $syncLog->id,
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AttendanceSyncRequest::query()
            ->whereKey($this->syncRequestId)
            ->update([
                'status' => AttendanceSyncRequest::STATUS_FAILED,
                'completed_at' => now(),
                'error' => 'Attendance sync job failed before completion.',
            ]);
    }
}
