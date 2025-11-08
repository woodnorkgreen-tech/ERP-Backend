<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Models\Phase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectsDashboardService
{
    /**
     * Get enquiry metrics for dashboard
     */
    public function getEnquiryMetrics(): array
    {
        $totalEnquiries = ProjectEnquiry::count();

        $statusCounts = ProjectEnquiry::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $monthlyEnquiries = ProjectEnquiry::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'count' => $item->count
                ];
            });

        $priorityCounts = ProjectEnquiry::select('priority', DB::raw('count(*) as count'))
            ->whereNotNull('priority')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $departmentCounts = ProjectEnquiry::with('department')
            ->select('department_id', DB::raw('count(*) as count'))
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->with('department')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->department->name => $item->count];
            })
            ->toArray();

        return [
            'total_enquiries' => $totalEnquiries,
            'status_breakdown' => $statusCounts,
            'monthly_trend' => $monthlyEnquiries,
            'priority_distribution' => $priorityCounts,
            'department_distribution' => $departmentCounts,
        ];
    }

    /**
     * Get task metrics for dashboard
     */
    public function getTaskMetrics(): array
    {
        // All tasks are now consolidated into EnquiryTask
        $enquiryTasks = EnquiryTask::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Departmental tasks are now part of EnquiryTask
        $departmentalTasks = EnquiryTask::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $overdueTasks = EnquiryTask::where('due_date', '<', Carbon::now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        $tasksByDepartment = EnquiryTask::with('department')
            ->select('department_id', DB::raw('count(*) as count'))
            ->groupBy('department_id')
            ->with('department')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->department->name => $item->count];
            })
            ->toArray();

        $taskCompletionRate = $this->calculateTaskCompletionRate();

        return [
            'enquiry_tasks' => $enquiryTasks,
            'departmental_tasks' => $departmentalTasks,
            'total_tasks' => array_sum($enquiryTasks) + array_sum($departmentalTasks),
            'overdue_tasks' => $overdueTasks,
            'tasks_by_department' => $tasksByDepartment,
            'completion_rate' => $taskCompletionRate,
        ];
    }

    /**
     * Get project metrics for dashboard
     */
    public function getProjectMetrics(): array
    {
        // For now, projects are enquiries that have been converted
        $convertedProjects = ProjectEnquiry::where('status', 'converted_to_project')->count();

        $activeProjects = ProjectEnquiry::whereIn('status', ['planning', 'in_progress'])->count();

        $completedProjects = ProjectEnquiry::where('status', 'completed')->count();

        $totalBudget = ProjectEnquiry::whereNotNull('estimated_budget')
            ->sum('estimated_budget');

        $projectsByStatus = ProjectEnquiry::select('status', DB::raw('count(*) as count'))
            ->whereIn('status', ['planning', 'in_progress', 'completed', 'converted_to_project'])
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $averageProjectDuration = $this->calculateAverageProjectDuration();

        $phasesByStatus = Phase::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $phaseProgress = $this->getPhaseProgress();

        return [
            'total_projects' => $convertedProjects + $activeProjects + $completedProjects,
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'converted_enquiries' => $convertedProjects,
            'total_budget' => $totalBudget,
            'projects_by_status' => $projectsByStatus,
            'average_duration_days' => $averageProjectDuration,
            'phases_by_status' => $phasesByStatus,
            'phase_progress' => $phaseProgress,
        ];
    }

    /**
     * Calculate task completion rate
     */
    private function calculateTaskCompletionRate(): float
    {
        $totalTasks = EnquiryTask::count();
        if ($totalTasks === 0) return 0;

        $completedTasks = EnquiryTask::where('status', 'completed')->count();

        return round(($completedTasks / $totalTasks) * 100, 2);
    }

    /**
     * Calculate average project duration
     */
    private function calculateAverageProjectDuration(): ?float
    {
        $completedProjects = ProjectEnquiry::where('status', 'completed')
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->get();

        if ($completedProjects->isEmpty()) return null;

        $totalDays = $completedProjects->sum(function ($project) {
            return Carbon::parse($project->start_date)->diffInDays(Carbon::parse($project->end_date));
        });

        return round($totalDays / $completedProjects->count(), 1);
    }

    /**
     * Get phase progress metrics
     */
    private function getPhaseProgress(): array
    {
        $phases = Phase::with('projectEnquiry')
            ->select('name', 'status', DB::raw('count(*) as count'))
            ->groupBy('name', 'status')
            ->get()
            ->groupBy('name')
            ->map(function ($phaseGroup) {
                $total = $phaseGroup->sum('count');
                $completed = $phaseGroup->where('status', 'completed')->sum('count');
                $progress = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

                return [
                    'name' => $phaseGroup->first()->name,
                    'total' => $total,
                    'completed' => $completed,
                    'progress' => $progress
                ];
            })
            ->values()
            ->toArray();

        return $phases;
    }

    /**
     * Get recent activities for dashboard
     */
    public function getRecentActivities(int $limit = 10): array
    {
        $activities = [];

        // Recent enquiries
        $recentEnquiries = ProjectEnquiry::with('client')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($enquiry) {
                return [
                    'type' => 'enquiry_created',
                    'title' => 'New enquiry created',
                    'description' => "Enquiry #{$enquiry->enquiry_number} for {$enquiry->client->full_name}",
                    'timestamp' => $enquiry->created_at->toISOString(),
                    'priority' => $enquiry->priority,
                    'status' => $enquiry->status
                ];
            });

        // Recent task updates
        $recentTasks = EnquiryTask::with(['enquiry.client', 'department'])
            ->where('updated_at', '>', Carbon::now()->subDays(7))
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($task) {
                return [
                    'type' => 'task_updated',
                    'title' => 'Task status updated',
                    'description' => "{$task->title} - {$task->department->name}",
                    'timestamp' => $task->updated_at->toISOString(),
                    'status' => $task->status
                ];
            });

        // Recent phase updates
        $recentPhases = Phase::with('projectEnquiry.client')
            ->where('updated_at', '>', Carbon::now()->subDays(7))
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($phase) {
                return [
                    'type' => 'phase_updated',
                    'title' => 'Phase status updated',
                    'description' => "{$phase->name} phase for {$phase->projectEnquiry->client->full_name}",
                    'timestamp' => $phase->updated_at->toISOString(),
                    'status' => $phase->status
                ];
            });

        // Combine and sort by timestamp
        $activities = collect([...$recentEnquiries, ...$recentTasks, ...$recentPhases])
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values()
            ->toArray();

        return $activities;
    }

    /**
     * Get alerts for dashboard
     */
    public function getAlerts(): array
    {
        $alerts = [];

        // Overdue tasks
        $overdueTasks = EnquiryTask::with(['enquiry.client', 'department'])
            ->where('due_date', '<', Carbon::now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->get()
            ->map(function ($task) {
                return [
                    'type' => 'overdue_task',
                    'severity' => 'high',
                    'title' => 'Overdue Task',
                    'message' => "Task '{$task->title}' for {$task->enquiry->client->full_name} is overdue",
                    'action_url' => "/projects/enquiries/{$task->enquiry_id}"
                ];
            });

        // Upcoming deadlines (next 3 days)
        $upcomingDeadlines = EnquiryTask::with(['enquiry.client', 'department'])
            ->where('due_date', '>', Carbon::now())
            ->where('due_date', '<=', Carbon::now()->addDays(3))
            ->whereIn('status', ['pending', 'in_progress'])
            ->get()
            ->map(function ($task) {
                return [
                    'type' => 'upcoming_deadline',
                    'severity' => 'medium',
                    'title' => 'Upcoming Deadline',
                    'message' => "Task '{$task->title}' for {$task->enquiry->client->full_name} due in {$task->due_date->diffForHumans()}",
                    'action_url' => "/projects/enquiries/{$task->enquiry_id}"
                ];
            });

        // High priority enquiries without recent updates
        $staleHighPriority = ProjectEnquiry::where('priority', 'urgent')
            ->where('updated_at', '<', Carbon::now()->subDays(3))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('client')
            ->get()
            ->map(function ($enquiry) {
                return [
                    'type' => 'stale_high_priority',
                    'severity' => 'medium',
                    'title' => 'High Priority Enquiry Needs Attention',
                    'message' => "Urgent enquiry #{$enquiry->enquiry_number} hasn't been updated recently",
                    'action_url' => "/projects/enquiries/{$enquiry->id}"
                ];
            });

        $alerts = collect([...$overdueTasks, ...$upcomingDeadlines, ...$staleHighPriority])
            ->sortByDesc(function ($alert) {
                $severityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                return $severityOrder[$alert['severity']] ?? 0;
            })
            ->values()
            ->toArray();

        return $alerts;
    }

    /**
     * Export dashboard data to PDF
     */
    public function exportToPDF(array $filters = []): string
    {
        // This would generate a PDF with dashboard data
        // For now, return a placeholder path
        return 'exports/dashboard_' . now()->format('Y-m-d_H-i-s') . '.pdf';
    }

    /**
     * Export dashboard data to Excel
     */
    public function exportToExcel(array $filters = []): string
    {
        // This would generate an Excel file with dashboard data
        // For now, return a placeholder path
        return 'exports/dashboard_' . now()->format('Y-m-d_H-i-s') . '.xlsx';
    }

    /**
     * Apply filters to dashboard data
     */
    public function getFilteredDashboardData(array $filters = []): array
    {
        $query = ProjectEnquiry::query();

        // Apply search filter
        if (!empty($filters['searchQuery'])) {
            $search = $filters['searchQuery'];
            $query->where(function ($q) use ($search) {
                $q->where('enquiry_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($clientQuery) use ($search) {
                      $clientQuery->where('full_name', 'like', "%{$search}%");
                  });
            });
        }

        // Apply date range filter
        if (!empty($filters['dateFrom'])) {
            $query->where('created_at', '>=', $filters['dateFrom']);
        }
        if (!empty($filters['dateTo'])) {
            $query->where('created_at', '<=', $filters['dateTo']);
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        // Apply priority filter
        if (!empty($filters['priority'])) {
            $query->whereIn('priority', $filters['priority']);
        }

        // Apply department filter
        if (!empty($filters['department'])) {
            $query->where('department_id', $filters['department']);
        }

        // Apply budget range filter
        if (!empty($filters['budgetRange'])) {
            $minBudget = $filters['budgetRange']['min'] ?? 0;
            $maxBudget = $filters['budgetRange']['max'] ?? 1000000;
            $query->whereBetween('estimated_budget', [$minBudget, $maxBudget]);
        }

        // Get filtered metrics
        $filteredEnquiries = $query->get();

        // Calculate filtered metrics
        $enquiryMetrics = $this->calculateFilteredEnquiryMetrics($filteredEnquiries);
        $taskMetrics = $this->calculateFilteredTaskMetrics($filteredEnquiries);
        $projectMetrics = $this->calculateFilteredProjectMetrics($filteredEnquiries);

        return [
            'enquiry_metrics' => $enquiryMetrics,
            'task_metrics' => $taskMetrics,
            'project_metrics' => $projectMetrics,
            'filtered_count' => $filteredEnquiries->count(),
            'applied_filters' => $filters
        ];
    }

    /**
     * Calculate filtered enquiry metrics
     */
    private function calculateFilteredEnquiryMetrics($enquiries): array
    {
        $totalEnquiries = $enquiries->count();

        $statusBreakdown = $enquiries->groupBy('status')->map->count()->toArray();

        $priorityDistribution = $enquiries->whereNotNull('priority')
            ->groupBy('priority')
            ->map->count()
            ->toArray();

        $departmentDistribution = $enquiries->whereNotNull('department_id')
            ->groupBy('department.name')
            ->map->count()
            ->toArray();

        // Monthly trend for filtered data
        $monthlyTrend = $enquiries->groupBy(function ($enquiry) {
            return $enquiry->created_at->format('M Y');
        })->map->count()->toArray();

        return [
            'total_enquiries' => $totalEnquiries,
            'status_breakdown' => $statusBreakdown,
            'monthly_trend' => array_map(function ($month, $count) {
                return ['month' => $month, 'count' => $count];
            }, array_keys($monthlyTrend), $monthlyTrend),
            'priority_distribution' => $priorityDistribution,
            'department_distribution' => $departmentDistribution,
        ];
    }

    /**
     * Calculate filtered task metrics
     */
    private function calculateFilteredTaskMetrics($enquiries): array
    {
        $enquiryIds = $enquiries->pluck('id');

        $enquiryTasks = EnquiryTask::whereIn('enquiry_id', $enquiryIds)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $departmentalTasks = EnquiryTask::whereIn('project_enquiry_id', $enquiryIds)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $overdueTasks = EnquiryTask::whereIn('project_enquiry_id', $enquiryIds)
            ->where('due_date', '<', Carbon::now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        $completionRate = $this->calculateFilteredTaskCompletionRate($enquiryIds);

        return [
            'enquiry_tasks' => $enquiryTasks,
            'departmental_tasks' => $departmentalTasks,
            'total_tasks' => array_sum($enquiryTasks) + array_sum($departmentalTasks),
            'overdue_tasks' => $overdueTasks,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * Calculate filtered project metrics
     */
    private function calculateFilteredProjectMetrics($enquiries): array
    {
        $activeProjects = $enquiries->whereIn('status', ['planning', 'in_progress'])->count();
        $completedProjects = $enquiries->where('status', 'completed')->count();
        $convertedProjects = $enquiries->where('status', 'converted_to_project')->count();

        $totalBudget = $enquiries->whereNotNull('estimated_budget')->sum('estimated_budget');

        $projectsByStatus = $enquiries->whereIn('status', ['planning', 'in_progress', 'completed', 'converted_to_project'])
            ->groupBy('status')
            ->map->count()
            ->toArray();

        return [
            'total_projects' => $enquiries->count(),
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'converted_enquiries' => $convertedProjects,
            'total_budget' => $totalBudget,
            'projects_by_status' => $projectsByStatus,
        ];
    }

    /**
     * Calculate task completion rate for filtered enquiries
     */
    private function calculateFilteredTaskCompletionRate(array $enquiryIds): float
    {
        $totalTasks = EnquiryTask::whereIn('project_enquiry_id', $enquiryIds)->count();
        if ($totalTasks === 0) return 0;

        $completedTasks = EnquiryTask::whereIn('project_enquiry_id', $enquiryIds)
            ->where('status', 'completed')
            ->count();

        return round(($completedTasks / $totalTasks) * 100, 2);
    }
}
