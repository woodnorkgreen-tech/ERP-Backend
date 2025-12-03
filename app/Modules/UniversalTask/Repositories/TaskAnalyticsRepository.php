<?php

namespace App\Modules\UniversalTask\Repositories;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class TaskAnalyticsRepository
{
    /**
     * Get task counts grouped by status.
     *
     * @param array $filters
     * @return array
     */
    public function getStatusCounts(array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get task counts grouped by priority.
     *
     * @param array $filters
     * @return array
     */
    public function getPriorityCounts(array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->select('priority', DB::raw('COUNT(*) as count'))
            ->whereNotNull('priority')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();
    }

    /**
     * Get task counts grouped by department.
     *
     * @param array $filters
     * @return array
     */
    public function getDepartmentCounts(array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->join('departments', 'tasks.department_id', '=', 'departments.id')
            ->select('departments.name', DB::raw('COUNT(*) as count'))
            ->groupBy('departments.id', 'departments.name')
            ->pluck('count', 'name')
            ->toArray();
    }

    /**
     * Get completion rate for tasks.
     *
     * @param array $filters
     * @return float
     */
    public function getCompletionRate(array $filters = []): float
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();

        return $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;
    }

    /**
     * Get average completion time in hours.
     *
     * @param array $filters
     * @return float
     */
    public function getAverageCompletionTime(array $filters = []): float
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        $result = $query->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at)) as avg_hours'))
            ->first();

        return round((float) ($result->avg_hours ?? 0), 2);
    }

    /**
     * Get overdue percentage.
     *
     * @param array $filters
     * @return float
     */
    public function getOverduePercentage(array $filters = []): float
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $overdue = (clone $query)->overdue()->count();

        return $total > 0 ? round(($overdue / $total) * 100, 2) : 0.0;
    }

    /**
     * Get time series data for task creation.
     *
     * @param string $period
     * @param int $days
     * @param array $filters
     * @return array
     */
    public function getCreationTimeSeries(string $period, int $days, array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        $dateFormat = $this->getDateFormatForPeriod($period);
        $groupBy = $this->getGroupByForPeriod($period);

        return $query->select(DB::raw("{$groupBy} as period"), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('count', 'period')
            ->toArray();
    }

    /**
     * Get time series data for task completion.
     *
     * @param string $period
     * @param int $days
     * @param array $filters
     * @return array
     */
    public function getCompletionTimeSeries(string $period, int $days, array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        $groupBy = $this->getGroupByForPeriod($period);

        return $query->select(DB::raw("{$groupBy} as period"), DB::raw('COUNT(*) as count'))
            ->where('completed_at', '>=', now()->subDays($days))
            ->whereNotNull('completed_at')
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('count', 'period')
            ->toArray();
    }

    /**
     * Get department analytics with optimized query.
     *
     * @param array $filters
     * @return \Illuminate\Support\Collection
     */
    public function getDepartmentAnalytics(array $filters = []): \Illuminate\Support\Collection
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->join('departments', 'tasks.department_id', '=', 'departments.id')
            ->select(
                'departments.id',
                'departments.name',
                DB::raw('COUNT(*) as total_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "completed" THEN 1 ELSE 0 END) as completed_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "in_progress" THEN 1 ELSE 0 END) as in_progress_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "overdue" THEN 1 ELSE 0 END) as overdue_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "blocked" THEN 1 ELSE 0 END) as blocked_tasks'),
                DB::raw('AVG(CASE WHEN tasks.completed_at IS NOT NULL AND tasks.started_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, tasks.started_at, tasks.completed_at) ELSE NULL END) as avg_completion_time'),
                DB::raw('ROUND(AVG(CASE WHEN tasks.status = "completed" THEN 1 ELSE 0 END) * 100, 2) as completion_rate')
            )
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('total_tasks', 'desc')
            ->get();
    }

    /**
     * Get user workload analytics.
     *
     * @param array $filters
     * @return array
     */
    public function getUserWorkloadAnalytics(array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->join('users', 'tasks.assigned_user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                DB::raw('COUNT(*) as total_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "completed" THEN 1 ELSE 0 END) as completed_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "in_progress" THEN 1 ELSE 0 END) as in_progress_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "overdue" THEN 1 ELSE 0 END) as overdue_tasks'),
                DB::raw('SUM(CASE WHEN tasks.status = "pending" THEN 1 ELSE 0 END) as pending_tasks')
            )
            ->whereNotNull('tasks.assigned_user_id')
            ->groupBy('users.id', 'users.name')
            ->orderBy('total_tasks', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get task type distribution.
     *
     * @param array $filters
     * @return array
     */
    public function getTaskTypeDistribution(array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->select('task_type', DB::raw('COUNT(*) as count'))
            ->whereNotNull('task_type')
            ->groupBy('task_type')
            ->pluck('count', 'task_type')
            ->toArray();
    }

    /**
     * Get tasks created in the last N days grouped by day.
     *
     * @param int $days
     * @param array $filters
     * @return array
     */
    public function getRecentActivity(int $days = 7, array $filters = []): array
    {
        $query = Task::query();
        $this->applyFilters($query, $filters);

        return $query->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Get cached result for a query.
     *
     * @param string $key
     * @param \Closure $callback
     * @param int $ttl
     * @return mixed
     */
    protected function getCachedResult(string $key, \Closure $callback, int $ttl = 900)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Apply filters to query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (isset($filters['task_type'])) {
            $query->where('task_type', $filters['task_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['assigned_user_id'])) {
            $query->where('assigned_user_id', $filters['assigned_user_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['due_date_from'])) {
            $query->where('due_date', '>=', $filters['due_date_from']);
        }

        if (isset($filters['due_date_to'])) {
            $query->where('due_date', '<=', $filters['due_date_to']);
        }
    }

    /**
     * Get date format for period.
     *
     * @param string $period
     * @return string
     */
    protected function getDateFormatForPeriod(string $period): string
    {
        return match ($period) {
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };
    }

    /**
     * Get group by clause for period.
     *
     * @param string $period
     * @return string
     */
    protected function getGroupByForPeriod(string $period): string
    {
        return match ($period) {
            'week' => 'YEARWEEK(created_at, 1)',
            'month' => 'DATE_FORMAT(created_at, "%Y-%m")',
            default => 'DATE(created_at)',
        };
    }
}