<?php

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\DailyTask;
use Illuminate\Support\Facades\Log;

class DailyTaskObserver
{
    /**
     * Handle the DailyTask "created" event.
     */
    public function created(DailyTask $task): void
    {
        Log::info('DailyTask created', [
            'task_id' => $task->id,
            'job_card_id' => $task->job_card_id,
            'description' => $task->description,
            'created_by' => auth()->id()
        ]);
    }

    /**
     * Handle the DailyTask "updated" event.
     */
    public function updated(DailyTask $task): void
    {
        $changes = $task->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps
            if (in_array($field, ['updated_at', 'created_at'])) {
                continue;
            }

            $oldValue = $task->getOriginal($field);
            
            // Log important changes
            if (in_array($field, ['description', 'start_time', 'end_time', 'hours_worked'])) {
                Log::info('DailyTask updated', [
                    'task_id' => $task->id,
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'updated_by' => auth()->id()
                ]);
            }
        }
    }

    /**
     * Handle the DailyTask "deleted" event.
     */
    public function deleted(DailyTask $task): void
    {
        Log::info('DailyTask deleted', [
            'task_id' => $task->id,
            'job_card_id' => $task->job_card_id,
            'description' => $task->description,
            'deleted_by' => auth()->id()
        ]);
    }

    /**
     * Handle the DailyTask "restored" event.
     */
    public function restored(DailyTask $task): void
    {
        Log::info('DailyTask restored', [
            'task_id' => $task->id,
            'restored_by' => auth()->id()
        ]);
    }
}
