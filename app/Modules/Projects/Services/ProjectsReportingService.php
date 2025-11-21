<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectsReportingService
{
    /**
     * Generate comprehensive project analytics report
     */
    public function generateProjectAnalytics(array $filters = []): array
    {
        $query = ProjectEnquiry::query();

        // Apply date filters
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        // Apply department filter
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        $enquiries = $query->with('department', 'client')->get();

        return [
            'summary' => $this->getAnalyticsSummary($enquiries),
            'performance_metrics' => $this->getPerformanceMetrics($enquiries),
            'department_analysis' => $this->getDepartmentAnalysis($enquiries),
            'client_analysis' => $this->getClientAnalysis($enquiries),
            'trend_analysis' => $this->getTrendAnalysis($filters),
        ];
    }

    /**
     * Get task performance analytics
     */
    public function generateTaskAnalytics(array $filters = []): array
    {
        $query = EnquiryTask::query();

        // Apply date filters
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        // Apply department filter
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        $tasks = $query->with('department', 'enquiry')->get();

        return [
            'task_summary' => $this->getTaskSummary($tasks),
            'department_performance' => $this->getDepartmentPerformance($tasks),
            'completion_trends' => $this->getCompletionTrends($filters),
            'bottleneck_analysis' => $this->getBottleneckAnalysis($tasks),
        ];
    }



    /**
     * Get analytics summary
     */
    private function getAnalyticsSummary($enquiries): array
    {
        return [
            'total_enquiries' => $enquiries->count(),
            'total_budget' => $enquiries->sum('estimated_budget'),
            'average_budget' => $enquiries->avg('estimated_budget'),
            'enquiries_by_status' => $enquiries->groupBy('status')->map->count(),
        ];
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics($enquiries): array
    {
        $completedEnquiries = $enquiries->where('status', 'completed');
        $avgCompletionTime = null;

        if ($completedEnquiries->isNotEmpty()) {
            $totalDays = $completedEnquiries->sum(function ($enquiry) {
                if ($enquiry->start_date && $enquiry->end_date) {
                    return Carbon::parse($enquiry->start_date)->diffInDays(Carbon::parse($enquiry->end_date));
                }
                return 0;
            });
            $avgCompletionTime = round($totalDays / $completedEnquiries->count(), 1);
        }

        return [
            'on_time_delivery_rate' => $this->calculateOnTimeDeliveryRate($enquiries),
            'average_completion_time_days' => $avgCompletionTime,
            'budget_variance' => $this->calculateBudgetVariance($enquiries),
        ];
    }

    /**
     * Get department analysis
     */
    private function getDepartmentAnalysis($enquiries): array
    {
        return $enquiries->groupBy('department.name')->map(function ($deptEnquiries) {
            return [
                'count' => $deptEnquiries->count(),
                'total_budget' => $deptEnquiries->sum('estimated_budget'),
                'average_budget' => $deptEnquiries->avg('estimated_budget'),
                'status_distribution' => $deptEnquiries->groupBy('status')->map->count(),
            ];
        })->toArray();
    }

    /**
     * Get client analysis
     */
    private function getClientAnalysis($enquiries): array
    {
        return $enquiries->groupBy('client.full_name')->map(function ($clientEnquiries) {
            return [
                'count' => $clientEnquiries->count(),
                'total_budget' => $clientEnquiries->sum('estimated_budget'),
                'status_distribution' => $clientEnquiries->groupBy('status')->map->count(),
            ];
        })->toArray();
    }

    /**
     * Get trend analysis
     */
    private function getTrendAnalysis(array $filters): array
    {
        $startDate = $filters['start_date'] ?? Carbon::now()->subMonths(6);
        $endDate = $filters['end_date'] ?? Carbon::now();

        $monthlyData = Enquiry::select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('count(*) as count'),
                DB::raw('sum(estimated_budget) as total_budget')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $monthlyData->toArray();
    }

    /**
     * Get task summary
     */
    private function getTaskSummary($tasks): array
    {
        return [
            'total_tasks' => $tasks->count(),
            'completed_tasks' => $tasks->where('status', 'completed')->count(),
            'pending_tasks' => $tasks->where('status', 'pending')->count(),
            'in_progress_tasks' => $tasks->where('status', 'in_progress')->count(),
            'overdue_tasks' => $tasks->where('due_date', '<', Carbon::now())
                                   ->whereIn('status', ['pending', 'in_progress'])->count(),
        ];
    }

    /**
     * Get department performance
     */
    private function getDepartmentPerformance($tasks): array
    {
        return $tasks->groupBy('department.name')->map(function ($deptTasks) {
            $completed = $deptTasks->where('status', 'completed')->count();
            $total = $deptTasks->count();
            $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

            return [
                'total_tasks' => $total,
                'completed_tasks' => $completed,
                'completion_rate' => $completionRate,
                'average_completion_days' => $this->calculateAverageTaskCompletionTime($deptTasks->where('status', 'completed')),
            ];
        })->toArray();
    }

    /**
     * Get completion trends
     */
    private function getCompletionTrends(array $filters): array
    {
        $startDate = $filters['start_date'] ?? Carbon::now()->subMonths(6);
        $endDate = $filters['end_date'] ?? Carbon::now();

        $trends = EnquiryTask::select(
                DB::raw('DATE_FORMAT(completed_at, "%Y-%m") as month'),
                DB::raw('count(*) as completed_count')
            )
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->whereNotNull('completed_at')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $trends->toArray();
    }

    /**
     * Get bottleneck analysis
     */
    private function getBottleneckAnalysis($tasks): array
    {
        $pendingTasks = $tasks->where('status', 'pending');
        $overdueTasks = $tasks->where('due_date', '<', Carbon::now())
                             ->whereIn('status', ['pending', 'in_progress']);

        return [
            'longest_pending_tasks' => $pendingTasks->sortByDesc('created_at')->take(10)->map(function ($task) {
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'department' => $task->department->name,
                    'days_pending' => Carbon::parse($task->created_at)->diffInDays(Carbon::now()),
                ];
            })->values(),
            'overdue_tasks_by_department' => $overdueTasks->groupBy('department.name')->map->count(),
        ];
    }

    /**
     * Calculate on-time delivery rate
     */
    private function calculateOnTimeDeliveryRate($enquiries): float
    {
        $completedEnquiries = $enquiries->where('status', 'completed')->whereNotNull('expected_delivery_date');

        if ($completedEnquiries->isEmpty()) return 0;

        $onTime = $completedEnquiries->filter(function ($enquiry) {
            return Carbon::parse($enquiry->end_date)->lte(Carbon::parse($enquiry->expected_delivery_date));
        })->count();

        return round(($onTime / $completedEnquiries->count()) * 100, 2);
    }

    /**
     * Calculate budget variance
     */
    private function calculateBudgetVariance($enquiries): ?float
    {
        $enquiriesWithBudget = $enquiries->whereNotNull('estimated_budget')->whereNotNull('budget');

        if ($enquiriesWithBudget->isEmpty()) return null;

        $totalVariance = $enquiriesWithBudget->sum(function ($enquiry) {
            return (($enquiry->budget - $enquiry->estimated_budget) / $enquiry->estimated_budget) * 100;
        });

        return round($totalVariance / $enquiriesWithBudget->count(), 2);
    }



    /**
     * Calculate average task completion time
     */
    private function calculateAverageTaskCompletionTime($completedTasks): ?float
    {
        if ($completedTasks->isEmpty()) return null;

        $totalHours = $completedTasks->sum(function ($task) {
            if ($task->started_at && $task->completed_at) {
                return Carbon::parse($task->started_at)->diffInHours(Carbon::parse($task->completed_at));
            }
            return 0;
        });

        return round($totalHours / $completedTasks->count(), 1);
    }
}
