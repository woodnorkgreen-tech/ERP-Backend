<?php

namespace App\Modules\Printing\Services;

use App\Modules\Assets\Models\Asset;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Validation\ValidationException;

class PrintJobService
{
    public function __construct(private readonly PrintWorkTrackingService $workTracking)
    {
    }

    public function update(PrintJob $job, array $data): PrintJob
    {
        if ($job->isLocked()) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled print jobs are locked. Use correction/reopen flow.'],
            ]);
        }

        if (isset($data['machine_asset_id'])) {
            $asset = Asset::find($data['machine_asset_id']);
            $data['machine_name_snapshot'] = $asset?->name;
        }

        $job->update($data + ['updated_by' => auth()->id()]);

        return $job->fresh(['consumptions.roll', 'operator', 'machine', 'workSessions.user:id,name', 'workSessions.endedBy:id,name']);
    }

    public function transition(PrintJob $job, string $status, ?string $reason = null): PrintJob
    {
        if ($job->origin === 'historical_import') {
            throw ValidationException::withMessages([
                'status' => ['Imported historical jobs cannot enter the live printing workflow.'],
            ]);
        }
        if ($status === 'reprint_required' && !in_array($job->status, ['completed', 'reprint_required'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only completed print jobs can be marked as requiring a reprint.'],
            ]);
        }

        if ($status === 'completed') {
            $this->assertReadyToComplete($job);
        }

        if ($status === 'printing' && $job->stop_required_at !== null && $job->stop_acknowledged_at === null) {
            throw ValidationException::withMessages([
                'stop_required' => ['A client artwork change requires this print job to stop.'],
            ]);
        }

        $from = $job->status;
        $job->update([
            'status' => $status,
            'started_at' => $status === 'printing' && !$job->started_at ? now() : $job->started_at,
            'completed_at' => $status === 'completed' ? now() : $job->completed_at,
            'updated_by' => auth()->id(),
        ]);

        if ($status === 'printing' && !$job->workSessions()->whereNull('ended_at')->exists()) {
            $job->workSessions()->create(['user_id' => auth()->id(), 'started_at' => now()]);
        }
        if (in_array($status, ['completed', 'cancelled', 'reprint_required'], true)) {
            $this->workTracking->closeForWorkflow($job, $status === 'completed' ? 'completed' : 'stopped');
        }

        $job->events()->create([
            'event_type' => 'status_changed',
            'from_status' => $from,
            'to_status' => $status,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);

        return $job->fresh([
            'consumptions.roll', 'operator', 'machine',
            'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name',
            'workSessions.user:id,name', 'workSessions.endedBy:id,name',
        ]);
    }

    private function assertReadyToComplete(PrintJob $job): void
    {
        $hasMaterialUsage = $job->consumptions()
            ->whereNotNull('print_roll_id')
            ->where('actual_running_m', '>', 0)
            ->exists();

        if (!$hasMaterialUsage) {
            throw ValidationException::withMessages([
                'material_usage' => ['Record material/roll usage before completing this print job.'],
            ]);
        }
    }

    public function reprint(PrintJob $job, string $reason): PrintJob
    {
        if ($job->origin === 'historical_import') {
            throw ValidationException::withMessages([
                'order_type' => ['Create live reprints from a new print job.'],
            ]);
        }
        if (!in_array($job->status, ['completed', 'reprint_required'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only completed print jobs can be reprinted.'],
            ]);
        }

        if ($job->order_type === 'reprint') {
            throw ValidationException::withMessages([
                'order_type' => ['Create reprints from the original completed print job.'],
            ]);
        }

        $reprint = $job->replicate([
            'status',
            'original_design_handoff_id',
            'started_at',
            'completed_at',
            'created_at',
            'updated_at',
        ]);
        $reprint->order_type = 'reprint';
        $reprint->original_design_handoff_id = null;
        $reprint->status = 'queued';
        $reprint->reprint_of_job_id = $job->id;
        $reprint->reprint_reason = $reason;
        $reprint->artwork_version = max(2, ((int) ($job->artwork_version ?? 1)) + 1);
        $reprint->created_by = auth()->id();
        $reprint->updated_by = auth()->id();
        $reprint->save();

        $reprint->events()->create([
            'event_type' => 'reprint_created',
            'reason' => $reason,
            'payload' => ['original_print_job_id' => $job->id],
            'created_by' => auth()->id(),
        ]);

        return $reprint->fresh(['consumptions.roll', 'operator', 'machine', 'workSessions.user:id,name', 'workSessions.endedBy:id,name']);
    }
}
