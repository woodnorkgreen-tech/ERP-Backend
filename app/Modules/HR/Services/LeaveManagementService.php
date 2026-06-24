<?php

namespace App\Modules\HR\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
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
                'days_per_year' => 21,
                'monthly_accrual_rate' => 1.75,
                'allow_advance' => true,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Kenya statutory minimum annual leave: 21 working days, earned at 1.75 days per completed month.',
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
            if (!$cursor->isSunday()) {
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
        } while ($resumptionDate->isSunday());

        return $resumptionDate;
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
                $pendingDays = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_PENDING]);
                $metrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $year);
                $carryForwardDays = $this->calculateCarryForwardDays($employee, $leaveType, $year);
                
                $totalAvailable = $metrics['earned_days'] + $carryForwardDays;
                $accruedRemaining = max($totalAvailable - ($usedDays + $pendingDays), 0);
                $requestableDays = $leaveType->allow_advance
                    ? max($metrics['year_entitlement_days'] + $carryForwardDays - ($usedDays + $pendingDays), 0)
                    : $accruedRemaining;
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
                    'available_days' => $accruedRemaining,
                    'requestable_days' => $requestableDays,
                    'advance_available_days' => $advanceAvailableDays,
                    'allow_advance' => (bool) $leaveType->allow_advance,
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
        $pending = $this->sumRequestedDays($employee->id, $leaveType->id, $year, [LeaveRequest::STATUS_PENDING], $ignoreRequestId);

        if ($leaveType->code === 'UNPAID') {
            return;
        }

        $metrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $year, $asOfDate);
        $carryForwardDays = $this->calculateCarryForwardDays($employee, $leaveType, $year);
        $remaining = $leaveType->allow_advance
            ? ($metrics['year_entitlement_days'] + $carryForwardDays) - ($approved + $pending)
            : ($metrics['earned_days'] + $carryForwardDays) - ($approved + $pending);

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

    public function calculateCarryForwardDays(Employee $employee, LeaveType $leaveType, int $year): float
    {
        // Only calculate carry-forward for leave types with monthly accrual
        if (!$leaveType->monthly_accrual_rate) {
            return 0;
        }

        $previousYear = $year - 1;
        
        // Get metrics for the previous year
        $previousYearMetrics = $this->getLeaveEntitlementMetrics($employee, $leaveType, $previousYear);
        $previousYearUsed = $this->sumRequestedDays($employee->id, $leaveType->id, $previousYear, [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_RECALLED]);
        
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
