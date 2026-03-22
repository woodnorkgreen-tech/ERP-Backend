<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Models\Phase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectsDashboardService
{
    // Thresholds for alerts and suggestions
    const WORKLOAD_THRESHOLD = 15;
    const STALE_QUOTE_DAYS = 7;
    const STALE_IN_PROGRESS_DAYS = 3;
    const POOR_MARGIN_THRESHOLD = 15;

    public function getCommandCenterData(): array
    {
        // Get all departments for the pulse
        $allDepartments = \App\Modules\HR\Models\Department::all();

        // 1. Pipeline Flow Breakdown (Stages)
        // Group multiple statuses into stages for a cleaner pipeline
        $statusMapping = [
            'enquiry_logged' => 'enquiry_logged',
            'client_registered' => 'enquiry_logged',
            'site_survey_scheduled' => 'site_survey_scheduled',
            'site_survey_completed' => 'site_survey_scheduled',
            'design_assigned' => 'design_assigned',
            'design_completed' => 'design_assigned',
            'design_approved' => 'design_assigned',
            'quote_prepared' => 'quote_prepared',
            'budget_created' => 'quote_prepared',
            'quote_approved' => 'quote_approved',
            'materials_specified' => 'materials_specified',
            'planning' => 'in_progress',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
        ];

        // Define the display order and labels for the stages
        $pipelineStages = [
            'enquiry_logged' => 'New Enquiries',
            'site_survey_scheduled' => 'Site Surveys',
            'design_assigned' => 'Design Phase',
            'quote_prepared' => 'Quoting',
            'quote_approved' => 'Approved',
            'materials_specified' => 'Procurement',
            'in_progress' => 'Execution',
            'completed' => 'Completed'
        ];

        $rawCounts = ProjectEnquiry::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pipelineCounts = [];
        foreach ($statusMapping as $internalStatus => $stageKey) {
            $count = $rawCounts[$internalStatus] ?? 0;
            $pipelineCounts[$stageKey] = ($pipelineCounts[$stageKey] ?? 0) + $count;
        }

        // 2. Department Pulse (Active Workload)
        $departmentPulse = EnquiryTask::select('department_id', 'status', DB::raw('count(*) as count'))
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('department_id')
            ->groupBy('department_id', 'status')
            ->get()
            ->groupBy('department_id');

        $departmentStats = $allDepartments->map(function($dept) use ($departmentPulse) {
             $group = $departmentPulse->get($dept->id);
             $pending = $group ? $group->where('status', 'pending')->sum('count') : 0;
             $inProgress = $group ? $group->where('status', 'in_progress')->sum('count') : 0;
             
             return [
                  'id' => $dept->id,
                  'name' => $dept->name,
                  'pending' => $pending,
                  'in_progress' => $inProgress,
                  'total_load' => $pending + $inProgress
             ];
        })->values()->sortByDesc('total_load')->values()->toArray();

        // 3. Global Bottlenecks (Top 10 Overdue)
        $bottlenecks = EnquiryTask::with(['enquiry', 'department', 'assignedTo'])
            ->where('due_date', '<', Carbon::now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get();
        
        return [
            'pipeline' => [
                'stages' => $pipelineStages,
                'counts' => $pipelineCounts
            ],
            'department_pulse' => $departmentStats,
            'bottlenecks' => $bottlenecks,
            'timestamp' => now()
        ];
    }

    /**
     * Get enquiry metrics for dashboard
     */
    public function getEnquiryMetrics(): array
    {
        try {
            \Log::info('Calculating Enquiry Metrics');
            $totalEnquiries = ProjectEnquiry::count();
            
            // Conversion metrics
            $convertedCount = ProjectEnquiry::whereNotNull('job_number')->count();
            $unconvertedCount = ProjectEnquiry::whereNull('job_number')->where('status', '!=', 'cancelled')->count();
            $conversionRate = $totalEnquiries > 0 ? round(($convertedCount / $totalEnquiries) * 100, 2) : 0;

            $statusBreakdown = ProjectEnquiry::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $monthlyTrend = ProjectEnquiry::select(
                    DB::raw('year(created_at) as year'),
                    DB::raw('month(created_at) as month'),
                    DB::raw('count(*) as count')
                )
                ->where('created_at', '>=', Carbon::now()->subMonths(12))
                ->groupBy('year', 'month')
                ->orderBy('year', 'asc')
                ->orderBy('month', 'asc')
                ->get()
                ->map(function ($item) {
                    try {
                        $monthNum = (int)$item->month; // Ensure it's an integer
                        // Safe Carbon usage: create date with numeric month
                        $monthName = Carbon::create(null, $monthNum, 1)->format('M');
                        return [
                            'month' => $monthName,
                            'count' => (int)$item->count
                        ];
                    } catch (\Exception $e) {
                        \Log::warning('Error formatting month in trend', [
                            'month' => $item->month,
                            'error' => $e->getMessage()
                        ]);
                        return [
                            'month' => 'Unknown',
                            'count' => (int)$item->count
                        ];
                    }
                })->toArray();

            $priorityDistribution = ProjectEnquiry::select('priority', DB::raw('count(*) as count'))
                ->whereNotNull('priority')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            // Safer department distribution
            $departmentCounts = ProjectEnquiry::whereNotNull('department_id')
                ->select('department_id', DB::raw('count(*) as count'))
                ->groupBy('department_id')
                ->get()
                ->mapWithKeys(function ($item) {
                    try {
                        $dept = \App\Modules\HR\Models\Department::find($item->department_id);
                        $name = $dept ? $dept->name : 'Direct Enquiry';
                        return [$name => (int)$item->count];
                    } catch (\Exception $e) {
                        \Log::warning('Error fetching department for enquiry metrics', [
                            'department_id' => $item->department_id,
                            'error' => $e->getMessage()
                        ]);
                        return ['Direct Enquiry' => (int)$item->count];
                    }
                })
                ->toArray();

            \Log::info('Enquiry Metrics calculated', ['total' => $totalEnquiries]);

            return [
                'total_enquiries' => $totalEnquiries,
                'converted_to_project' => $convertedCount,
                'remaining_enquiries' => $unconvertedCount,
                'conversion_rate' => $conversionRate,
                'status_breakdown' => $statusBreakdown,
                'monthly_trend' => $monthlyTrend,
                'priority_distribution' => $priorityDistribution,
                'department_distribution' => $departmentCounts,
            ];
        } catch (\Exception $e) {
            \Log::error('Error calculating enquiry metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return safe defaults
            return [
                'total_enquiries' => 0,
                'converted_to_project' => 0,
                'remaining_enquiries' => 0,
                'conversion_rate' => 0,
                'status_breakdown' => [],
                'monthly_trend' => [],
                'priority_distribution' => [],
                'department_distribution' => [],
            ];
        }
    }

    /**
     * Get task metrics for dashboard
     */
    public function getTaskMetrics(): array
    {
        \Log::info('Calculating Task Metrics');
        // All tasks are now consolidated into EnquiryTask
        $enquiryTasks = EnquiryTask::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Departmental tasks are now part of EnquiryTask
        $departmentalTasks = []; // Add if you have a departmental tasks table
        
        $totalTasksCount = EnquiryTask::count();
        $overdueTasks = EnquiryTask::where('due_date', '<', Carbon::now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->where('status', '!=', 'completed')
            ->count();

        $tasksByDepartment = EnquiryTask::join('departments', 'enquiry_tasks.department_id', '=', 'departments.id')
            ->select('departments.name', DB::raw('count(*) as count'))
            ->groupBy('departments.name')
            ->pluck('count', 'departments.name')
            ->toArray();

        $taskCompletionRate = $this->calculateTaskCompletionRate();

        // Performance Matrix (New Analytics)
        $performanceMatrix = DB::table('enquiry_tasks')
            ->join('departments', 'enquiry_tasks.department_id', '=', 'departments.id')
            ->select(
                'departments.id as department_id', 
                DB::raw('count(*) as total'),
                DB::raw('sum(case when status = "completed" then 1 else 0 end) as completed'),
                DB::raw('sum(case when status = "completed" and completed_at <= due_date then 1 else 0 end) as on_time')
            )
            ->whereNotNull('department_id')
            ->groupBy('departments.id')
            ->get()
            ->mapWithKeys(function ($item) {
                $dept = \App\Modules\HR\Models\Department::find($item->department_id);
                if (!$dept) return [];
                
                $total = $item->total ?: 1;
                $completed = $item->completed ?: 0;
                $onTime = $item->on_time ?: 0;
                
                return [$dept->name => [
                    'efficiency' => round(($completed / $total) * 100, 1),
                    'punctuality' => $completed > 0 ? round(($onTime / $completed) * 100, 1) : 0,
                    'volume' => $total
                ]];
            })->filter()->toArray();

        \Log::info('Task Metrics calculated', ['total' => $totalTasksCount]);

        return [
            'enquiry_tasks' => $enquiryTasks,
            'departmental_tasks' => $departmentalTasks,
            'total_tasks' => $totalTasksCount,
            'overdue_tasks' => $overdueTasks,
            'tasks_by_department' => $tasksByDepartment,
            'performance_matrix' => $performanceMatrix,
            'completion_rate' => $taskCompletionRate,
        ];
    }

    /**
     * Get project metrics for dashboard
     */
    public function getProjectMetrics(): array
    {
        \Log::info('Calculating Project Metrics');
        // For now, completed enquiries are considered completed projects
        $completedProjects = ProjectEnquiry::where('status', 'completed')->count();

        // Active projects are anything that's been approved and is being worked on
        $activeProjects = ProjectEnquiry::whereIn('status', ['planning', 'in_progress', 'materials_specified', 'budget_created', 'quote_approved'])->count();

        // Converted projects are enquiries that have been formally assigned a job number
        $convertedProjects = ProjectEnquiry::whereNotNull('job_number')->count();

        $totalBudget = ProjectEnquiry::whereNotNull('estimated_budget')
            ->sum('estimated_budget');

        $projectsByStatus = ProjectEnquiry::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $averageProjectDuration = $this->calculateAverageProjectDuration();

        // Table 'phases' might not exist yet
        $phasesByStatus = [];
        try {
            // $phasesByStatus = Phase::select('status', DB::raw('count(*) as count'))
            //     ->groupBy('status')
            //     ->pluck('count', 'status')
            //     ->toArray();
        } catch (\Exception $e) {
            \Log::warning('Phases table not found or error');
        }

        $phaseProgress = $this->getPhaseProgress();

        \Log::info('Project Metrics calculated', ['active' => $activeProjects]);

        return [
            'total_projects' => ProjectEnquiry::where('status', '!=', 'cancelled')->count(),
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
            ->whereNotNull('created_at')
            ->whereNotNull('updated_at')
            ->get();

        if ($completedProjects->isEmpty()) return null;

        $totalDays = $completedProjects->sum(function ($project) {
            try {
                if (!$project->created_at || !$project->updated_at) {
                    return 0;
                }
                return Carbon::parse($project->created_at)->diffInDays(Carbon::parse($project->updated_at));
            } catch (\Exception $e) {
                \Log::warning('Error calculating duration for project', [
                    'project_id' => $project->id,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at,
                    'error' => $e->getMessage()
                ]);
                return 0;
            }
        });

        return round($totalDays / $completedProjects->count(), 1);
    }

    /**
     * Get phase progress metrics
     */
    private function getPhaseProgress(): array
    {
        // Table 'phases' might not exist yet
        return [];
        /*
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
        */
    }

    /**
     * Get financial metrics for dashboard
     */
    public function getFinancialMetrics(): array
    {
        \Log::info('Calculating Financial Metrics');
        try {
            $relevantStatuses = [
                'design_completed',
                'design_approved',
                'materials_specified',
                'budget_created',
                'quote_prepared',
                'quote_approved',
                'planning',
                'in_progress',
                'completed'
            ];

            $projectIds = ProjectEnquiry::whereIn('status', $relevantStatuses)
                ->pluck('id')
                ->toArray();

            \Log::info('Financial Metrics - Found projects', ['count' => count($projectIds)]);

            // 1. Revenue Analysis
            // Projected Revenue: Sum of estimated_budget for all relevant projects
            $projectedRevenue = (float)ProjectEnquiry::whereIn('id', $projectIds)->sum('estimated_budget');

            // Approved Revenue: Sum of approved quotes
            $approvedRevenue = (float)DB::table('task_quote_data')
                ->join('enquiry_tasks', 'task_quote_data.enquiry_task_id', '=', 'enquiry_tasks.id')
                ->whereIn('enquiry_tasks.project_enquiry_id', $projectIds)
                ->where('task_quote_data.status', 'approved')
                ->sum('quote_amount');

            \Log::info('Financial Metrics - Revenue', ['projected' => $projectedRevenue, 'approved' => $approvedRevenue]);

            // Effective Revenue for dashboard display (prioritize approved, fallback to projected)
            // But for a realistic dashboard, we might want to show both or a smart mix.
            // Let's use Approved for the main card if it exists, otherwise projected.
            // Actually, let's use the Sum of (Approved for those that have it, Projected for those that dont)

            $effectiveRevenue = 0;
            $projectsWithBudget = ProjectEnquiry::whereIn('id', $projectIds)->get();
            foreach ($projectsWithBudget as $p) {
                try {
                    $qTotal = DB::table('task_quote_data')
                        ->join('enquiry_tasks', 'task_quote_data.enquiry_task_id', '=', 'enquiry_tasks.id')
                        ->where('enquiry_tasks.project_enquiry_id', $p->id)
                        ->where('task_quote_data.status', 'approved')
                        ->sum('quote_amount');

                    if ($qTotal > 0) {
                        $effectiveRevenue += (float)$qTotal;
                    } else {
                        $effectiveRevenue += (float)($p->estimated_budget ?? 0);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Error calculating revenue for project', [
                        'project_id' => $p->id,
                        'error' => $e->getMessage()
                    ]);
                    $effectiveRevenue += (float)($p->estimated_budget ?? 0);
                }
            }

            // 2. Cost Analysis
            // Budgeted Cost: Sum of budget field or budget tasks
            $budgetedCost = (float)ProjectEnquiry::whereIn('id', $projectIds)->sum('budget');

            $taskBudgets = 0;
            $budgets = DB::table('task_budget_data')
                ->join('enquiry_tasks', 'task_budget_data.enquiry_task_id', '=', 'enquiry_tasks.id')
                ->whereIn('enquiry_tasks.project_enquiry_id', $projectIds)
                ->select('task_budget_data.budget_summary')
                ->get();

            foreach ($budgets as $b) {
                try {
                    $summary = is_string($b->budget_summary) ? json_decode($b->budget_summary, true) : $b->budget_summary;
                    $taskBudgets += (float)($summary['grandTotal'] ?? 0);
                } catch (\Exception $e) {
                    \Log::warning('Error parsing budget summary', [
                        'budget_summary' => $b->budget_summary,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            \Log::info('Financial Metrics - Costs', ['field_sum' => $budgetedCost, 'task_sum' => $taskBudgets]);

            $effectiveCost = $taskBudgets > 0 ? $taskBudgets : $budgetedCost;

            $profit = $effectiveRevenue - $effectiveCost;
            $margin = $effectiveRevenue > 0 ? round(($profit / $effectiveRevenue) * 100, 2) : 0;

            \Log::info('Financial Metrics calculated', ['revenue' => $effectiveRevenue, 'cost' => $effectiveCost]);

            return [
                'revenue' => $effectiveRevenue,
                'approved_revenue' => $approvedRevenue,
                'projected_revenue' => $projectedRevenue,
                'cost' => $effectiveCost,
                'profit' => $profit,
                'margin' => $margin,
            ];
        } catch (\Exception $e) {
            \Log::error('Error calculating financial metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'revenue' => 0,
                'approved_revenue' => 0,
                'projected_revenue' => 0,
                'cost' => 0,
                'profit' => 0,
                'margin' => 0,
            ];
        }
    }

    /**
     * Get system-wide metadata for the dashboard
     */
    public function getMetadata(): array
    {
        return [
            'status_labels' => [
                'client_registered' => 'Registered',
                'enquiry_logged' => 'Logged',
                'site_survey_completed' => 'Survey Done',
                'design_completed' => 'Design Done',
                'design_approved' => 'Design Approved',
                'materials_specified' => 'Materials',
                'budget_created' => 'Budgeted',
                'quote_prepared' => 'Quoted',
                'quote_approved' => 'Quote OK',
                'planning' => 'Planning',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled'
            ],
            'priority_labels' => [
                'low' => 'Low',
                'medium' => 'Medium',
                'high' => 'High',
                'urgent' => 'Urgent'
            ],
            'currency' => [
                'symbol' => 'Ksh',
                'code' => 'KES'
            ],
            'thresholds' => [
                'workload' => self::WORKLOAD_THRESHOLD,
                'stale_quote' => self::STALE_QUOTE_DAYS,
                'stale_in_progress' => self::STALE_IN_PROGRESS_DAYS,
                'poor_margin' => self::POOR_MARGIN_THRESHOLD
            ]
        ];
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
                    'description' => "Enquiry #{$enquiry->enquiry_number} for " . ($enquiry->client->full_name ?? 'Unknown Client'),
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
                $deptName = $task->department->name ?? 'Internal';
                return [
                    'type' => 'task_updated',
                    'title' => 'Task status updated',
                    'description' => "{$task->title} - {$deptName}",
                    'timestamp' => $task->updated_at->toISOString(),
                    'status' => $task->status
                ];
            });

        // Recent phase updates (safeguard)
        $recentPhases = [];
        try {
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
        } catch (\Exception $e) {
            \Log::warning('Phases table missing in activities: ' . $e->getMessage());
        }

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
                    'id' => uniqid('alert_'),
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
                    'id' => uniqid('alert_'),
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
                    'id' => uniqid('alert_'),
                    'type' => 'stale_high_priority',
                    'severity' => 'medium',
                    'title' => 'High Priority Enquiry Needs Attention',
                    'message' => "Urgent enquiry #{$enquiry->enquiry_number} hasn't been updated recently",
                    'action_url' => "/projects/enquiries/{$enquiry->id}"
                ];
            });

        return collect([...$overdueTasks, ...$upcomingDeadlines, ...$staleHighPriority])
            ->sortByDesc(function ($alert) {
                $severityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                return $severityOrder[$alert['severity']] ?? 0;
            })
            ->values()
            ->toArray();
    }

    /**
     * Get intelligence suggestions for the dashboard
     */
    public function getSuggestions(): array
    {
        $suggestions = [];

        // 1. Departmental Overload Suggestion
        $deptLoad = EnquiryTask::select('department_id', DB::raw('count(*) as count'))
            ->whereIn('status', ['pending', 'in_progress'])
            ->groupBy('department_id')
            ->orderBy('count', 'desc')
            ->first();

        if ($deptLoad && $deptLoad->count > self::WORKLOAD_THRESHOLD) {
            $deptName = \App\Modules\HR\Models\Department::find($deptLoad->department_id)->name ?? 'Department';
            $suggestions[] = [
                'type' => 'workload',
                'priority' => 'high',
                'title' => 'Capacity Alert: ' . $deptName,
                'message' => "{$deptName} is currently managing {$deptLoad->count} active items. Consider re-allocating resources to maintain deployment velocity.",
                'icon' => 'mdi-account-group-outline',
                'action_label' => 'View Workload',
                'action_url' => '/projects/dashboard/command-center'
            ];
        }

        // 2. Conversion Bottleneck (Stale Quoting)
        $staleQuotes = ProjectEnquiry::where('status', 'quote_prepared')
            ->where('updated_at', '<', Carbon::now()->subDays(self::STALE_QUOTE_DAYS))
            ->count();

        if ($staleQuotes > 0) {
            $suggestions[] = [
                'type' => 'conversion',
                'priority' => 'medium',
                'title' => 'Quote Follow-up Required',
                'message' => "{$staleQuotes} enquiries have been in 'Quoting' for over 5 days. Reach out to clients to expedite approval.",
                'icon' => 'mdi-file-clock-outline',
                'action_label' => 'View Quotes',
                'action_url' => '/projects/enquiries?status=quote_prepared'
            ];
        }

        // 3. Profit Margin Optimization
        $allProjects = ProjectEnquiry::whereIn('status', ['completed', 'in_progress', 'planning', 'quote_approved', 'materials_specified', 'budget_created'])->get();
        
        if ($allProjects->count() > 0) {
            $avgMargin = $allProjects->avg(function($p) {
                $rev = $p->estimated_budget ?? 0;
                $cost = $p->budget ?? 0;
                
                if ($cost == 0 && $rev > 0) {
                    $cost = $rev * 0.70; // Fallback estimate
                }
                
                return $rev > 0 ? (($rev - $cost) / $rev) * 100 : 0;
            });

            if ($avgMargin < self::POOR_MARGIN_THRESHOLD && $avgMargin > 0) {
                $suggestions[] = [
                    'type' => 'finance',
                    'priority' => 'urgent',
                    'title' => 'Profitability Insight',
                    'message' => "Current aggregate project margin is running at " . round($avgMargin, 1) . "%. Review operational overheads and material procurement costs.",
                    'icon' => 'mdi-finance',
                    'action_label' => 'Financial Audit',
                    'action_url' => '/finance/petty-cash/analytics'
                ];
            }
        }

        // 4. Deployment Stagnation
        $staleInProgress = ProjectEnquiry::where('status', 'in_progress')
            ->where('updated_at', '<', Carbon::now()->subDays(self::STALE_IN_PROGRESS_DAYS))
            ->count();
        
        if ($staleInProgress > 5) {
            $suggestions[] = [
                'type' => 'operational',
                'priority' => 'medium',
                'title' => 'Execution Velocity',
                'message' => "{$staleInProgress} active projects haven't reported progress in 48 hours. Check-in with production leads.",
                'icon' => 'mdi-lightning-bolt-outline',
                'action_label' => 'Track Progress',
                'action_url' => '/projects/dashboard'
            ];
        }

        // 5. Time Efficiency (Actual vs Estimated)
        $inefficientTasks = EnquiryTask::where('status', 'completed')
            ->whereNotNull('estimated_hours')
            ->whereNotNull('actual_hours')
            ->whereRaw('actual_hours > estimated_hours * 1.2')
            ->with(['enquiry', 'department'])
            ->limit(5)
            ->get();

        foreach ($inefficientTasks as $task) {
            $suggestions[] = [
                'type' => 'efficiency',
                'priority' => 'low',
                'title' => 'Execution Variance: ' . $task->title,
                'message' => "This task exceeded its estimate by " . round(($task->actual_hours / $task->estimated_hours - 1) * 100) . "%. Analyze blockers in {$task->department->name} to optimize future quotes.",
                'icon' => 'mdi-timer-off-outline',
                'action_label' => 'Audit Task',
                'action_url' => "/projects/enquiries/{$task->project_enquiry_id}"
            ];
        }

        // 6. Hard-Gate Blockers (Next Stage Progression)
        $stuckProjects = ProjectEnquiry::whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('enquiryTasks', function($q) {
                $q->where('status', '!=', 'completed');
            })
            ->with(['enquiryTasks' => function($q) {
                $q->where('status', '!=', 'completed');
            }])
            ->limit(5)
            ->get();

        foreach ($stuckProjects as $p) {
            $criticalTask = $p->enquiryTasks->whereIn('type', ['design', 'materials', 'budget', 'quote', 'production'])->first();
            if ($criticalTask) {
                $suggestions[] = [
                    'type' => 'progression',
                    'priority' => 'medium',
                    'title' => 'Path to Next Stage: ' . $p->enquiry_number,
                    'message' => "Project is currently held in '{$p->status}'. Complete '{$criticalTask->title}' to trigger automatic progression to the next operational phase.",
                    'icon' => 'mdi-arrow-right-bold-circle-outline',
                    'action_label' => 'View Project',
                    'action_url' => "/projects/enquiries/{$p->id}"
                ];
            }
        }

        return $suggestions;
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
            try {
                return $enquiry->created_at ? $enquiry->created_at->format('M Y') : 'Unknown';
            } catch (\Exception $e) {
                \Log::warning('Error formatting date for enquiry', [
                    'enquiry_id' => $enquiry->id,
                    'created_at' => $enquiry->created_at,
                    'error' => $e->getMessage()
                ]);
                return 'Unknown';
            }
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
        $convertedProjects = $enquiries->where('status', 'quote_approved')->count();

        $totalBudget = $enquiries->whereNotNull('estimated_budget')->sum('estimated_budget');

        $projectsByStatus = $enquiries->whereIn('status', ['planning', 'in_progress', 'completed', 'quote_approved'])
            ->groupBy('status')
            ->map->count()
            ->toArray();

        return [
            'total_projects' => $enquiries->count(),
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
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
