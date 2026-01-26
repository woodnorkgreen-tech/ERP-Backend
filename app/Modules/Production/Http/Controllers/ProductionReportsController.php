<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\JobCard;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\DailyTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductionReportsController extends Controller
{
    /**
     * Get production analytics for a date range.
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        try {
            // Total job cards in period
            $totalJobCards = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->count();

            // Total labor hours from approved job cards
            $totalLaborHours = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->sum('total_hours') ?? 0;

            // Total overtime hours
            $totalOvertimeHours = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->sum('overtime_hours') ?? 0;

            // Average daily hours
            $avgDailyHours = $totalJobCards > 0 ? $totalLaborHours / $totalJobCards : 0;

            // Employee utilization
            $activeEmployees = Employee::where('status', 'active')->count();
            $employeesWithWork = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->distinct('worker_id')
                ->count();
            
            $utilizationRate = $activeEmployees > 0 
                ? round(($employeesWithWork / $activeEmployees) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_job_cards' => $totalJobCards,
                    'total_labor_hours' => $totalLaborHours,
                    'total_overtime_hours' => $totalOvertimeHours,
                    'average_daily_hours' => round($avgDailyHours, 2),
                    'active_employees' => $activeEmployees,
                    'employees_with_work' => $employeesWithWork,
                    'utilization_rate' => $utilizationRate,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employee time report for a date range.
     */
    public function getTechnicianReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $employee = Employee::findOrFail($validated['technician_id']);
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        try {
            // Get job cards for this employee in period
            $jobCards = JobCard::where('worker_id', $employee->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->with(['tasks.workOrder', 'issues'])
                ->get();

            // Calculate totals
            $totalDays = $jobCards->count();
            $totalHours = $jobCards->sum('total_hours');
            $totalOvertime = $jobCards->sum('overtime_hours');
            $totalTasks = $jobCards->sum(function ($jobCard) {
                return $jobCard->tasks->count();
            });
            $totalIssues = $jobCards->sum(function ($jobCard) {
                return $jobCard->issues->count();
            });

            // Average hours per day
            $avgHoursPerDay = $totalDays > 0 ? $totalHours / $totalDays : 0;

            // Group tasks by work order
            $workOrderStats = [];
            foreach ($jobCards as $jobCard) {
                foreach ($jobCard->tasks as $task) {
                    if ($task->workOrder) {
                        $woId = $task->workOrder->id;
                        if (!isset($workOrderStats[$woId])) {
                            $workOrderStats[$woId] = [
                                'work_order_number' => $task->workOrder->work_order_number,
                                'title' => $task->workOrder->title,
                                'client_name' => $task->workOrder->client_name ?? 'N/A',
                                'total_hours' => 0,
                                'task_count' => 0
                            ];
                        }
                        $workOrderStats[$woId]['total_hours'] += $task->hours_worked;
                        $workOrderStats[$woId]['task_count']++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->first_name . ' ' . $employee->last_name,
                        'employee_number' => $employee->employee_id
                    ],
                    'summary' => [
                        'total_days_worked' => $totalDays,
                        'total_hours' => $totalHours,
                        'total_overtime_hours' => $totalOvertime,
                        'average_hours_per_day' => round($avgHoursPerDay, 2),
                        'total_tasks' => $totalTasks,
                        'total_issues' => $totalIssues
                    ],
                    'work_order_breakdown' => array_values($workOrderStats),
                    'daily_breakdown' => $jobCards->map(function ($jobCard) {
                        return [
                            'date' => $jobCard->date,
                            'total_hours' => $jobCard->total_hours,
                            'overtime_hours' => $jobCard->overtime_hours,
                            'task_count' => $jobCard->tasks->count(),
                            'issue_count' => $jobCard->issues->count()
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get technician report: ' . $e->getMessage()
            ], 500);
        }
    }
}
