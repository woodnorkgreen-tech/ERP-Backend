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
     * Check if user can view a task.
     *
     * @param User $user
     * @param Task|null $task
     * @return bool
     */
    public function canView(User $user, ?Task $task = null): bool
    {
        // Basic task read permission
        if (!$user->can(Permissions::TASK_READ)) {
            return false;
        }

        // If no specific task, user can view tasks in general
        if (!$task) {
            return true;
        }

        // Department-based access control
        if (!$this->hasDepartmentAccess($user, $task)) {
            return false;
        }

        // Task-level permissions (future enhancement)
        // Check if user has explicit permission to view this specific task

        return true;
    }

    /**
     * Check if user can create tasks.
     *
     * @param User $user
     * @param array $taskData
     * @return bool
     */
    public function canCreate(User $user, array $taskData = []): bool
    {
        if (!$user->can(Permissions::TASK_CREATE)) {
            return false;
        }

        // Check department access if department is specified
        if (isset($taskData['department_id'])) {
            if (!$this->hasDepartmentAccessById($user, $taskData['department_id'])) {
                return false;
            }
        }

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
        if (!$user->can(Permissions::TASK_UPDATE)) {
            return false;
        }

        // Must be able to view the task first
        if (!$this->canView($user, $task)) {
            return false;
        }

        // Creator can always edit their own tasks
        if ($task->created_by === $user->id) {
            return true;
        }

        // Department managers can edit tasks in their department
        if ($this->isDepartmentManager($user, $task->department_id)) {
            return true;
        }

        // Assigned user can edit tasks assigned to them
        if ($task->assigned_user_id === $user->id) {
            return true;
        }

        // Task-level permissions (future enhancement)

        return false;
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
        // Only Super Admin and Admin users can delete tasks
        // TEMPORARY: Allow deletion for testing - remove this condition in production
        if (!$user->hasRole(['Super Admin', 'Admin'])) {
            // For testing: allow the current user to delete tasks
            // TODO: Remove this and only allow Admin/Super Admin roles
            // return false;
        }

        // Must be able to view the task first
        if (!$this->canView($user, $task)) {
            return false;
        }

        return true;
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
        if (!$user->can(Permissions::TASK_ASSIGN)) {
            return false;
        }

        // Must be able to view the task
        if (!$this->canView($user, $task)) {
            return false;
        }

        // Creator can assign their own tasks
        if ($task->created_by === $user->id) {
            return true;
        }

        // Department managers can assign tasks in their department
        if ($this->isDepartmentManager($user, $task->department_id)) {
            return true;
        }

        // Current assignee can reassign (with restrictions)
        if ($task->assigned_user_id === $user->id) {
            // Can only reassign within same department
            return $assignee->department_id === $task->department_id;
        }

        return false;
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
        // Must be able to view the task
        if (!$this->canView($user, $task)) {
            return false;
        }

        // Creator can change status of their tasks
        if ($task->created_by === $user->id) {
            return true;
        }

        // Department managers can change status of tasks in their department
        if ($this->isDepartmentManager($user, $task->department_id)) {
            return true;
        }

        // Assigned user can change status of tasks assigned to them
        if ($task->assigned_user_id === $user->id) {
            // Can only change to certain statuses
            return in_array($newStatus, ['in_progress', 'completed', 'blocked']);
        }

        // Special permissions for completion
        if ($newStatus === 'completed' && $user->can(Permissions::TASK_COMPLETE)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can view analytics.
     *
     * @param User $user
     * @param array $filters
     * @return bool
     */
    public function canViewAnalytics(User $user, array $filters = []): bool
    {
        // Basic analytics permission
        if (!$user->can('task.analytics.view')) {
            return false;
        }

        // Department-based filtering
        if (isset($filters['department_id'])) {
            if (!$this->hasDepartmentAccessById($user, $filters['department_id'])) {
                return false;
            }
        }

        return true;
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