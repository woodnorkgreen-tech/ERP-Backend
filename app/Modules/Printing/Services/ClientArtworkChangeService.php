<?php

namespace App\Modules\Printing\Services;

use App\Modules\Design\Models\DesignItem;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientArtworkChangeService
{
    public function __construct(private readonly PrintWorkTrackingService $workTracking)
    {
    }

    /**
     * Remove work that has not started and raise a stop alert for work already printing.
     */
    public function notify(DesignItem $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason) {
            $jobs = PrintJob::query()
                ->where('design_item_id', $item->id)
                ->whereIn('status', ['queued', 'printing'])
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                if ($job->status === 'queued') {
                    $job->update([
                        'status' => 'cancelled',
                        'updated_by' => auth()->id(),
                    ]);
                    $job->events()->create([
                        'event_type' => 'withdrawn_for_client_changes',
                        'from_status' => 'queued',
                        'to_status' => 'cancelled',
                        'reason' => $reason,
                        'created_by' => auth()->id(),
                    ]);
                    continue;
                }

                if ($job->stop_acknowledged_at === null) {
                    $job->update([
                        'stop_required_at' => now(),
                        'stop_required_reason' => $reason,
                        'stop_required_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]);
                    $job->events()->create([
                        'event_type' => 'stop_required_for_client_changes',
                        'from_status' => 'printing',
                        'to_status' => 'printing',
                        'reason' => $reason,
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            $item->handoffs()
                ->where('target_module', 'printing')
                ->whereIn('status', ['pending', 'accepted'])
                ->update([
                    'status' => 'cancelled',
                    'rejection_reason' => $reason,
                    'responded_by' => auth()->id(),
                    'responded_at' => now(),
                ]);
        });
    }

    public function acknowledgeStop(PrintJob $job): PrintJob
    {
        return DB::transaction(function () use ($job) {
            $job = PrintJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($job->stop_required_at === null) {
                throw ValidationException::withMessages([
                    'stop_required' => ['This print job does not have a stop request.'],
                ]);
            }

            if ($job->stop_acknowledged_at !== null) {
                return $this->fresh($job);
            }

            $this->workTracking->closeForWorkflow($job, 'stopped', 'client_artwork_changes');
            $from = $job->status;
            $job->update([
                'status' => 'cancelled',
                'stop_acknowledged_at' => now(),
                'stop_acknowledged_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $job->events()->create([
                'event_type' => 'client_change_stop_acknowledged',
                'from_status' => $from,
                'to_status' => 'cancelled',
                'reason' => $job->stop_required_reason,
                'created_by' => auth()->id(),
            ]);

            return $this->fresh($job);
        });
    }

    private function fresh(PrintJob $job): PrintJob
    {
        return $job->fresh([
            'consumptions.roll', 'operator', 'machine', 'stopRequestedBy:id,name',
            'stopAcknowledgedBy:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name',
        ]);
    }
}
