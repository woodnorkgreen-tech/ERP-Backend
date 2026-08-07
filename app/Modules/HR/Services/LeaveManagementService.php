<?php

namespace App\Modules\HR\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\HR\Models\AttendanceHoliday;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceAdjustment;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LeaveManagementService
{
    protected function defaultLeaveTypeDefinitions(): array
    {
        return [
            [
                'name' => 'Annual Leave',
                'code' => 'ANNUAL',
                'days_per_year' => 21,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Annual leave entitlement: 21 working days per calendar year.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Kenya statutory maternity leave: 3 months with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Kenya statutory paternity leave: 2 weeks with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 14,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Kenya statutory sick leave baseline: 7 full-pay days and 7 half-pay days (total 14 days); half-pay applies after two months of service.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Special Leave',
                'code' => 'SPECIAL',
                'days_per_year' => 5,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'purple',
                'icon' => 'mdi-heart-outline',
                'description' => 'Leave for special circumstances such as compassionate leave, family emergencies, or other personal matters. Up to 5 days per year. Requires explanation of the reason.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Compensatory Leave',
                'code' => 'COMPENSATORY',
                'days_per_year' => 0,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'orange',
                'icon' => 'mdi-clock-outline',
                'description' => 'Time off in lieu of overtime worked or work performed on holidays/weekends. Requires explanation of the work being compensated.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 0,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Policy-controlled unpaid leave. No statutory monthly accrual.',
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
            || $user->isDeptLead()
            || $user->can('leave.request.approve')
            || $user->can('leave.type.update');
    }

    public function isGlobalManager(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR', 'Manager'])
            || $user->can('leave.request.approve')
            || $user->can('leave.type.update');
    }

    public function isHRLevel(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR']);
    }

    public function resolveEmployeeForUser(User $user, ?int $employeeId = null): ?Employee
    {
        if ($employeeId) {
            $employee = Employee::findOrFail($employeeId);

            if ((int) $user->employee_id === (int) $employee->id) {
                return $employee;
            }

            if ($this->isGlobalManager($user)) {
                return $employee;
            }

            if ($user->isDeptLead()) {
                $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
                if (in_array($employee->department_id, $accessibleDeptIds)) {
                    return $employee;
                }
            }

            throw new AuthorizationException('You are not allowed to access this employee leave profile.');
        }

        if ($user->employee_id) {
            return Employee::find($user->employee_id);
        }

        return null;
    }

    public function calculateBusinessDays(string|Carbon $startDate, string|Carbon $endDate, string $session = 'full_day', ?LeaveType $leaveType = null): float
    {
        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse($startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('Leave end date must be on or after the start date.');
        }

        if ($session !== 'full_day' && !$start->isSameDay($end)) {
            throw new \InvalidArgumentException('Half-day leave can only be requested for a single date.');
        }

        if ($this->usesCalendarDays($leaveType)) {
            $days = $start->diffInDays($end) + 1;

            return $session !== 'full_day' ? 0.5 : (float) $days;
        }

        $holidays = $this->holidayDateLookup($start, $end);
        $days = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if (!$cursor->isSunday() && !$holidays->has($cursor->toDateString())) {
                $days++;
            }

            $cursor->addDay();
        }

        if ($session !== 'full_day') {
            return $days > 0 ? 0.5 : 0.0;
        }

        return (float) $days;
    }

    public function calculateResumptionDate(string|Carbon $endDate): Carbon
    {
        $resumptionDate = $endDate instanceof Carbon
            ? $endDate->copy()->startOfDay()
            : Carbon::parse($endDate)->startOfDay();

        do {
            $resumptionDate->addDay();
        } while ($resumptionDate->isSunday() || $this->isHoliday($resumptionDate));

        return $resumptionDate;
    }

    public function usesCalendarDays(?LeaveType $leaveType): bool
    {
        return in_array($leaveType?->code, ['MATERNITY', 'PATERNITY'], true);
    }

    public function getHolidaysForYear(int $year): Collection
    {
        if (!Schema::hasTable('attendance_holidays')) {
            return collect();
        }

        return AttendanceHoliday::query()
            ->whereYear('date', $year)
            ->where('is_active', true)
            ->orderBy('date')
            ->get(['id', 'date', 'name'])
            ->map(fn (AttendanceHoliday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ])
            ->values();
    }

    protected function holidayDateLookup(Carbon $start, Carbon $end): Collection
    {
        if (!Schema::hasTable('attendance_holidays')) {
            return collect();
        }

        return AttendanceHoliday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('is_active', true)
            ->get(['date', 'name'])
            ->mapWithKeys(fn (AttendanceHoliday $holiday) => [$holiday->date->toDateString() => $holiday->name]);
    }

    protected function isHoliday(Carbon $date): bool
    {
        if (!Schema::hasTable('attendance_holidays')) {
            return false;
        }

        return AttendanceHoliday::query()
            ->whereDate('date', $date->toDateString())
            ->where('is_active', true)
            ->exists();
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
            ->where('code', '!=', 'UNPAID')
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $leaveType) use ($employee, $year) {
                $usedDays = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_APPROVED]);
                $pendingDays = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [
                    LeaveRequest::STATUS_PENDING,
                    LeaveRequest::STATUS_LEAD_APPROVED,
                ]);
                $metrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $year);
                $carryForwardDays = $this->calculateCarryForwardDays($employee, $leaveType, $year);
                $adjustmentDays = $this->sumAdjustmentDays($employee->id, $leaveType->id, $year);

                $totalAvailable = $metrics['earned_days'] + $carryForwardDays;
                // Available is the employee's actual balance after approved leave and manual adjustments.
                // Pending requests are shown separately and only reserve requestable days.
                $accruedRemaining = max($totalAvailable - $usedDays - $adjustmentDays, 0);
                $requestableDays = $leaveType->allow_advance
                    ? max($metrics['year_entitlement_days'] + $carryForwardDays - ($usedDays + $adjustmentDays + $pendingDays), 0)
                    : max($accruedRemaining - $pendingDays, 0);
                $advanceAvailableDays = $leaveType->allow_advance
                    ? max($requestableDays - $accruedRemaining, 0)
                    : 0;

                return [
                    'leave_type_id' => $leaveType->id,
                    'name' => $leaveType->name,
                    'code' => $leaveType->code,
                    'color' => $leaveType->color,
                    'icon' => $leaveType->icon,
                    'allocated_days' => $metrics['year_entitlement_days'],
                    'earned_days' => $metrics['earned_days'],
                    'carry_forward_days' => $carryForwardDays,
                    'used_days' => $usedDays,
                    'pending_days' => $pendingDays,
                    'adjustment_days' => $adjustmentDays,
                    'available_days' => $accruedRemaining,
                    'requestable_days' => $requestableDays,
                    'advance_available_days' => $advanceAvailableDays,
                    'allow_advance' => (bool) $leaveType->allow_advance,
                ];
            })
            ->values();
    }

    /**
     * @param  int|null  $perPage  Pass null for the full, unpaginated set (used by the export,
     *                             which must always cover every accessible employee).
     */
    public function getLeaveRegister(User $user, int $year, ?int $perPage = null, int $page = 1): Collection|LengthAwarePaginator
    {
        $this->syncDefaultLeaveTypes();

        $query = Employee::query()
            ->accessibleByUser($user)
            ->active()
            ->with('department:id,name')
            ->orderBy('first_name');

        $mapEmployee = fn (Employee $employee) => $this->buildRegisterEntry($employee, $year);

        if ($perPage === null) {
            return $query->get()->map($mapEmployee)->values();
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->through($mapEmployee);
    }

    protected function buildRegisterEntry(Employee $employee, int $year): array
    {
        $balances = $this->getLeaveSummaryForEmployee($employee, $year);
        // Allocated/remaining track Annual Leave specifically — it's the balance that
        // actually gates future requests. Maternity/Paternity/Sick/Special are distinct,
        // situational entitlements; summing their allocations together would be a
        // meaningless number. "Used" is genuinely additive across every type, though.
        $annualBalance = $balances->firstWhere('code', 'ANNUAL');

        $instances = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->with('leaveType:id,name,code,color')
            ->orderBy('start_date')
            ->get()
            ->map(function (LeaveRequest $request) {
                return [
                    'leave_request_id' => $request->id,
                    'leave_type_id' => $request->leave_type_id,
                    'leave_type_name' => $request->leaveType?->name,
                    'leave_type_code' => $request->leaveType?->code,
                    'leave_type_color' => $request->leaveType?->color,
                    'start_date' => $request->start_date?->toDateString(),
                    'end_date' => $request->end_date?->toDateString(),
                    'days_requested' => $request->days_requested,
                    'session' => $request->session,
                    'reason' => $request->reason,
                ];
            })
            ->values();

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position,
            ],
            'balances' => $balances->values(),
            'total_allocated_days' => (float) ($annualBalance['allocated_days'] ?? 0),
            'total_used_days' => (float) $balances->sum('used_days'),
            'total_remaining_days' => (float) ($annualBalance['available_days'] ?? 0),
            'instances' => $instances,
        ];
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
                'gender' => $employee->gender,
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
            'holidays' => $this->getHolidaysForYear($year),
        ];
    }

    public function getHandoverProjects(?string $search = null, int $limit = 20): array
    {
        $search = trim((string) $search);
        $limit = max(1, min($limit, 50));

        $projectsQuery = Project::query()
            ->with(['enquiry:id,title,job_number,status']);

        if ($search !== '') {
            $projectsQuery->where(function (Builder $query) use ($search) {
                $query
                    ->where('project_id', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('enquiry', function (Builder $enquiryQuery) use ($search) {
                        $enquiryQuery
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('job_number', 'like', "%{$search}%")
                            ->orWhere('status', 'like', "%{$search}%");
                    });
            });
        }

        $projects = $projectsQuery
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'enquiry_id', 'project_id', 'status', 'created_at', 'updated_at'])
            ->filter(fn (Project $project) => filled($project->project_id) || filled($project->enquiry?->title))
            ->map(fn (Project $project) => [
                'key' => 'project-' . $project->id,
                'source' => 'project',
                'source_id' => $project->id,
                'project_id' => $project->project_id ?: $project->enquiry?->job_number ?: $project->enquiry?->project_id,
                'name' => $project->enquiry?->title ?: $project->project_id,
                'status' => $project->status,
            ]);

        $enquiriesQuery = ProjectEnquiry::query();

        if ($search !== '') {
            $enquiriesQuery->where(function (Builder $query) use ($search) {
                $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('job_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        $enquiries = $enquiriesQuery
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'status', 'job_number'])
            ->filter(fn (ProjectEnquiry $enquiry) => filled($enquiry->title) || filled($enquiry->job_number))
            ->map(fn (ProjectEnquiry $enquiry) => [
                'key' => 'enquiry-' . $enquiry->id,
                'source' => 'enquiry',
                'source_id' => $enquiry->id,
                'project_id' => $enquiry->job_number,
                'name' => $enquiry->title ?: $enquiry->job_number,
                'status' => $enquiry->status,
            ]);

        return $projects
            ->concat($enquiries)
            ->sortByDesc('source_id')
            ->take($limit)
            ->values()
            ->all();
    }

    public function ensureBalanceAvailable(
        Employee $employee,
        LeaveType $leaveType,
        float $daysRequested,
        int $year,
        ?int $ignoreRequestId = null,
        string|Carbon|null $asOfDate = null
    ): void
    {
        $approved = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_APPROVED], $ignoreRequestId);
        $pending = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_LEAD_APPROVED,
        ], $ignoreRequestId);

        if ($leaveType->code === 'UNPAID') {
            return;
        }

        $metrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $year, $asOfDate);
        $carryForwardDays = $this->calculateCarryForwardDays($employee, $leaveType, $year);
        $adjustmentDays = $this->sumAdjustmentDays($employee->id, $leaveType->id, $year);
        $remaining = $leaveType->allow_advance
            ? ($metrics['year_entitlement_days'] + $carryForwardDays) - ($approved + $pending + $adjustmentDays)
            : ($metrics['earned_days'] + $carryForwardDays) - ($approved + $pending + $adjustmentDays);

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

    protected function getLeaveEntitlementMetrics(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        string|Carbon|null $asOfDate = null
    ): array
    {
        if (!$leaveType->monthly_accrual_rate) {
            return [
                'year_entitlement_days' => (float) $leaveType->days_per_year,
                'earned_days' => (float) $leaveType->days_per_year,
            ];
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $hireDate = $employee->hire_date
            ? ($employee->hire_date instanceof Carbon
                ? $employee->hire_date->copy()->startOfDay()
                : Carbon::parse($employee->hire_date)->startOfDay())
            : $yearStart->copy();

        if ($hireDate->gt($yearEnd)) {
            return [
                'year_entitlement_days' => 0.0,
                'earned_days' => 0.0,
            ];
        }

        $accrualStart = $hireDate->greaterThan($yearStart) ? $hireDate : $yearStart;
        $effectiveAsOf = $asOfDate instanceof Carbon
            ? $asOfDate->copy()->endOfDay()
            : ($asOfDate ? Carbon::parse($asOfDate)->endOfDay() : now()->endOfDay());

        $eligibleMonthsInYear = $this->countInclusiveMonths($accrualStart, $yearEnd);
        $earnedMonths = $effectiveAsOf->lt($accrualStart)
            ? 0
            : min($eligibleMonthsInYear, $this->countInclusiveMonths($accrualStart, $effectiveAsOf));

        $yearEntitlement = min((float) $leaveType->days_per_year, $eligibleMonthsInYear * (float) $leaveType->monthly_accrual_rate);
        $earnedDays = min($yearEntitlement, $earnedMonths * (float) $leaveType->monthly_accrual_rate);

        return [
            'year_entitlement_days' => round($yearEntitlement, 2),
            'earned_days' => round($earnedDays, 2),
        ];
    }

    protected function countInclusiveMonths(Carbon $startDate, Carbon $endDate): int
    {
        if ($endDate->lt($startDate)) {
            return 0;
        }

        return (($endDate->year - $startDate->year) * 12)
            + ($endDate->month - $startDate->month)
            + 1;
    }

    public function getContactEmployees(User $user): Collection
    {
        $query = Employee::query()
            ->with('department:id,name')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name');

        return $query->get(['id', 'employee_id', 'first_name', 'last_name', 'position', 'department_id', 'gender'])
            ->map(function (Employee $employee) {
                return [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'gender' => $employee->gender,
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

    protected function sumAdjustmentDays(int $employeeId, int $leaveTypeId, int $year): float
    {
        return (float) LeaveBalanceAdjustment::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->sum('days');
    }

    public function calculateCarryForwardDays(Employee $employee, LeaveType $leaveType, int $year): float
    {
        // Only calculate carry-forward for leave types with monthly accrual
        if (!$leaveType->monthly_accrual_rate) {
            return 0;
        }

        $previousYear = $year - 1;
        
        // Get metrics for the previous year
        $previousYearMetrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $previousYear);
        $previousYearUsed = $this->sumRequestedDays($employee->id, $leaveType->id, $previousYear, [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_RECALLED])
            + $this->sumAdjustmentDays($employee->id, $leaveType->id, $previousYear);

        $unusedPreviousYear = $previousYearMetrics['earned_days'] - $previousYearUsed;
        
        // Maximum carry-forward allowed (typically cannot carry more than annual entitlement)
        $maxCarryForward = min((float) $leaveType->days_per_year, $previousYearMetrics['earned_days']);
        
        // Only carry forward up to the max allowed, and only if there are unused days
        return min(max($unusedPreviousYear, 0), $maxCarryForward);
    }

    public function restoreLeaveBalance(LeaveRequest $leaveRequest, ?float $daysToRestore = null): void
    {
        $employee = $leaveRequest->employee;
        $leaveType = $leaveRequest->leaveType;
        $year = $leaveRequest->start_date->year;

        // Use provided days or default to full request days
        $daysToRestore = (float) ($daysToRestore ?? $leaveRequest->days_requested);

        // Get current leave summary for this leave type
        $summary = $this->getLeaveSummaryForEmployee($employee, $year);
        $leaveBalance = $summary->firstWhere('leave_type_id', $leaveType->id);

        if (!$leaveBalance) {
            return;
        }

        // Calculate the new values
        $currentUsedDays = (float) $leaveBalance['used_days'];
        $currentAvailableDays = (float) $leaveBalance['available_days'];

        // Restore the specified days back to the balance
        $newUsedDays = max(0, $currentUsedDays - $daysToRestore);
        $newAvailableDays = $currentAvailableDays + $daysToRestore;

        // Log the restoration for audit purposes
        \Log::info('Leave balance restored', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $year,
            'days_restored' => $daysToRestore,
            'previous_used_days' => $currentUsedDays,
            'new_used_days' => $newUsedDays,
            'previous_available_days' => $currentAvailableDays,
            'new_available_days' => $newAvailableDays,
        ]);
    }

    /**
     * Record a manual leave balance correction. Positive $days debits the balance
     * (e.g. leave already taken outside the system); negative $days credits it back.
     */
    public function adjustBalance(
        Employee $employee,
        LeaveType $leaveType,
        float $days,
        string $reason,
        User $actor,
        int $year
    ): LeaveBalanceAdjustment {
        return LeaveBalanceAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $year,
            'days' => $days,
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }

    public function getBalanceAdjustments(Employee $employee, ?int $leaveTypeId = null, ?int $year = null): Collection
    {
        return LeaveBalanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->when($leaveTypeId, fn (Builder $query) => $query->where('leave_type_id', $leaveTypeId))
            ->when($year, fn (Builder $query) => $query->where('year', $year))
            ->with(['leaveType:id,name,code,color', 'creator:id,name'])
            ->latest()
            ->get();
    }

    /**
     * Get leave balance for a specific employee and leave type
     */
    public function getLeaveBalance(int $employeeId, int $leaveTypeId, int $year): array
    {
        $employee = Employee::findOrFail($employeeId);
        $leaveType = LeaveType::findOrFail($leaveTypeId);

        $summary = $this->getLeaveSummaryForEmployee($employee, $year);
        $balance = $summary->firstWhere('leave_type_id', $leaveTypeId);

        return $balance ?: [
            'leave_type_id' => $leaveTypeId,
            'used_days' => 0,
            'available_days' => 0,
            'pending_days' => 0,
        ];
    }
}
