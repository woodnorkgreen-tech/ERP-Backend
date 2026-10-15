<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Logistics\Models\Delivery;

class Driver extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'license_number',
        'license_expiry',
        'status',
    ];

    protected $casts = [
        'license_expiry' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
    
    public function user(): HasOne
    {
        return $this->hasOne(\App\Models\User::class, 'employee_id', 'employee_id');
    }

    // ✅ ADD THIS METHOD - Gets the driver's current active delivery
    public function activeDelivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'driver_id')
            ->whereIn('status', ['pending', 'in_transit'])
            ->latest();
    }

    /**
     * Pull the logistics team from the HR employee list.
     *
     * Everyone active in the Logistics department, plus anyone anywhere whose
     * position is a driver role, is registered as a driver. Employees that
     * were removed as drivers (soft-deleted) are left alone. Licence details
     * are filled in later by the Logistics Lead.
     *
     * Roles follow the HR position, and the two can overlap:
     *   - every one of them gets the "Driver" role
     *   - a lead (position contains "lead", or the department manager) also
     *     gets "Logistics Lead", so a lead who drives has both.
     *
     * Returns the number of drivers created.
     */
    public static function syncFromHr(): int
    {
        $removed = static::onlyTrashed()->pluck('employee_id');

        $employees = Employee::active()
            ->whereNotIn('id', $removed)
            ->where(function ($q) {
                $q->where('position', 'like', '%driver%')
                  ->orWhereHas('department', fn ($d) => $d->where('name', 'like', '%logistic%'));
            })
            ->with('department')
            ->get();

        $created = 0;

        foreach ($employees as $employee) {
            $driver = static::firstOrCreate(
                ['employee_id' => $employee->id],
                ['status' => 'active']
            );
            if ($driver->wasRecentlyCreated) {
                $created++;
            }

            $isLead = stripos((string) $employee->position, 'lead') !== false
                || ($employee->department && (int) $employee->department->manager_id === (int) $employee->id);

            static::syncRoles($employee, $isLead);
        }

        return $created;
    }

    /** Give the matching login the Driver role, and Logistics Lead for leads. */
    private static function syncRoles(Employee $employee, bool $isLead): void
    {
        try {
            $user = $employee->email
                ? \App\Models\User::where('email', $employee->email)->first()
                : null;
            if (!$user) {
                return;
            }

            $guard = config('auth.defaults.guard', 'web');
            $roles = $isLead ? ['Driver', 'Logistics Lead'] : ['Driver'];

            foreach ($roles as $name) {
                \Spatie\Permission\Models\Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
                if (!$user->hasRole($name)) {
                    $user->assignRole($name);
                }
            }
        } catch (\Throwable $e) {
            // Never block registration over a role problem.
        }
    }
}
