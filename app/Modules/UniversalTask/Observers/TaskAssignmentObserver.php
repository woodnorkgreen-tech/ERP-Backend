<?php

namespace App\Modules\UniversalTask\Observers;

use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Models\TaskHistory;

class TaskAssignmentObserver
{
    /**
     * Handle the TaskAssignment "created" event.
     * 
     * Log the assignment creation in task history.
     */
    public function created(TaskAssignment $assignment): void
    {
        $this->logAssignmentChange($assignment, 'assignment_created');
    }

    /**
     * Handle the TaskAssignment "updated" event.
     * 
     * Log the assignment update in task history.
     */
    public function updated(TaskAssignment $assignment): void
    {
        $this->logAssignmentChange($assignment, 'assignment_updated');
    }

    /**
     * Handle the TaskAssignment "deleted" event.
     * 
     * Log the assignment deletion in task history.
     */
    public function deleted(TaskAssignment $assignment): void
    {
        $this->logAssignmentChange($assignment, 'assignment_deleted');
    }

    /**
     * Log assignment changes to task history.
     * 
     * @param TaskAssignment $assignment The assignment being changed
     * @param string $action The action being performed
     */
    protected function logAssignmentChange(TaskAssignment $assignment, string $action): void
    {
        // Get the authenticated user or use the assigned_by user
        $userId = auth()->id() ?? $assignment->assigned_by;

        TaskHistory::create([
            'task_id' => $assignment->task_id,
            'user_id' => $userId,
            'action' => $action,
            'field_name' => 'assignment',
            'old_value' => $this->getOldAssignmentValue($assignment, $action),
            'new_value' => $this->getNewAssignmentValue($assignment, $action),
        ]);
    }

    /**
     * Get the old assignment value for history logging.
     * 
     * @param TaskAssignment $assignment
     * @param string $action
     * @return string|null
     */
    protected function getOldAssignmentValue(TaskAssignment $assignment, string $action): ?string
    {
        if ($action === 'assignment_created') {
            return null;
        }

        if ($action === 'assignment_deleted') {
            return json_encode([
                'user_id' => $assignment->user_id,
                'role' => $assignment->role,
                'is_primary' => $assignment->is_primary,
            ]);
        }

        // For updates, get the original values
        $original = $assignment->getOriginal();
        return json_encode([
            'user_id' => $original['user_id'] ?? $assignment->user_id,
            'role' => $original['role'] ?? $assignment->role,
            'is_primary' => $original['is_primary'] ?? $assignment->is_primary,
        ]);
    }

    /**
     * Get the new assignment value for history logging.
     * 
     * @param TaskAssignment $assignment
     * @param string $action
     * @return string|null
     */
    protected function getNewAssignmentValue(TaskAssignment $assignment, string $action): ?string
    {
        if ($action === 'assignment_deleted') {
            return null;
        }

        return json_encode([
            'user_id' => $assignment->user_id,
            'role' => $assignment->role,
            'is_primary' => $assignment->is_primary,
            'assigned_by' => $assignment->assigned_by,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
        ]);
    }
}
