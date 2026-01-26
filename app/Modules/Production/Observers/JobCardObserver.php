<?php

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\JobCard;
use App\Modules\Production\Models\DailyTask;
use Illuminate\Support\Facades\Log;

class JobCardObserver
{
    /**
     * Handle the JobCard "created" event.
     */
    public function created(JobCard $jobCard): void
    {
        Log::info('JobCard created', [
            'job_card_id' => $jobCard->id,
            'worker_id' => $jobCard->worker_id,
            'date' => $jobCard->date,
            'created_by' => auth()->id()
        ]);
    }

    /**
     * Handle the JobCard "updated" event.
     */
    public function updated(JobCard $jobCard): void
    {
        $changes = $jobCard->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps
            if (in_array($field, ['updated_at', 'created_at'])) {
                continue;
            }

            $oldValue = $jobCard->getOriginal($field);
            
            // Log important changes
            if ($field === 'status') {
                Log::info('JobCard status changed', [
                    'job_card_id' => $jobCard->id,
                    'old_status' => $oldValue,
                    'new_status' => $newValue,
                    'changed_by' => auth()->id()
                ]);
            }
        }
    }

    /**
     * Handle the JobCard "deleted" event.
     */
    public function deleted(JobCard $jobCard): void
    {
        Log::info('JobCard deleted', [
            'job_card_id' => $jobCard->id,
            'worker_id' => $jobCard->worker_id,
            'date' => $jobCard->date,
            'deleted_by' => auth()->id()
        ]);
    }

    /**
     * Handle the JobCard "restored" event.
     */
    public function restored(JobCard $jobCard): void
    {
        Log::info('JobCard restored', [
            'job_card_id' => $jobCard->id,
            'restored_by' => auth()->id()
        ]);
    }
}
