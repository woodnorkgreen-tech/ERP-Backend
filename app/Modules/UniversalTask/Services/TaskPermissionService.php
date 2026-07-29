<?php

namespace App\Modules\UniversalTask\Services;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\HR\Models\Department;
use App\Constants\Permissions;
use Illuminate\Support\Facades\Log;

class TaskPermissionService
{
    /**
     * Check if a user has full, unscoped access (sees/manages every task).
     *
     * @param User $user
     * @return bool
     */
    public function hasFullAccess(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR']);
    }

    /**
     * Check if a user is directly involved with a task (creator, assignee,
     * or a member of its multi-assignee list).
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    public function isOwnTask(User $user, Task $task): bool
    {
        if ($task->created_by === $user->id) {
            return true;
        }

        if ($task->assigned_user_id === $user->id) {
            return true;
        }

        return $task->assignments()->where('user_id', $user->id)->exists();
    }

    /**
     * Check if a task's department falls under a user's lead access.
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    public function isLeadOfTaskDepartment(User $user, Task $task): bool
    {
        if (!$task->department_id || !$user->isDeptLead()) {
            return false;
        }

        return $user->getAccessibleDepartments()->pluck('id')->contains($task->department_id);
    }

    /**
     * Apply visibility scope to a task query:
     * HR/Admin/Super Admin see everything; department members see tasks in
     * their accessible departments; everyone also keeps access to tasks they
     * created or are assigned to.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyVisibilityScope($query, User $user)
    {
        if ($this->hasFullAccess($user)) {
            return $query;
        }

        $departmentIds = $user->getAccessibleDepartments()->pluck('id');

        return $query->where(function ($q) use ($user, $departmentIds) {
            if ($departmentIds->isNotEmpty()) {
                $q->whereIn('department_id', $departmentIds);
            }

            $q->orWhere('created_by', $user->id)
                ->orWhere('assigned_user_id', $user->id)
                ->orWhereHas('assignments', function ($assignmentQuery) use ($user) {
                    $assignmentQuery->where('user_id', $user->id);
                });
        });
    }

    /**
     * Check if user can view a task.
     *
     * @param User $user
     * @param Task|null $task
     * @return bool
     */
    public function canView(User $user, ?Task $task = null): bool
    {
        // If no specific task, any authenticated user can view the task list
        // (results are scoped by applyVisibilityScope()).
        if (!$task) {
            return true;
        }

        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        if ($this->hasDepartmentAccess($user, $task)) {
            return true;
        }

        return $this->isOwnTask($user, $task);
    }

    /**
     * Check if user can archive or restore a task. Archive visibility follows
     * department access, while active-task/status safeguards live in the
     * controller.
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    public function canArchive(User $user, Task $task): bool
    {
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        if ($this->hasDepartmentAccess($user, $task)) {
            return true;
        }

        return $this->isOwnTask($user, $task);
    }

    /**
     * Check if user can create tasks. Creation is open to any
     * authenticated user - that's the point of a universal tracker.
     *
     * @param User $user
     * @param array $taskData
     * @return bool
     */
    public function canCreate(User $user, array $taskData = []): bool
    {
        return true;
    }

    /**
     * Check if user can edit a task.
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    public function canEdit(User $user, Task $task): bool
    {
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        // Creator or assignee can edit their own task
        return $this->isOwnTask($user, $task);
    }

    /**
     * Check if user can delete a task.
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    public function canDelete(User $user, Task $task): bool
    {
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        // Regular users may only delete tasks they created themselves
        return $task->created_by === $user->id;
    }

    /**
     * Check if user can assign tasks.
     *
     * @param User $user
     * @param Task $task
     * @param User $assignee
     * @return bool
     */
    public function canAssign(User $user, Task $task, User $assignee): bool
    {
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        // Creator or current assignee can (re)assign their own task
        return $this->isOwnTask($user, $task);
    }

    /**
     * Check if user can change task status.
     *
     * @param User $user
     * @param Task $task
     * @param string $newStatus
     * @return bool
     */
    public function canChangeStatus(User $user, Task $task, string $newStatus): bool
    {
        if ($this->hasFullAccess($user)) {
            return true;
        }

        if ($this->isLeadOfTaskDepartment($user, $task)) {
            return true;
        }

        // Creator or assignee can move their own task between any status
        return $this->isOwnTask($user, $task);
    }

    /**
     * Check if user can manage templates.
     *
     * @param User $user
     * @return bool
     */
    public function canManageTemplates(User $user): bool
    {
        return $user->can('task.template.manage');
    }

    /**
     * Check if user has access to a specific department.
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    protected function hasDepartmentAccess(User $user, Task $task): bool
    {
        return $this->hasDepartmentAccessById($user, $task->department_id);
    }

    /**
     * Check if user has access to a department by ID.
     *
     * @param User $user
     * @param int|null $departmentId
     * @return bool
     */
    protected function hasDepartmentAccessById(User $user, ?int $departmentId): bool
    {
        if (!$departmentId) {
            return true; // No department restriction
        }

        // User has department access permission
        if ($user->can(Permissions::DEPARTMENT_ACCESS)) {
            return true;
        }

        // Check if user belongs to the department
        if ($user->department_id === $departmentId) {
            return true;
        }

        // Check if user has access to multiple departments (future enhancement)
        // This would check a user_department_access pivot table

        return false;
    }

    /**
     * Check if user is a department manager.
     *
     * @param User $user
     * @param int|null $departmentId
     * @return bool
     */
    protected function isDepartmentManager(User $user, ?int $departmentId): bool
    {
        if (!$departmentId) {
            return false;
        }

        // User has department manage permission
        if ($user->can(Permissions::DEPARTMENT_MANAGE)) {
            return true;
        }

        // Check if user is designated as manager for this department
        // This would check a department_managers table or role-based logic

        return false;
    }

    /**
     * Log permission denial for auditing.
     *
     * @param User $user
     * @param string $action
     * @param Task|null $task
     * @param array $context
     */
    public function logPermissionDenial(User $user, string $action, ?Task $task = null, array $context = []): void
    {
        Log::warning('Task permission denied', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'action' => $action,
            'task_id' => $task?->id,
            'task_title' => $task?->title,
            'department_id' => $task?->department_id,
            'context' => $context,
            'timestamp' => now(),
        ]);
    }

    /**
     * Get all departments a user has access to.
     *
     * @param User $user
     * @return array
     */
    public function getAccessibleDepartments(User $user): array
    {
        // If user has global department access
        if ($user->can(Permissions::DEPARTMENT_ACCESS)) {
            // Return all department IDs
            return Department::pluck('id')->toArray();
        }

        // User's own department
        $departments = [$user->department_id];

        // Additional departments (future enhancement)
        // Check user_department_access pivot table

        return array_filter($departments);
    }

    /**
     * Filter tasks based on user's permissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyPermissionFilters($query, User $user)
    {
        // Filter by accessible departments
        $accessibleDepartments = $this->getAccessibleDepartments($user);
        if (!empty($accessibleDepartments)) {
            $query->whereIn('department_id', $accessibleDepartments);
        }

        // Additional permission-based filters can be added here

        return $query;
    }
}
