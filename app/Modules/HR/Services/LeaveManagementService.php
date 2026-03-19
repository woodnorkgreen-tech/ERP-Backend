<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeaveManagementService
{
    protected function defaultLeaveTypeDefinitions(): array
    {
        return [
            [
                'name' => 'Annual Leave',
                'code' => 'ANNUAL',
                'days_per_year' => 20,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Standard annual leave entitlement.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Leave for maternity and post-delivery recovery.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Leave for fathers after childbirth.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 10,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Leave for illness or medical recovery.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 30,
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Approved leave taken without pay.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
        ];
    }

    public function syncDefaultLeaveTypes(): void
    {
        foreach ($this->defaultLeaveTypeDefinitions() as $leaveType) {
            LeaveType::query()->updateOrCreate(
                ['code' => $leaveType['code']],
                $leaveType
            );
        }

        LeaveType::query()
            ->whereIn('code', ['PARENTAL', 'COMPOFF'])
            ->update(['is_active' => false]);
    }

    public function getRequestableLeaveTypes(): Collection
    {
        $this->syncDefaultLeaveTypes();

        $definitions = collect($this->defaultLeaveTypeDefinitions());
        $order = $definitions->pluck('code')->flip();

        return LeaveType::query()
            ->where('is_active', true)
            ->whereIn('code', $definitions->pluck('code'))
            ->get()
            ->sortBy(fn (LeaveType $leaveType) => $order[$leaveType->code] ?? 999)
            ->values();
    }

    public function canManage(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR', 'Manager'])
            || $user->can('leave.request.approve')
            || $user->can('leave.type.update');
    }

    public function resolveEmployeeForUser(User $user, ?int $employeeId = null): ?Employee
    {
        if ($employeeId) {
            $employee = Employee::findOrFail($employeeId);

            if (!$this->canManage($user) && (int) $user->employee_id !== (int) $employee->id) {
                throw new AuthorizationException('You are not allowed to access this employee leave profile.');
            }

            return $employee;
        }

        if ($user->employee_id) {
            return Employee::find($user->employee_id);
        }

        return null;
    }

    public function calculateBusinessDays(string|Carbon $startDate, string|Carbon $endDate, string $session = 'full_day'): float
    {
        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse($startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('Leave end date must be on or after the start date.');
        }

        if ($session !== 'full_day' && !$start->isSameDay($end)) {
            throw new \InvalidArgumentException('Half-day leave can only be requested for a single date.');
        }

        $days = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if (!$cursor->isWeekend()) {
                $days++;
            }

            $cursor->addDay();
        }

        if ($session !== 'full_day') {
            return $days > 0 ? 0.5 : 0.0;
        }

        return (float) $days;
    }

    public function validateDateRange(string $startDate, string $endDate): void
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        if ($start->year !== $end->year) {
            throw new \InvalidArgumentException('Leave requests must stay within a single calendar year.');
        }
    }

    public function ensureNoOverlap(Employee $employee, string $startDate, string $endDate, ?int $ignoreRequestId = null): void
    {
        $query = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED])
            ->where(function (Builder $builder) use ($startDate, $endDate) {
                $builder
                    ->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function (Builder $nested) use ($startDate, $endDate) {
                        $nested->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        if ($ignoreRequestId) {
            $query->where('id', '!=', $ignoreRequestId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('This employee already has an overlapping leave request.');
        }
    }

    public function getLeaveSummaryForEmployee(Employee $employee, int $year): Collection
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $leaveType) use ($employee, $year) {
                $usedDays = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_APPROVED]);
                $pendingDays = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_PENDING]);

                return [
                    'leave_type_id' => $leaveType->id,
                    'name' => $leaveType->name,
                    'code' => $leaveType->code,
                    'color' => $leaveType->color,
                    'icon' => $leaveType->icon,
                    'allocated_days' => $leaveType->days_per_year,
                    'used_days' => $usedDays,
                    'pending_days' => $pendingDays,
                    'available_days' => max($leaveType->days_per_year - $usedDays, 0),
                ];
            })
            ->values();
    }

    public function getDashboard(User $user, ?int $employeeId = null, ?int $year = null): array
    {
        $this->syncDefaultLeaveTypes();

        $year = $year ?: now()->year;
        $employee = $this->resolveEmployeeForUser($user, $employeeId);
        $canManage = $this->canManage($user);

        return [
            'employee' => $employee ? [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position,
            ] : null,
            'year' => $year,
            'balances' => $employee ? $this->getLeaveSummaryForEmployee($employee, $year) : collect(),
            'recent_requests' => $this->getRecentRequests($user, $employee, $canManage),
            'team_on_leave' => $this->getTeamOnLeave($user),
            'pending_requests_count' => LeaveRequest::query()->where('status', LeaveRequest::STATUS_PENDING)->count(),
            'leave_types' => $this->getRequestableLeaveTypes(),
            'contact_employees' => $this->getContactEmployees($user),
        ];
    }

    public function ensureBalanceAvailable(Employee $employee, LeaveType $leaveType, float $daysRequested, int $year, ?int $ignoreRequestId = null): void
    {
        $approved = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_APPROVED], $ignoreRequestId);
        $pending = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_PENDING], $ignoreRequestId);
        $remaining = $leaveType->days_per_year - ($approved + $pending);

        if ($daysRequested > $remaining) {
            throw new \InvalidArgumentException(sprintf(
                'Requested %.1f day(s), but only %.1f day(s) remain for %s in %d.',
                $daysRequested,
                max($remaining, 0),
                $leaveType->name,
                $year
            ));
        }
    }

    public function getContactEmployees(User $user): Collection
    {
        $query = Employee::query()
            ->with('department:id,name')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name');

        return $query->get(['id', 'employee_id', 'first_name', 'last_name', 'position', 'department_id'])
            ->map(function (Employee $employee) {
                return [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'position' => $employee->position,
                    'department' => $employee->department ? [
                        'id' => $employee->department->id,
                        'name' => $employee->department->name,
                    ] : null,
                ];
            })
            ->values();
    }

    public function getTeamOnLeave(User $user): Collection
    {
        $today = now()->toDateString();
        $query = LeaveRequest::query()
            ->with(['employee.department', 'leaveType'])
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date');

        if (!$this->canManage($user) && $user->department_id) {
            $query->whereHas('employee', function (Builder $builder) use ($user) {
                $builder->where('department_id', $user->department_id);
            });
        }

        return $query->get()->map(function (LeaveRequest $request) {
            return [
                'id' => $request->id,
                'employee_id' => $request->employee_id,
                'employee_name' => $request->employee?->name,
                'position' => $request->employee?->position,
                'department' => $request->employee?->department?->name,
                'leave_type' => $request->leaveType?->name,
                'end_date' => optional($request->end_date)->toDateString(),
            ];
        });
    }

    public function getRecentRequests(User $user, ?Employee $employee, bool $canManage): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'approver', 'contactEmployee.department'])
            ->latest();

        if ($employee && !$canManage) {
            $query->where('employee_id', $employee->id);
        }

        return $query->limit(8)->get();
    }

    protected function sumRequestedDays(
        int $employeeId,
        int $leaveTypeId,
        int $year,
        array $statuses,
        ?int $ignoreRequestId = null
    ): float {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereYear('start_date', $year)
            ->whereIn('status', $statuses);

        if ($ignoreRequestId) {
            $query->where('id', '!=', $ignoreRequestId);
        }

        return (float) $query->sum('days_requested');
    }
}
