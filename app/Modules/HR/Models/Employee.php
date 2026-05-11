<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
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
        'employment_type',
        'manager_id',
        'address',

        'emergency_contact',
        'performance_rating',
        'last_review_date',
        'profile_photo_path',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'probation_end_date' => 'date',
        'is_on_probation' => 'boolean',
        'contract_end_date' => 'date',
        'emergency_contact' => 'array',
        'performance_rating' => 'decimal:1',
        'last_review_date' => 'date',
        'salary' => 'float'
    ];

    protected $appends = [
        'name',
        'is_active',
        'profile_photo_url',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
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
        return asset('storage/' . $this->profile_photo_path);
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

        // Admin can see all employees for admin purposes
        if ($user->hasRole('Admin')) {
            return $query;
        }

        // Managers and Employees can only see employees in their department
        return $query->where('department_id', $user->department_id);
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

        // Admin has access to all for admin purposes
        if ($user->hasRole('Admin')) {
            return true;
        }

        // Check if employee is in user's department
        return $this->department_id === $user->department_id;
    }

    /**
     * Get the documents for the employee.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
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
}
