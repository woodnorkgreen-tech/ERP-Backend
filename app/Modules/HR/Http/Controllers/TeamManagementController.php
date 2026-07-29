<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Compensation;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\OTEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TeamManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $teamIds = $this->teamEmployeeIds($user);

        if ($teamIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $this->emptySummary(),
                    'team_members' => [],
                    'leave_requests' => [],
                    'overtime_entries' => [],
                    'compensations' => [],
                    'attendance_records' => [],
                ],
            ]);
        }

        $from = $request->date('date_from')?->toDateString() ?? now()->subDays(14)->toDateString();
        $to = $request->date('date_to')?->toDateString() ?? now()->toDateString();
        $isGlobal = $this->isGlobalReviewer($user);
        $approvalStatuses = $isGlobal ? ['submitted', 'under_review'] : ['submitted'];
        $compensationStatuses = $isGlobal ? ['pending', 'under_review'] : ['pending'];

        $teamMembers = Employee::query()
            ->with('department')
            ->whereIn('id', $teamIds)
            ->orderBy('first_name')
            ->get();

        $leaveRequests = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'leadApprover'])
            ->whereIn('employee_id', $teamIds)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_LEAD_APPROVED])
            ->latest()
            ->limit(20)
            ->get();

        $overtimeEntries = OTEntry::query()
            ->with(['employee.department', 'project', 'supervisorApprover', 'flags'])
            ->whereIn('employee_id', $teamIds)
            ->whereIn('status', $approvalStatuses)
            ->latest()
            ->limit(20)
            ->get();

        $compensations = Compensation::query()
            ->with(['employee.department', 'supervisorApprover'])
            ->whereIn('employee_id', $teamIds)
            ->whereIn('status', $compensationStatuses)
            ->latest()
            ->limit(20)
            ->get();

        $attendanceRecords = AttendanceRecord::query()
            ->with('employee.department')
            ->whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$from, $to])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->latest('date')
            ->latest('id')
            ->limit(40)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'team_members' => $teamMembers->count(),
                    'pending_leave' => $leaveRequests->where('status', LeaveRequest::STATUS_PENDING)->count(),
                    'lead_approved_leave' => $leaveRequests->where('status', LeaveRequest::STATUS_LEAD_APPROVED)->count(),
                    'pending_overtime' => $overtimeEntries->where('status', 'submitted')->count(),
                    'under_review_overtime' => $overtimeEntries->where('status', 'under_review')->count(),
                    'pending_time_off' => $compensations->where('status', 'pending')->count(),
                    'attendance_exceptions' => $attendanceRecords
                        ->whereIn('status', ['absent', 'late', 'missing_clock_out', 'early_departure', 'half_day'])
                        ->count(),
                    'attendance_from' => $from,
                    'attendance_to' => $to,
                ],
                'team_members' => $teamMembers,
                'leave_requests' => $leaveRequests,
                'overtime_entries' => $overtimeEntries,
                'compensations' => $compensations,
                'attendance_records' => $attendanceRecords,
            ],
        ]);
    }

    private function teamEmployeeIds($user): Collection
    {
        if (!$user?->employee_id && !$this->isGlobalReviewer($user)) {
            return collect();
        }

        $query = Employee::query()->active();

        if ($this->isGlobalReviewer($user)) {
            return $query
                ->when($user->employee_id, fn (Builder $builder) => $builder->whereKeyNot($user->employee_id))
                ->pluck('id');
        }

        $managedDepartmentIds = Department::query()
            ->where('manager_id', $user->employee_id)
            ->pluck('id');

        return $query
            ->where(function (Builder $builder) use ($user, $managedDepartmentIds) {
                $builder->where('manager_id', $user->employee_id);

                if ($managedDepartmentIds->isNotEmpty()) {
                    $builder->orWhereIn('department_id', $managedDepartmentIds);
                }
            })
            ->whereKeyNot($user->employee_id)
            ->pluck('id')
            ->unique()
            ->values();
    }

    private function isGlobalReviewer($user): bool
    {
        return (bool) $user?->hasRole(['Super Admin', 'Admin', 'HR', 'HR Admin']);
    }

    private function emptySummary(): array
    {
        return [
            'team_members' => 0,
            'pending_leave' => 0,
            'lead_approved_leave' => 0,
            'pending_overtime' => 0,
            'under_review_overtime' => 0,
            'pending_time_off' => 0,
            'attendance_exceptions' => 0,
            'attendance_from' => now()->subDays(14)->toDateString(),
            'attendance_to' => now()->toDateString(),
        ];
    }
}
