<?php

namespace App\Modules\UniversalTask\Services;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TaskAnalyticsService
{
    /**
     * Get dashboard data with real-time metrics.
     *
     * @param array $filters Optional filters (department_id, date_range, etc.)
     * @return array Dashboard data
     */
    public function getDashboardData(array $filters = []): array
    {
        $cacheKey = 'task_dashboard_' . md5(serialize($filters));

        return Cache::remember($cacheKey, 900, function () use ($filters) { // 15 minutes
            $query = Task::query();

            // Apply filters
            $this->applyFilters($query, $filters);

            $totalTasks = (clone $query)->count();
            $completedTasks = (clone $query)->completed()->count();
            $inProgressTasks = (clone $query)->inProgress()->count();
            $overdueTasks = (clone $query)->overdue()->count();
            $blockedTasks = (clone $query)->blocked()->count();

            // Status distribution
            $statusCounts = (clone $query)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Priority distribution
            $priorityCounts = (clone $query)
                ->select('priority', DB::raw('count(*) as count'))
                ->whereNotNull('priority')
                ->groupBy('priority')
                ->pluck('count', 'status')
                ->toArray();

            // Department distribution
            $departmentCounts = (clone $query)
                ->join('departments', 'tasks.department_id', '=', 'departments.id')
                ->select('departments.name', DB::raw('count(*) as count'))
                ->groupBy('departments.id', 'departments.name')
                ->pluck('count', 'name')
                ->toArray();

            // Recent activity (last 7 days)
            $recentActivity = (clone $query)
                ->where('created_at', '>=', now()->subDays(7))
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->pluck('count', 'date')
                ->toArray();

            return [
                'summary' => [
                    'total_tasks' => $totalTasks,
                    'completed_tasks' => $completedTasks,
                    'in_progress_tasks' => $inProgressTasks,
                    'overdue_tasks' => $overdueTasks,
                    'blocked_tasks' => $blockedTasks,
                    'completion_rate' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0,
                ],
                'status_distribution' => $statusCounts,
                'priority_distribution' => $priorityCounts,
                'department_distribution' => $departmentCounts,
                'recent_activity' => $recentActivity,
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Get key metrics for tasks.
     *
     * @param array $filters Optional filters
     * @return array Key metrics
     */
    public function getKeyMetrics(array $filters = []): array
    {
        $cacheKey = 'task_key_metrics_' . md5(serialize($filters));

        return Cache::remember($cacheKey, 900, function () use ($filters) {
            $query = Task::query();
            $this->applyFilters($query, $filters);

            // Completion rate
            $totalTasks = (clone $query)->count();
            $completedTasks = (clone $query)->completed()->count();
            $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0;

            // Average completion time (in hours)
            $avgCompletionTime = (clone $query)
                ->completed()
                ->whereNotNull('started_at')
                ->whereNotNull('completed_at')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at)) as avg_hours'))
                ->first()
                ->avg_hours ?? 0;

            // Overdue percentage
            $overdueTasks = (clone $query)->overdue()->count();
            $overduePercentage = $totalTasks > 0 ? round(($overdueTasks / $totalTasks) * 100, 2) : 0;

            // Average tasks per user
            $uniqueAssignees = (clone $query)
                ->distinct('assigned_user_id')
                ->whereNotNull('assigned_user_id')
                ->count('assigned_user_id');

            $avgTasksPerUser = $uniqueAssignees > 0 ? round($totalTasks / $uniqueAssignees, 2) : 0;

            // Time variance (estimated vs actual)
            $timeVariance = (clone $query)
                ->completed()
                ->whereNotNull('estimated_hours')
                ->whereNotNull('actual_hours')
                ->select(DB::raw('AVG(actual_hours - estimated_hours) as variance'))
                ->first()
                ->variance ?? 0;

            // Issue rate (issues per task)
            $totalIssues = (clone $query)
                ->join('task_issues', 'tasks.id', '=', 'task_issues.task_id')
                ->count();

            $issueRate = $totalTasks > 0 ? round($totalIssues / $totalTasks, 2) : 0;

            return [
                'completion_rate' => $completionRate,
                'average_completion_time_hours' => round($avgCompletionTime, 2),
                'overdue_percentage' => $overduePercentage,
                'average_tasks_per_user' => $avgTasksPerUser,
                'time_variance_hours' => round($timeVariance, 2),
                'issue_rate_per_task' => $issueRate,
                'total_tasks' => $totalTasks,
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Get time series data for trend analysis.
     *
     * @param string $period 'day', 'week', 'month'
     * @param int $days Number of days to look back
     * @param array $filters Optional filters
     * @return array Time series data
     */
    public function getTimeSeriesData(string $period = 'day', int $days = 30, array $filters = []): array
    {
        $cacheKey = 'task_time_series_' . $period . '_' . $days . '_' . md5(serialize($filters));

        return Cache::remember($cacheKey, 1800, function () use ($period, $days, $filters) {
            $query = Task::query();
            $this->applyFilters($query, $filters);

            $dateFormat = match ($period) {
                'week' => '%Y-%u', // Year-week
                'month' => '%Y-%m', // Year-month
                default => '%Y-%m-%d', // Day
            };

            $groupBy = match ($period) {
                'week' => 'YEARWEEK(created_at, 1)',
                'month' => 'DATE_FORMAT(created_at, "%Y-%m")',
                default => 'DATE(created_at)',
            };

            // Created tasks over time
            $createdData = (clone $query)
                ->select(DB::raw("{$groupBy} as period"), DB::raw('COUNT(*) as created'))
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('period')
                ->orderBy('period')
                ->pluck('created', 'period')
                ->toArray();

            // Completed tasks over time
            $completedData = (clone $query)
                ->select(DB::raw("{$groupBy} as period"), DB::raw('COUNT(*) as completed'))
                ->where('completed_at', '>=', now()->subDays($days))
                ->whereNotNull('completed_at')
                ->groupBy('period')
                ->orderBy('period')
                ->pluck('completed', 'period')
                ->toArray();

            // Overdue tasks over time
            $overdueData = (clone $query)
                ->select(DB::raw("{$groupBy} as period"), DB::raw('COUNT(*) as overdue'))
                ->where('due_date', '>=', now()->subDays($days))
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->groupBy('period')
                ->orderBy('period')
                ->pluck('overdue', 'period')
                ->toArray();

            // Generate all periods in range
            $periods = $this->generatePeriods($period, $days);

            $timeSeries = [];
            foreach ($periods as $periodKey) {
                $timeSeries[] = [
                    'period' => $periodKey,
                    'created' => $createdData[$periodKey] ?? 0,
                    'completed' => $completedData[$periodKey] ?? 0,
                    'overdue' => $overdueData[$periodKey] ?? 0,
                ];
            }

            return [
                'period' => $period,
                'days' => $days,
                'data' => $timeSeries,
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Get department-specific analytics.
     *
     * @param array $filters Optional filters
     * @return array Department analytics
     */
    public function getDepartmentAnalytics(array $filters = []): array
    {
        $cacheKey = 'task_department_analytics_' . md5(serialize($filters));

        return Cache::remember($cacheKey, 1800, function () use ($filters) {
            $query = Task::query();
            $this->applyFilters($query, $filters);

            $departments = (clone $query)
                ->join('departments', 'tasks.department_id', '=', 'departments.id')
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

            $departmentData = [];
            foreach ($departments as $dept) {
                $departmentData[] = [
                    'department_id' => $dept->id,
                    'department_name' => $dept->name,
                    'total_tasks' => (int) $dept->total_tasks,
                    'completed_tasks' => (int) $dept->completed_tasks,
                    'in_progress_tasks' => (int) $dept->in_progress_tasks,
                    'overdue_tasks' => (int) $dept->overdue_tasks,
                    'blocked_tasks' => (int) $dept->blocked_tasks,
                    'completion_rate' => (float) $dept->completion_rate,
                    'average_completion_time_hours' => round((float) $dept->avg_completion_time, 2),
                ];
            }

            return [
                'departments' => $departmentData,
                'generated_at' => now()->toISOString(),
            ];
        });
    }

    /**
     * Apply filters to a query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['department_id'])) {
            $query->byDepartment($filters['department_id']);
        }

        if (isset($filters['task_type'])) {
            $query->byType($filters['task_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->byPriority($filters['priority']);
        }

        if (isset($filters['assigned_user_id'])) {
            $query->assignedToUser($filters['assigned_user_id']);
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
     * Generate all periods in a date range.
     *
     * @param string $period
     * @param int $days
     * @return array
     */
    protected function generatePeriods(string $period, int $days): array
    {
        $periods = [];
        $current = now()->subDays($days - 1)->startOfDay();

        while ($current <= now()) {
            $periods[] = match ($period) {
                'week' => $current->format('o-W'), // Year-week
                'month' => $current->format('Y-m'), // Year-month
                default => $current->format('Y-m-d'), // Day
            };

            $current = match ($period) {
                'week' => $current->addWeek(),
                'month' => $current->addMonth(),
                default => $current->addDay(),
            };
        }

        return $periods;
    }

    /**
     * Clear analytics cache.
     */
    public function clearCache(): void
    {
        Cache::forget('task_dashboard_*');
        Cache::forget('task_key_metrics_*');
        Cache::forget('task_time_series_*');
        Cache::forget('task_department_analytics_*');
    }
}