<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Statutory deduction codes an employee can be exempted from during payroll.
     * Stored as an array in the `statutory_exemptions` column.
     */
    public const STATUTORY_EXEMPTIONS = ['paye', 'nssf', 'shif', 'housing_levy'];

    /**
     * Valid termination_type values — shared by EmployeeController::destroy() and
     * the Offboarding module so both validate against the same list.
     */
    public const TERMINATION_TYPES = [
        'resignation', 'dismissal', 'redundancy', 'contract_expiry',
        'retirement', 'mutual_agreement', 'other',
    ];

    protected $fillable = [
        'employee_id',
        'hikvision_id',
        'first_name',
        'last_name',
        'id_number',
        'kra_pin',
        'nssf_id',
        'nhif_id',
        'email',
        'phone',
        'department_id',
        'position',
        'hire_date',
        'probation_end_date',
        'is_on_probation',
        'contract_end_date',

        'status',
        'salary',
        'bank_name',
        'bank_branch',
        'bank_code',
        'account_number',
        'payment_method',
        'statutory_exemptions',
        'employment_type',
        'manager_id',
        'address',
        'date_of_birth',

        'emergency_contact',
        'performance_rating',
        'last_review_date',
        'profile_photo_path',

        // Termination metadata (set on destroy; cleared on restore)
        'termination_reason',
        'termination_type',
        'termination_date',
    ];

    protected $casts = [
        'hire_date'          => 'date',
        'probation_end_date' => 'date',
        'date_of_birth'      => 'date',
        'is_on_probation'    => 'boolean',
        'contract_end_date'  => 'date',
        'emergency_contact'  => 'array',
        'performance_rating' => 'decimal:1',
        'last_review_date'   => 'date',
        'salary'             => 'float',
        'statutory_exemptions' => 'array',
        'termination_date'   => 'date',
    ];

    protected $appends = [
        'name',
        'is_active',
        'profile_photo_url',
        // 'ot_balance' is intentionally NOT auto-appended: the accessor runs a
        // ledger query per model, causing an N+1 on every employee list/dashboard.
        // Endpoints that need it (e.g. compact() for the compensation dropdown)
        // append it explicitly, and direct ->ot_balance reads still work on demand.
    ];

    /**
     * Get the department that the employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the user account associated with this employee.
     */
    public function user(): HasOne
    {
        return $this->hasOne(\App\Models\User::class, 'employee_id');
    }

    /**
     * Get the manager of this employee.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Get the subordinates of this employee.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /**
     * Get leave requests recorded for this employee.
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Get manual leave balance adjustments recorded for this employee.
     */
    public function leaveBalanceAdjustments(): HasMany
    {
        return $this->hasMany(LeaveBalanceAdjustment::class);
    }

    /**
     * Get the full name of the employee.
     */
    public function getNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    /**
     * Get the active status as boolean.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the full public URL for the profile photo.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (!$this->profile_photo_path) {
            return null;
        }
        $timestamp = $this->updated_at ? $this->updated_at->timestamp : time();
        // Return an ABSOLUTE url (resolved against the request/APP_URL host) rather than a
        // bare relative path. In dev the SPA proxies /api so a relative path works, but in
        // production the SPA is a separate origin and a relative <img src="/api/..."> resolves
        // against the frontend host -> broken image. Matches the project's url('api/...') convention.
        return url("/api/hr/employees/{$this->id}/photo") . "?t={$timestamp}";
    }

    /**
     * Scope to filter active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter employees by department.
     */
    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope to filter employees accessible by the current user.
     */
    public function scopeAccessibleByUser($query, $user = null)
    {
        $user = $user ?? auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0'); // No access if no user
        }

        // Super Admin can see all employees
        if ($user->hasRole('Super Admin')) {
            return $query;
        }

        // Admin / HR / Project Officers can see all employees
        if ($user->hasRole(['Admin', 'HR', 'Project Officer', 'Project Manager'])) {
            return $query;
        }

        // Department managers: departments where this employee is listed as manager
        $managedDepartmentIds = \App\Modules\HR\Models\Department::where('manager_id', $user->employee_id)->pluck('id')->toArray();

        return $query->where(function($q) use ($managedDepartmentIds, $user) {
            if (!empty($managedDepartmentIds)) {
                $q->whereIn('department_id', array_unique($managedDepartmentIds));
            }
            // Direct reports regardless of department
            if ($user->employee_id) {
                $q->orWhere('manager_id', $user->employee_id);
            }
            // Own record only — not the whole department
            if ($user->employee_id) {
                $q->orWhere('id', $user->employee_id);
            }
        });
    }

    /**
     * Check if employee is accessible by the given user.
     */
    public function isAccessibleBy($user = null): bool
    {
        $user = $user ?? auth()->user();

        if (!$user) {
            return false;
        }

        // Super Admin has access to all
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        // Admin / HR / Project Officers can access all employees.
        // Kept in sync with the scopeAccessibleByUser role list so that a record
        // visible in the roster (index) is also reachable via show/update/destroy.
        if ($user->hasRole(['Admin', 'HR', 'Project Officer', 'Project Manager'])) {
            return true;
        }

        // Department managers can see everyone in their departments
        $managedDepartmentIds = \App\Modules\HR\Models\Department::where('manager_id', $user->employee_id)->pluck('id')->toArray();
        if (in_array($this->department_id, $managedDepartmentIds)) {
            return true;
        }

        // Direct reports
        if ($user->employee_id && $this->manager_id === $user->employee_id) {
            return true;
        }

        // Own record
        if ($user->employee_id && $this->id === $user->employee_id) {
            return true;
        }

        return false;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class)->orderBy('expiry_date');
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class)->orderBy('review_date', 'desc');
    }


    /**
     * Get the payslips for the employee.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Get the payroll ledgers for the employee.
     */
    public function payrollLedgers(): HasMany
    {
        return $this->hasMany(PayrollLedger::class);
    }

    /**
     * Get the salary history for the employee.
     */
    public function salaryHistory(): HasMany
    {
        return $this->hasMany(EmployeeSalaryHistory::class)->orderBy('valid_from', 'desc');
    }

    /**
     * Get the HR actions performed for this employee.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(HRAction::class);
    }

    /**
     * Get overtime entries for the employee.
     */
    public function otEntries(): HasMany
    {
        return $this->hasMany(OTEntry::class);
    }

    /**
     * Get ledger entries for the employee.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Get compensations for the employee.
     */
    public function compensations(): HasMany
    {
        return $this->hasMany(Compensation::class);
    }

    /**
     * Get the current OT balance from the latest ledger entry.
     */
    public function getOtBalanceAttribute(): float
    {
        try {
            $latest = $this->ledgerEntries()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();
            return $latest ? (float) $latest->balance_after : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }
}
