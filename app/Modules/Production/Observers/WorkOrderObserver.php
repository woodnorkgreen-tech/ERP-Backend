<?php

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use App\Modules\Notifications\Services\NotificationService;

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

        if ($workOrder->assigned_to) {
            NotificationService::send(
                type: 'production_work_order_assigned',
                title: "Work order {$workOrder->work_order_number} assigned",
                message: $workOrder->title,
                module: 'production',
                data: ['work_order_id' => $workOrder->id, 'url' => "/production/work-orders/{$workOrder->id}"],
                users: [$workOrder->assigned_to],
            );
        }
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

        if ($workOrder->wasChanged('assigned_to') && $workOrder->assigned_to) {
            NotificationService::send(
                type: 'production_work_order_assigned',
                title: "Work order {$workOrder->work_order_number} assigned",
                message: $workOrder->title,
                module: 'production',
                data: ['work_order_id' => $workOrder->id, 'url' => "/production/work-orders/{$workOrder->id}"],
                users: [$workOrder->assigned_to],
            );
        }

        if ($workOrder->wasChanged('status')) {
            NotificationService::send(
                type: 'production_work_order_status_changed',
                title: "Work order {$workOrder->work_order_number} is {$workOrder->status}",
                message: "Status changed from {$workOrder->getOriginal('status')} to {$workOrder->status}.",
                module: 'production',
                data: ['work_order_id' => $workOrder->id, 'status' => $workOrder->status, 'url' => "/production/work-orders/{$workOrder->id}"],
                users: array_filter([$workOrder->assigned_to, $workOrder->created_by]),
            );
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
