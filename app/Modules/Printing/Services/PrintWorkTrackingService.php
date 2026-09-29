<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintWorkTrackingService
{
    public function begin(PrintJob $job): PrintJob
    {
        return DB::transaction(function () use ($job) {
            $job = PrintJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (in_array($job->status, ['completed', 'cancelled', 'reprint_required'], true)) {
                throw ValidationException::withMessages(['status' => ['Finished, cancelled, or reprint-required work cannot be started.']]);
            }
            if ($job->stop_required_at !== null && $job->stop_acknowledged_at === null) {
                throw ValidationException::withMessages(['stop_required' => ['A client artwork change requires this print job to stop.']]);
            }
            if ($job->workSessions()->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['work_tracking' => ['This print job is already being worked on.']]);
            }
            $job->workSessions()->create(['user_id' => auth()->id(), 'started_at' => now()]);
            $job->update([
                'status' => 'printing',
                'operator_id' => $job->operator_id ?: auth()->id(),
                'started_at' => $job->started_at ?: now(),
                'updated_by' => auth()->id(),
            ]);

            return $this->fresh($job);
        });
    }

    public function pause(PrintJob $job, string $reason, ?string $details = null): PrintJob
    {
        if ($reason === 'other' && blank($details)) {
            throw ValidationException::withMessages(['details' => ['Explain the pause when Other is selected.']]);
        }

        return DB::transaction(function () use ($job, $reason, $details) {
            $job = PrintJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $session = $job->workSessions()->whereNull('ended_at')->latest('started_at')->first();
            if (!$session) {
                throw ValidationException::withMessages(['work_tracking' => ['No active work session is available to pause.']]);
            }
            $session->update([
                'ended_at' => now(), 'end_type' => 'paused', 'pause_reason_code' => $reason,
                'pause_reason_details' => $details, 'ended_by' => auth()->id(),
            ]);

            return $this->fresh($job);
        });
    }

    public function closeForWorkflow(PrintJob $job, string $endType, ?string $reason = null): void
    {
        $session = $job->workSessions()->whereNull('ended_at')->latest('started_at')->first();
        if (!$session) return;
        $session->update([
            'ended_at' => now(), 'end_type' => $endType, 'pause_reason_code' => $reason,
            'ended_by' => auth()->id(),
        ]);
    }

    private function fresh(PrintJob $job): PrintJob
    {
        return $job->fresh([
            'consumptions.roll', 'operator', 'machine',
            'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name',
            'workSessions.user:id,name', 'workSessions.endedBy:id,name',
        ]);
    }
}
