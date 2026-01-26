<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\JobCard;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TechnicalLabour;
use App\Modules\Production\Models\DailyTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductionReportsController extends Controller
{
    /**
     * Get production analytics for a date range.
     */
    public function analytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Handle period parameter
        if (isset($validated['period'])) {
            $period = $validated['period'];
            $now = now();
            
            switch ($period) {
                case 'week':
                    $startDate = $now->startOfWeek()->toDateString();
                    $endDate = $now->endOfWeek()->toDateString();
                    break;
                case 'month':
                    $startDate = $now->startOfMonth()->toDateString();
                    $endDate = $now->endOfMonth()->toDateString();
                    break;
                case 'quarter':
                    $startDate = $now->startOfQuarter()->toDateString();
                    $endDate = $now->endOfQuarter()->toDateString();
                    break;
                case 'year':
                    $startDate = $now->startOfYear()->toDateString();
                    $endDate = $now->endOfYear()->toDateString();
                    break;
                default:
                    $startDate = $now->startOfMonth()->toDateString();
                    $endDate = $now->endOfMonth()->toDateString();
            }
        } else {
            $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
            $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        }

        try {
            // Total job cards in period
            $totalJobCards = JobCard::whereBetween('date', [$startDate, $endDate])
                ->count();

            // Completed job cards
            $completedJobCards = JobCard::whereBetween('date', [$startDate, $endDate])
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

            // Calculate total labor cost using daily rates from technical labour
            $totalLaborCost = 0;
            $jobCardsForCost = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->get();
                
            foreach ($jobCardsForCost as $jobCard) {
                if ($jobCard->worker) {
                    $dayRate = $jobCard->worker->day_rate ?? 25.00;
                    $totalLaborCost += $dayRate; // Each job card represents one day of work
                }
            }

            // Get top performers
            $topPerformers = JobCard::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->with('worker')
                ->get()
                ->groupBy('worker_id')
                ->map(function ($jobCards, $workerId) {
                    $totalHours = $jobCards->sum('total_hours');
                    $completedJobs = $jobCards->count();
                    $efficiency = $totalHours > 0 ? min(100, round(($completedJobs / $totalHours) * 100, 1)) : 0;
                    
                    $firstJobCard = $jobCards->first();
                    $workerName = 'Unknown';
                    
                    if ($firstJobCard->worker) {
                        // Handle both technical labour (full_name) and legacy employees (first_name/last_name)
                        $workerName = $firstJobCard->worker->full_name ?? 
                                     ($firstJobCard->worker->first_name . ' ' . $firstJobCard->worker->last_name) ?? 
                                     'Unknown';
                    }
                    
                    return [
                        'technician_id' => (int) $workerId,
                        'technician_name' => $workerName,
                        'job_cards_completed' => $completedJobs,
                        'total_hours' => round($totalHours, 1),
                        'efficiency_rating' => $efficiency
                    ];
                })
                ->sortByDesc('efficiency_rating')
                ->take(10)
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_job_cards' => $totalJobCards,
                    'completed_job_cards' => $completedJobCards,
                    'total_labor_hours' => round($totalLaborHours, 1),
                    'total_overtime_hours' => round($totalOvertimeHours, 1),
                    'total_labor_cost' => round($totalLaborCost, 2),
                    'average_completion_time' => round($avgDailyHours, 1),
                    'technician_utilization' => $utilizationRate,
                    'top_performers' => $topPerformers,
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
    public function technicianReport(Request $request, $technician_id): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Handle period parameter
        if (isset($validated['period'])) {
            $period = $validated['period'];
            $now = now();
            
            switch ($period) {
                case 'week':
                    $startDate = $now->startOfWeek()->toDateString();
                    $endDate = $now->endOfWeek()->toDateString();
                    break;
                case 'month':
                    $startDate = $now->startOfMonth()->toDateString();
                    $endDate = $now->endOfMonth()->toDateString();
                    break;
                case 'quarter':
                    $startDate = $now->startOfQuarter()->toDateString();
                    $endDate = $now->endOfQuarter()->toDateString();
                    break;
                case 'year':
                    $startDate = $now->startOfYear()->toDateString();
                    $endDate = $now->endOfYear()->toDateString();
                    break;
                default:
                    $startDate = $now->startOfMonth()->toDateString();
                    $endDate = $now->endOfMonth()->toDateString();
            }
        } else {
            $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
            $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();
        }

        try {
            // Try to find technical labour first, then fallback to employee
            $technician = TechnicalLabour::find($technician_id);
            
            if (!$technician) {
                $technician = Employee::findOrFail($technician_id);
            }

            // Get job cards for this technician in period
            $jobCards = JobCard::where('worker_id', $technician_id)
                ->whereBetween('date', [$startDate, $endDate])
                ->where('status', 'approved')
                ->get();

            // Calculate totals
            $totalDays = $jobCards->count();
            $totalHours = $jobCards->sum('total_hours');
            $totalOvertime = $jobCards->sum('overtime_hours');
            $regularHours = $totalHours - $totalOvertime;
            
            // Calculate efficiency (jobs completed per hour)
            $efficiency = $totalHours > 0 ? min(100, round(($totalDays / $totalHours) * 100, 1)) : 0;

            // Calculate total cost using fixed daily rate
            $dayRate = $technician->day_rate ?? 25.00;
            $totalCost = $totalDays * $dayRate;

            // Determine technician name and ID based on model type
            if ($technician instanceof TechnicalLabour) {
                $technicianName = $technician->full_name;
                $employeeId = 'TECH-' . $technician->id;
            } else {
                $technicianName = $technician->first_name . ' ' . $technician->last_name;
                $employeeId = $technician->employee_id ?? 'EMP-' . $technician->id;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'technician_id' => $technician->id,
                    'technician_name' => $technicianName,
                    'employee_id' => $employeeId,
                    'total_hours' => round($totalHours, 1),
                    'regular_hours' => round($regularHours, 1),
                    'overtime_hours' => round($totalOvertime, 1),
                    'job_cards_completed' => $totalDays,
                    'efficiency_rating' => round($efficiency, 1),
                    'total_cost' => round($totalCost, 2),
                    'day_rate' => round($dayRate, 2)
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
