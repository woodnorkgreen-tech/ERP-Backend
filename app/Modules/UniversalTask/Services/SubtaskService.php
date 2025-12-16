<?php

namespace App\Modules\UniversalTask\Services;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubtaskService
{
    /**
     * Create a subtask for a parent task.
     *
     * @param Task $parentTask The parent task
     * @param array $subtaskData Subtask data
     * @param User $user User creating the subtask
     * @return Task The created subtask
     */
    public function createSubtask(Task $parentTask, array $subtaskData, User $user): Task
    {
        // Ensure parent_task_id is set
        $subtaskData['parent_task_id'] = $parentTask->id;

        // Inherit department and taskable from parent if not specified
        $subtaskData['department_id'] = $subtaskData['department_id'] ?? $parentTask->department_id;
        $subtaskData['taskable_type'] = $subtaskData['taskable_type'] ?? $parentTask->taskable_type;
        $subtaskData['taskable_id'] = $subtaskData['taskable_id'] ?? $parentTask->taskable_id;

        // Inherit priority from parent if not specified
        $subtaskData['priority'] = $subtaskData['priority'] ?? $parentTask->priority;

        // Use TaskService to create the subtask
        $taskService = app(TaskService::class);
        $subtask = $taskService->createTask($subtaskData, $user);

        // Update parent completion percentage
        $this->updateParentCompletionPercentage($parentTask);

        Log::info('Subtask created successfully', [
            'parent_task_id' => $parentTask->id,
            'subtask_id' => $subtask->id,
            'created_by' => $user->id,
        ]);

        return $subtask;
    }

    /**
     * Get all ancestors of a task (parent, grandparent, etc.).
     *
     * @param Task $task
     * @return array Array of ancestor Task models
     */
    public function getAncestors(Task $task): array
    {
        return $task->getAncestors();
    }

    /**
     * Get all descendants of a task (children, grandchildren, etc.).
     *
     * @param Task $task
     * @return array Array of descendant Task models
     */
    public function getDescendants(Task $task): array
    {
        return $task->getDescendants();
    }

    /**
     * Get the full hierarchy tree for a task.
     *
     * @param Task $task
     * @return array Hierarchical array representation
     */
    public function getHierarchyTree(Task $task): array
    {
        return $this->buildHierarchyTree($task);
    }

    /**
     * Move a subtask to a different parent.
     *
     * @param Task $subtask The subtask to move
     * @param Task|null $newParent The new parent task (null for root level)
     * @param int $userId User performing the move
     * @return Task The moved subtask
     * @throws \InvalidArgumentException If move would create circular relationship
     */
    public function moveSubtask(Task $subtask, ?Task $newParent, int $userId): Task
    {
        // Validate the move
        if ($newParent && !$subtask->validateParentTask($newParent->id)) {
            throw new \InvalidArgumentException('Cannot move subtask: circular relationship detected');
        }

        $oldParentId = $subtask->parent_task_id;

        DB::beginTransaction();

        try {
            // Update parent
            $subtask->parent_task_id = $newParent ? $newParent->id : null;
            $subtask->save();

            // Update completion percentages
            if ($oldParentId) {
                $oldParent = Task::find($oldParentId);
                if ($oldParent) {
                    $this->updateParentCompletionPercentage($oldParent);
                }
            }

            if ($newParent) {
                $this->updateParentCompletionPercentage($newParent);
            }

            // Record in history
            $taskService = app(TaskService::class);
            $taskService->recordTaskHistory($subtask, [
                'parent_task_id' => ['old' => $oldParentId, 'new' => $subtask->parent_task_id],
            ], $userId, 'moved');

            DB::commit();

            Log::info('Subtask moved successfully', [
                'subtask_id' => $subtask->id,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $subtask->parent_task_id,
                'moved_by' => $userId,
            ]);

            return $subtask;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Propagate completion status up the hierarchy.
     * If all subtasks are completed, mark parent as eligible for completion.
     *
     * @param Task $task The task whose subtasks may have changed
     */
    public function propagateCompletionUp(Task $task): void
    {
        if (!$task->parent_task_id) {
            return; // Root task
        }

        $parent = $task->parentTask;
        if (!$parent) {
            return;
        }

        $allSubtasksCompleted = $parent->subtasks->every(function ($subtask) {
            return $subtask->status === 'completed';
        });

        if ($allSubtasksCompleted && $parent->status !== 'completed') {
            // Mark parent as completed automatically
            $taskService = app(TaskService::class);
            $taskService->updateStatus($parent, 'completed', 1); // System user ID

            Log::info('Parent task auto-completed due to all subtasks completed', [
                'parent_task_id' => $parent->id,
                'completed_by_system' => true,
            ]);
        }

        // Recursively propagate up
        $this->propagateCompletionUp($parent);
    }

    /**
     * Get tasks that would be affected by a status change (dependencies, subtasks, etc.).
     *
     * @param Task $task
     * @param string $newStatus
     * @return array Array of affected tasks grouped by relationship type
     */
    public function getAffectedTasks(Task $task, string $newStatus): array
    {
        $affected = [
            'dependent_tasks' => [],
            'parent_tasks' => [],
            'subtasks' => [],
        ];

        // Tasks that depend on this task
        if ($newStatus === 'completed') {
            $dependentTasks = Task::whereHas('dependencies', function ($query) use ($task) {
                $query->where('depends_on_task_id', $task->id)
                      ->whereIn('dependency_type', ['blocks', 'blocked_by']);
            })->get();

            $affected['dependent_tasks'] = $dependentTasks;
        }

        // Parent tasks (for completion percentage updates)
        if ($task->parent_task_id) {
            $affected['parent_tasks'] = [$task->parentTask];
        }

        // Subtasks (if parent status changes)
        if ($newStatus === 'completed' || $newStatus === 'cancelled') {
            $affected['subtasks'] = $task->subtasks;
        }

        return $affected;
    }

    /**
     * Update parent task's completion percentage.
     *
     * @param Task $parentTask
     */
    protected function updateParentCompletionPercentage(Task $parentTask): void
    {
        $parentTask->completion_percentage = $parentTask->calculateCompletionPercentage();
        $parentTask->save();
    }

    /**
     * Build a hierarchical tree representation of the task.
     *
     * @param Task $task
     * @return array
     */
    protected function buildHierarchyTree(Task $task): array
    {
        $tree = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'completion_percentage' => $task->completion_percentage,
            'assigned_user' => $task->assignedUser?->name,
            'due_date' => $task->due_date?->toDateString(),
            'subtasks' => [],
        ];

        foreach ($task->subtasks as $subtask) {
            $tree['subtasks'][] = $this->buildHierarchyTree($subtask);
        }

        return $tree;
    }
}