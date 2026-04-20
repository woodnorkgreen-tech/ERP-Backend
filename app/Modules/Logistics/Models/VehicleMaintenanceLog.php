<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class VehicleMaintenanceLog extends Model
{
    use SoftDeletes;

    protected $table = 'vehicle_maintenance_logs';

    protected $fillable = [
        'log_code', 'vehicle_id', 'driver_on_duty_id', 'logged_by_id',
        'activity_type', 'maintenance_type', 'odometer_reading',
        'description', 'cause_of_failure',
        'service_provider', 'cost_breakdown', 'total_cost',
        'service_date', 'downtime_days', 'next_service_due', 'next_service_notes',
        'status',
        'approved_by_id', 'approved_at', 'rejection_reason',
        'confirmed_by_id', 'confirmed_at',
        'before_photos', 'after_photos',
    ];

    protected $casts = [
        'service_date'     => 'date',
        'next_service_due' => 'date',
        'approved_at'      => 'datetime',
        'confirmed_at'     => 'datetime',
        'odometer_reading' => 'decimal:2',
        'total_cost'       => 'decimal:2',
        'cost_breakdown'   => 'array',
        'before_photos'    => 'array',
        'after_photos'     => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driverOnDuty(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_on_duty_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'logged_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by_id');
    }

    protected static function booted(): void
    {
        static::creating(function (VehicleMaintenanceLog $log) {
            if (empty($log->log_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $log->log_code = 'MNT-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });

        // When a log is created, set vehicle to maintenance
        static::created(function (VehicleMaintenanceLog $log) {
            Vehicle::where('id', $log->vehicle_id)->update(['status' => 'maintenance']);
        });

        // When confirmed (completed), free the vehicle back to active
        static::updated(function (VehicleMaintenanceLog $log) {
            if ($log->isDirty('status') && $log->status === 'completed') {
                Vehicle::where('id', $log->vehicle_id)->update(['status' => 'active']);
            }
        });
    }
}
