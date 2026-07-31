<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'restricted_gender',
        'days_per_year',
        'monthly_accrual_rate',
        'allow_advance',
        'color',
        'icon',
        'description',
        'is_active',
        'requires_attachment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
        'monthly_accrual_rate' => 'decimal:2',
        'allow_advance' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(LeaveBalanceAdjustment::class);
    }

    /**
     * Whether the given employee may request this leave type. Unrestricted
     * types (restricted_gender = null) are open to everyone. A restricted
     * type with no gender recorded on the employee is treated as ineligible
     * — a missing gender must never silently grant a gender-specific benefit
     * (or silently deny it to the person it's actually for); the profile
     * needs completing first.
     */
    public function isEligibleForEmployee(Employee $employee): bool
    {
        if (!$this->restricted_gender) {
            return true;
        }

        if (!$employee->gender) {
            return false;
        }

        return strtolower($employee->gender) === strtolower($this->restricted_gender);
    }
}
