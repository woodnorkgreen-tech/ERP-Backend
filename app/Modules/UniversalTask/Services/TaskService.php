<?php

namespace App\Modules\UniversalTask\Services;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Models\TaskHistory;
use App\Modules\UniversalTask\Models\Contexts\LogisticsTaskContext;
use App\Modules\UniversalTask\Models\Contexts\DesignTaskContext;
use App\Modules\UniversalTask\Models\Contexts\FinanceTaskContext;
use App\Modules\UniversalTask\Repositories\TaskRepository;
use App\Modules\UniversalTask\Events\TaskAssigned;
use App\Modules\UniversalTask\Events\TaskStatusChanged;
use App\Modules\UniversalTask\Events\TaskCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Event;

class TaskService
{
    protected TaskRepository $taskRepository;

    public function __construct(TaskRepository $taskRepository)
    {
        $this->taskRepository = $taskRepository;
    }

    /**
     * Create a new task with validation and context handling.
     *
     * @param array $data Task data
     * @param User $user User creating the task
     * @return Task The created task
     * @throws \Illuminate\Validation\ValidationException
     */
    public function createTask(array $data, User $user): Task
    {
        // Validate task data
        $validatedData = $this->validateTaskData($data, 'create');

        // Add creator
        $validatedData['created_by'] = $user->id;

        // Set default status if not provided
        $validatedData['status'] = $validatedData['status'] ?? 'pending';

        // Set default department if not provided (use user's department or first available)
        if (!isset($validatedData['department_id']) || empty($validatedData['department_id'])) {
            $validatedData['department_id'] = $user->department_id ?? 1; // Default to department 1 if user has no department
        }

        // Auto-assign to creator if no assignee specified
        if (!isset($validatedData['assigned_user_id']) || empty($validatedData['assigned_user_id'])) {
            $validatedData['assigned_user_id'] = $user->id;
        }

        // Set default due date (7 days from now) if not provided
        if (!isset($validatedData['due_date']) || empty($validatedData['due_date'])) {
            $validatedData['due_date'] = now()->addDays(7)->toDateString();
        }

        DB::beginTransaction();

        try {
            // Create the task
            $task = Task::create($validatedData);

            // Handle context data if provided
            if (isset($data['context'])) {
                $this->createTaskContext($task, $data['context']);
            }

            DB::commit();

            // Clear cache for the new task
            $this->taskRepository->clearTaskCache($task);

            // If this is a subtask, clear cache for the parent task too
            if ($task->parent_task_id) {
                $this->taskRepository->clearAllTaskCaches();
            }

            Log::info('Task created successfully', [
                'task_id' => $task->id,
                'title' => $task->title,
                'created_by' => $user->id,
                'assigned_to' => $task->assigned_user_id,
                'due_date' => $task->due_date,
            ]);

            return $task;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Task creation failed', [
                'data' => $data,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update an existing task with history tracking.
     *
     * @param Task $task The task to update
     * @param array $data Updated task data
     * @param int $userId User making the update
     * @return Task The updated task
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateTask(Task $task, array $data, int $userId): Task
    {
        // Validate task data
        $validatedData = $this->validateTaskData($data, 'update', $task->id);

        DB::beginTransaction();

        try {
            // Track changes for history
            $changes = $this->getTaskChanges($task, $validatedData);

            // Store old parent for cache clearing
            $oldParentId = $task->parent_task_id;
            $newParentId = $validatedData['parent_task_id'] ?? null;

            // Update the task
            $task->update($validatedData);

            // Handle context data if provided
            if (isset($data['context'])) {
                $this->updateTaskContext($task, $data['context']);
            }

            // Record history if there were changes
            if (!empty($changes)) {
                $this->recordTaskHistory($task, $changes, $userId);
            }

            DB::commit();

            // Clear cache for the updated task
            $this->taskRepository->clearTaskCache($task);

            // Clear all caches if parent changed
            if ($oldParentId !== $newParentId) {
                $this->taskRepository->clearAllTaskCaches();
            }

            Log::info('Task updated successfully', [
                'task_id' => $task->id,
                'changes' => $changes,
                'updated_by' => $userId,
            ]);

            return $task;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Task update failed', [
                'task_id' => $task->id,
                'data' => $data,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a task with cascade handling.
     *
     * @param Task $task The task to delete
     * @param int $userId User deleting the task
     * @return bool True if deleted successfully
     */
    public function deleteTask(Task $task, int $userId): bool
    {
        DB::beginTransaction();

        try {
            // Record deletion in history BEFORE deleting the task
            $this->recordTaskHistory($task, ['deleted' => true], $userId, 'deleted');

            // Soft delete the task (cascade will handle relationships due to foreign keys)
            $task->delete();

            DB::commit();

            // Clear cache for the deleted task
            $this->taskRepository->clearTaskCache($task);

            // If this was a subtask, clear all caches
            if ($task->parent_task_id) {
                $this->taskRepository->clearAllTaskCaches();
            }

            Log::info('Task deleted successfully', [
                'task_id' => $task->id,
                'deleted_by' => $userId,
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Task deletion failed', [
                'task_id' => $task->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Assign a task to users with notification triggering.
     *
     * @param Task $task The task to assign
     * @param array $assignmentData Assignment data (user_ids, role, etc.)
     * @param int $assignerId User making the assignment
     * @return Task The updated task
     */
    public function assignTask(Task $task, array $assignmentData, int $assignerId): Task
    {
        $userIds = $assignmentData['user_ids'] ?? [];
        $role = $assignmentData['role'] ?? null;

        DB::beginTransaction();

        try {
            // Remove existing assignments if replacing
            if ($assignmentData['replace_existing'] ?? false) {
                $task->assignments()->delete();
            }

            // Create new assignments
            $assignments = [];
            foreach ($userIds as $index => $userId) {
                $assignments[] = TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $userId,
                    'assigned_by' => $assignerId,
                    'assigned_at' => now(),
                    'role' => $role,
                    'is_primary' => $index === 0, // First user is primary
                ]);
            }

            // Update task's assigned_user_id to primary assignee
            if (!empty($userIds)) {
                $task->assigned_user_id = $userIds[0];
                $task->save();
            }

            // Record assignment in history
            $this->recordTaskHistory($task, [
                'assigned_users' => $userIds,
                'assignment_role' => $role,
            ], $assignerId, 'assigned');

            DB::commit();

            // Trigger notification event
            Event::dispatch(new TaskAssigned($task, $assignments, $assignerId));

            // Clear cache for the assigned task
            $this->taskRepository->clearTaskCache($task);

            Log::info('Task assigned successfully', [
                'task_id' => $task->id,
                'assigned_users' => $userIds,
                'assigned_by' => $assignerId,
            ]);

            return $task;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Task assignment failed', [
                'task_id' => $task->id,
                'assignment_data' => $assignmentData,
                'assigner_id' => $assignerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update task status with dependency checking and automatic overdue marking.
     *
     * @param Task $task The task to update
     * @param string $newStatus The new status
     * @param int $userId User making the change
     * @param string|null $notes Optional notes for the status change
     * @return Task The updated task
     * @throws \InvalidArgumentException If status transition is not allowed
     */
    public function updateStatus(Task $task, string $newStatus, int $userId, ?string $notes = null): Task
    {
        // Validate status transition
        if (!$task->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition task {$task->id} from '{$task->status}' to '{$newStatus}'"
            );
        }

        $oldStatus = $task->status;

        DB::beginTransaction();

        try {
            // Update status and related timestamps
            $updateData = ['status' => $newStatus];

            if ($newStatus === 'in_progress' && !$task->started_at) {
                $updateData['started_at'] = now();
            } elseif (in_array($newStatus, ['completed', 'cancelled']) && !$task->completed_at) {
                $updateData['completed_at'] = now();
            }

            $task->update($updateData);

            // Record status change in history
            $this->recordTaskHistory($task, [
                'status' => ['old' => $oldStatus, 'new' => $newStatus],
                'notes' => $notes,
            ], $userId, 'status_changed');

            // Handle automatic overdue marking
            if ($newStatus === 'overdue') {
                $this->markTaskOverdue($task);
            }

            // Update parent completion percentage if this is a subtask
            if ($task->parent_task_id) {
                $this->updateParentCompletionPercentage($task->parentTask);
            }

            DB::commit();

            // Trigger notification events
            Event::dispatch(new TaskStatusChanged($task, $oldStatus, $newStatus, $userId));

            if ($newStatus === 'completed') {
                Event::dispatch(new TaskCompleted($task, $userId));
            }

            // Clear cache for the status-updated task
            $this->taskRepository->clearTaskCache($task);

            Log::info('Task status updated successfully', [
                'task_id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'updated_by' => $userId,
            ]);

            return $task;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Task status update failed', [
                'task_id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate task data based on operation type.
     *
     * @param array $data
     * @param string $operation ('create' or 'update')
     * @param int|null $taskId Task ID for update validation
     * @return array Validated data
     */
    protected function validateTaskData(array $data, string $operation, ?int $taskId = null): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'task_type' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled', 'overdue'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'parent_task_id' => 'nullable|exists:tasks,id',
            'taskable_type' => 'nullable|string|max:255',
            'taskable_id' => 'nullable|integer',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'actual_hours' => 'nullable|numeric|min:0',
            'due_date' => 'required|date|after_or_equal:today', // Make due_date required
            'blocked_reason' => 'nullable|string',
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];

        // For updates, make fields optional except for required ones
        if ($operation === 'update') {
            foreach ($rules as $field => $rule) {
                if (!isset($data[$field])) {
                    continue;
                }
                // Keep required fields required for updates if provided
            }
        }

        return Validator::make($data, $rules)->validate();
    }

    /**
     * Create task context based on task type.
     *
     * @param Task $task
     * @param array $contextData
     */
    protected function createTaskContext(Task $task, array $contextData): void
    {
        $contextModel = $this->getContextModelForTaskType($task->task_type);

        if ($contextModel) {
            $contextModel::create(array_merge($contextData, ['task_id' => $task->id]));
        }
    }

    /**
     * Update task context.
     *
     * @param Task $task
     * @param array $contextData
     */
    protected function updateTaskContext(Task $task, array $contextData): void
    {
        $context = $this->getTaskContext($task);

        if ($context) {
            $context->update($contextData);
        } else {
            $this->createTaskContext($task, $contextData);
        }
    }

    /**
     * Get context model class for task type.
     *
     * @param string|null $taskType
     * @return string|null
     */
    protected function getContextModelForTaskType(?string $taskType): ?string
    {
        return match ($taskType) {
            'logistics' => LogisticsTaskContext::class,
            'design' => DesignTaskContext::class,
            'finance' => FinanceTaskContext::class,
            default => null,
        };
    }

    /**
     * Get the context for a task.
     *
     * @param Task $task
     * @return Model|null
     */
    protected function getTaskContext(Task $task)
    {
        return match ($task->task_type) {
            'logistics' => $task->logisticsContext,
            'design' => $task->designContext,
            'finance' => $task->financeContext,
            default => null,
        };
    }

    /**
     * Get changes between old and new task data.
     *
     * @param Task $task
     * @param array $newData
     * @return array
     */
    protected function getTaskChanges(Task $task, array $newData): array
    {
        $changes = [];

        foreach ($newData as $field => $value) {
            if ($task->$field != $value) {
                $changes[$field] = [
                    'old' => $task->$field,
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    /**
     * Record task history.
     *
     * @param Task $task
     * @param array $changes
     * @param int $userId
     * @param string $action
     */
    protected function recordTaskHistory(Task $task, array $changes, int $userId, string $action = 'updated'): void
    {
        TaskHistory::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'action' => $action,
            'metadata' => $changes,
        ]);
    }

    /**
     * Mark a task as overdue.
     *
     * @param Task $task
     */
    protected function markTaskOverdue(Task $task): void
    {
        // This is handled by the status update, but we can add additional logic here
        // like sending overdue notifications
    }

    /**
     * Update parent task's completion percentage.
     *
     * @param Task $parentTask
     */
    protected function updateParentCompletionPercentage(Task $parentTask): void
    {
        if ($parentTask) {
            $parentTask->completion_percentage = $parentTask->calculateCompletionPercentage();
            $parentTask->save();
        }
    }
}