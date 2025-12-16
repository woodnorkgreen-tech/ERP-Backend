<?php

namespace App\Modules\UniversalTask\Repositories;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class TaskRepository
{
    /**
     * Search tasks with full-text search across title, description, and notes.
     *
     * @param Builder $query
     * @param string $searchTerm
     * @return Builder
     */
    public function applySearch(Builder $query, string $searchTerm): Builder
    {
        if (empty($searchTerm)) {
            return $query;
        }

        return $query->where(function ($q) use ($searchTerm) {
            $q->where('title', 'LIKE', "%{$searchTerm}%")
              ->orWhere('description', 'LIKE', "%{$searchTerm}%")
              ->orWhereJsonContains('metadata', ['notes' => $searchTerm]); // Assuming notes are in metadata
        });
    }

    /**
     * Apply filters to the query.
     *
     * @param Builder $query
     * @param array $filters
     * @return Builder
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        // Status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Priority filter
        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        // Department filter
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Task type filter
        if (isset($filters['task_type'])) {
            $query->where('task_type', $filters['task_type']);
        }

        // Assignee filter
        if (isset($filters['assigned_user_id'])) {
            $query->where('assigned_user_id', $filters['assigned_user_id']);
        }

        // Creator filter
        if (isset($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        // Date range filters
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        if (isset($filters['due_date_from'])) {
            $query->where('due_date', '>=', $filters['due_date_from']);
        }

        if (isset($filters['due_date_to'])) {
            $query->where('due_date', '<=', $filters['due_date_to']);
        }

        // Tags filter (assuming tags are stored as JSON)
        if (isset($filters['tags']) && is_array($filters['tags'])) {
            foreach ($filters['tags'] as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }

        // Overdue filter
        if (isset($filters['overdue']) && $filters['overdue']) {
            $query->overdue();
        }

        return $query;
    }

    /**
     * Apply sorting to the query.
     *
     * @param Builder $query
     * @param string $sortBy
     * @param string $sortDirection
     * @return Builder
     */
    public function applySorting(Builder $query, string $sortBy = 'created_at', string $sortDirection = 'desc'): Builder
    {
        $allowedSortFields = [
            'created_at', 'updated_at', 'due_date', 'priority', 'status', 'title',
            'estimated_hours', 'actual_hours', 'completion_percentage'
        ];

        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        // Handle special sorting cases
        if ($sortBy === 'priority') {
            // Custom priority sorting: urgent > high > medium > low
            $priorityOrder = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low') {$sortDirection}");
        } elseif ($sortBy === 'status') {
            // Custom status sorting: pending > in_progress > review > completed > cancelled
            $statusOrder = ['pending' => 5, 'in_progress' => 4, 'review' => 3, 'completed' => 2, 'cancelled' => 1, 'blocked' => 0, 'overdue' => -1];
            $query->orderByRaw("FIELD(status, 'pending', 'in_progress', 'review', 'completed', 'cancelled', 'blocked', 'overdue') {$sortDirection}");
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        return $query;
    }

    /**
     * Get paginated results with search, filters, and sorting applied.
     *
     * @param array $params
     * @param User|null $user For permission filtering
     * @return LengthAwarePaginator
     */
    public function getTasks(array $params = [], ?User $user = null): LengthAwarePaginator
    {
        $query = Task::with(['department', 'assignedUser.employee', 'creator', 'parentTask'])
                    ->withCount('subtasks');

        // Include both parent tasks and subtasks in main list by default
        // Only filter for specific parent_task_id when explicitly requested
        if (isset($params['parent_task_id'])) {
            $query->where('parent_task_id', $params['parent_task_id']);
        }
        // else: include all tasks (both parents and subtasks)

        // Apply search
        if (isset($params['search']) && !empty($params['search'])) {
            $query = $this->applySearch($query, $params['search']);
        }

        // Apply filters
        $filters = array_filter([
            'status' => $params['status'] ?? null,
            'priority' => $params['priority'] ?? null,
            'department_id' => $params['department_id'] ?? null,
            'task_type' => $params['task_type'] ?? null,
            'assigned_user_id' => $params['assigned_user_id'] ?? null,
            'created_by' => $params['created_by'] ?? null,
            'created_from' => $params['created_from'] ?? null,
            'created_to' => $params['created_to'] ?? null,
            'due_date_from' => $params['due_date_from'] ?? null,
            'due_date_to' => $params['due_date_to'] ?? null,
            'tags' => $params['tags'] ?? null,
            'overdue' => $params['overdue'] ?? null,
        ]);

        $query = $this->applyFilters($query, $filters);

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDirection = $params['sort_direction'] ?? 'desc';
        $query = $this->applySorting($query, $sortBy, $sortDirection);

        // Pagination
        $perPage = $params['per_page'] ?? 25;
        $perPage = min(max($perPage, 1), 100); // Limit between 1 and 100

        return $query->paginate($perPage);
    }

    /**
     * Get tasks assigned to a specific user.
     *
     * @param int $userId
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function getTasksAssignedToUser(int $userId, array $params = []): LengthAwarePaginator
    {
        $params['assigned_user_id'] = $userId;
        return $this->getTasks($params);
    }

    /**
     * Get tasks created by a specific user.
     *
     * @param int $userId
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function getTasksCreatedByUser(int $userId, array $params = []): LengthAwarePaginator
    {
        $params['created_by'] = $userId;
        return $this->getTasks($params);
    }

    /**
     * Get overdue tasks.
     *
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function getOverdueTasks(array $params = []): LengthAwarePaginator
    {
        $params['overdue'] = true;
        return $this->getTasks($params);
    }

    /**
     * Get tasks by department.
     *
     * @param int $departmentId
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function getTasksByDepartment(int $departmentId, array $params = []): LengthAwarePaginator
    {
        $params['department_id'] = $departmentId;
        return $this->getTasks($params);
    }

    /**
     * Get task statistics.
     *
     * @param array $filters
     * @return array
     */
    public function getTaskStatistics(array $filters = []): array
    {
        $query = Task::query();
        $query = $this->applyFilters($query, $filters);

        $stats = $query->selectRaw('
            COUNT(*) as total_tasks,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress_tasks,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_tasks,
            SUM(CASE WHEN status = "blocked" THEN 1 ELSE 0 END) as blocked_tasks,
            SUM(CASE WHEN status = "overdue" THEN 1 ELSE 0 END) as overdue_tasks,
            AVG(CASE WHEN completed_at IS NOT NULL AND started_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, started_at, completed_at) ELSE NULL END) as avg_completion_time,
            AVG(estimated_hours) as avg_estimated_hours
        ')->first();

        return [
            'total_tasks' => (int) $stats->total_tasks,
            'completed_tasks' => (int) $stats->completed_tasks,
            'in_progress_tasks' => (int) $stats->in_progress_tasks,
            'pending_tasks' => (int) $stats->pending_tasks,
            'blocked_tasks' => (int) $stats->blocked_tasks,
            'overdue_tasks' => (int) $stats->overdue_tasks,
            'completion_rate' => $stats->total_tasks > 0 ? round(($stats->completed_tasks / $stats->total_tasks) * 100, 2) : 0,
            'average_completion_time_hours' => round((float) $stats->avg_completion_time, 2),
            'average_estimated_hours' => round((float) $stats->avg_estimated_hours, 2),
        ];
    }

    /**
     * Get tasks with advanced search and filtering for admin purposes.
     *
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $params = []): LengthAwarePaginator
    {
        $query = Task::with(['department', 'assignedUser.employee', 'creator', 'parentTask', 'subtasks']);

        // Apply search with more fields
        if (isset($params['search']) && !empty($params['search'])) {
            $searchTerm = $params['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('task_type', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('blocked_reason', 'LIKE', "%{$searchTerm}%")
                  ->orWhereJsonContains('metadata', $searchTerm)
                  ->orWhereJsonContains('tags', $searchTerm);
            });
        }

        // Apply all filters
        $query = $this->applyFilters($query, $params);

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDirection = $params['sort_direction'] ?? 'desc';
        $query = $this->applySorting($query, $sortBy, $sortDirection);

        // Pagination
        $perPage = $params['per_page'] ?? 25;

        return $query->paginate($perPage);
    }

    /**
     * Clear cache for a specific user.
     *
     * @param int $userId
     */
    public function clearUserCache(int $userId): void
    {
        // Clear user-specific task caches
        Cache::forget("user_tasks_{$userId}_*");
        Cache::forget("tasks_*{$userId}*"); // Also clear any task caches that might include this user
    }

    /**
     * Clear cache for a specific department.
     *
     * @param int $departmentId
     */
    public function clearDepartmentCache(int $departmentId): void
    {
        // Clear department-specific caches
        Cache::forget("tasks_*{$departmentId}*");
    }

    /**
     * Clear all task-related caches.
     */
    public function clearAllTaskCaches(): void
    {
        Cache::forget('tasks_*');
        Cache::forget('user_tasks_*');
        Cache::forget('overdue_tasks_*');
    }

    /**
     * Clear cache when a task is updated.
     *
     * @param Task $task
     */
    public function clearTaskCache(Task $task): void
    {
        // Clear general task caches
        Cache::forget('tasks_*');
        Cache::forget('overdue_tasks_*');

        // Clear user-specific caches for assigned user and creator
        if ($task->assigned_user_id) {
            $this->clearUserCache($task->assigned_user_id);
        }
        if ($task->created_by) {
            $this->clearUserCache($task->created_by);
        }

        // Clear department cache
        if ($task->department_id) {
            $this->clearDepartmentCache($task->department_id);
        }
    }
}