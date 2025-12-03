<?php

namespace App\Modules\UniversalTask\Observers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskHistory;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     * 
     * Log the task creation in the history.
     */
    public function created(Task $task): void
    {
        TaskHistory::logCreation($task, $task->created_by);
    }

    /**
     * Handle the Task "updated" event.
     * 
     * Log all changed attributes and update parent completion if needed.
     */
    public function updated(Task $task): void
    {
        // Log all changed attributes
        $changes = $task->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps and internal fields
            if (in_array($field, ['updated_at', 'completion_percentage'])) {
                continue;
            }

            $oldValue = $task->getOriginal($field);
            
            // Special handling for status changes
            if ($field === 'status') {
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'action' => 'status_changed',
                    'field_name' => 'status',
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ]);
            } else {
                TaskHistory::logUpdate($task, $field, $oldValue, $newValue);
            }
        }

        // Update parent completion percentage if status changed
        if ($task->isDirty('status') && $task->parent_task_id) {
            $this->updateParentCompletion($task->parentTask);
        }
    }

    /**
     * Handle the Task "deleted" event.
     * 
     * Log the deletion and update parent completion if needed.
     */
    public function deleted(Task $task): void
    {
        TaskHistory::logDeletion($task);

        if ($task->parent_task_id && $task->parentTask) {
            $this->updateParentCompletion($task->parentTask);
        }
    }

    /**
     * Handle the Task "restored" event.
     * 
     * Log the restoration and update parent completion if needed.
     */
    public function restored(Task $task): void
    {
        TaskHistory::logRestoration($task);

        if ($task->parent_task_id && $task->parentTask) {
            $this->updateParentCompletion($task->parentTask);
        }
    }

    /**
     * Update the completion percentage of a parent task.
     */
    protected function updateParentCompletion(Task $parentTask): void
    {
        $newPercentage = $parentTask->calculateCompletionPercentage();
        
        // Only update if the percentage has changed to avoid infinite loops
        if ($parentTask->completion_percentage != $newPercentage) {
            $parentTask->completion_percentage = $newPercentage;
            $parentTask->saveQuietly(); // Use saveQuietly to prevent triggering events
            
            // Recursively update grandparent if exists
            if ($parentTask->parent_task_id && $parentTask->parentTask) {
                $this->updateParentCompletion($parentTask->parentTask);
            }
        }
    }
}
