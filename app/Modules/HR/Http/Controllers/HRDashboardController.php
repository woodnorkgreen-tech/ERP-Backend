<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\OTFlag;
use App\Modules\HR\Models\PayrollRun;
use Illuminate\Http\JsonResponse;

class HRDashboardController extends Controller
{
    /**
     * Cross-domain HR overview, aggregated server-side.
     *
     * Replaces the previous client-side aggregation (which fetched the entire
     * employee list and computed everything in the browser). All figures are
     * produced with scoped aggregate queries — no per-row N+1.
     */
    public function overview(): JsonResponse
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // Fresh scoped query each time so access rules apply to every metric.
        $employees = fn () => Employee::query()->accessibleByUser();

        $headcount = [
            'total'          => $employees()->count(),
            'active'         => $employees()->where('status', 'active')->count(),
            'on_leave'       => $employees()->where('status', 'on-leave')->count(),
            'inactive'       => $employees()->whereIn('status', ['inactive', 'terminated'])->count(),
            'new_this_month' => $employees()->where('hire_date', '>=', $monthStart)->count(),
        ];

        // Department breakdown (active staff), grouped in SQL then named from a
        // small lookup map to avoid a join against the access-scoped query.
        $deptNames = Department::pluck('name', 'id');
        $departments = $employees()
            ->where('status', 'active')
            ->selectRaw('department_id, COUNT(*) as count')
            ->groupBy('department_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'name'  => $deptNames[$row->department_id] ?? 'No Department',
                'count' => (int) $row->count,
            ])
            ->values();

        $recentHires = $employees()
            ->orderByDesc('hire_date')
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'position', 'hire_date'])
            ->map(fn ($e) => [
                'id'         => $e->id,
                'first_name' => $e->first_name,
                'last_name'  => $e->last_name,
                'position'   => $e->position,
                'hire_date'  => optional($e->hire_date)->toDateString(),
            ]);

        $pendingApprovals = [
            'leave'    => LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count(),
            'overtime' => OTEntry::whereIn('status', ['submitted', 'under_review'])->count(),
        ];

        $onLeaveToday = LeaveRequest::where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();

        $fatigueAlerts = OTFlag::where('type', 'fatigue_risk')
            ->whereNull('resolved_at')
            ->count();

        $latestRun = PayrollRun::orderByDesc('payroll_month')->first();
        $payroll = $latestRun ? [
            'month'     => $latestRun->payroll_month,
            'status'    => $latestRun->status,
            'total_net' => (float) $latestRun->total_net,
        ] : null;

        return response()->json([
            'headcount'         => $headcount,
            'departments'       => $departments,
            'recent_hires'      => $recentHires,
            'pending_approvals' => $pendingApprovals,
            'on_leave_today'    => $onLeaveToday,
            'fatigue_alerts'    => $fatigueAlerts,
            'payroll'           => $payroll,
        ]);
    }
}
