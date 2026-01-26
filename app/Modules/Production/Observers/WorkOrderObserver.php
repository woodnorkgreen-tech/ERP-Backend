<?php

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

class WorkOrderObserver
{
    /**
     * Handle the WorkOrder "created" event.
     */
    public function created(WorkOrder $workOrder): void
    {
        Log::info('WorkOrder created', [
            'work_order_id' => $workOrder->id,
            'work_order_number' => $workOrder->work_order_number,
            'title' => $workOrder->title,
            'created_by' => auth()->id()
        ]);
    }

    /**
     * Handle the WorkOrder "updated" event.
     */
    public function updated(WorkOrder $workOrder): void
    {
        $changes = $workOrder->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps
            if (in_array($field, ['updated_at', 'created_at'])) {
                continue;
            }

            $oldValue = $workOrder->getOriginal($field);
            
            // Log important changes
            if (in_array($field, ['status', 'priority', 'due_date', 'assigned_to'])) {
                Log::info('WorkOrder updated', [
                    'work_order_id' => $workOrder->id,
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'updated_by' => auth()->id()
                ]);
            }
        }
    }

    /**
     * Handle the WorkOrder "deleted" event.
     */
    public function deleted(WorkOrder $workOrder): void
    {
        Log::info('WorkOrder deleted', [
            'work_order_id' => $workOrder->id,
            'work_order_number' => $workOrder->work_order_number,
            'deleted_by' => auth()->id()
        ]);
    }

    /**
     * Handle the WorkOrder "restored" event.
     */
    public function restored(WorkOrder $workOrder): void
    {
        Log::info('WorkOrder restored', [
            'work_order_id' => $workOrder->id,
            'restored_by' => auth()->id()
        ]);
    }
}
