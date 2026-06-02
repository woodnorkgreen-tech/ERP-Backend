<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'request_code',
        'context_type',
        'project_id',
        'delivery_type_label',
        'requested_by_id',
        'priority',
        'pickup_location',
        'pickup_lat',
        'pickup_lng',
        'destination',
        'destination_lat',
        'destination_lng',
        'required_date',
        'notes',
        'status',
        'approved_by_id',
        'approved_at',
        'rejection_reason',
        'assigned_driver_id',
        'assigned_vehicle_id',
        'assigned_by_id',
        'assigned_at',
        'assignment_notes',
        'started_at',
        'completed_at',
        'batch_id',
        'stop_order',
    ];

    protected $casts = [
        'required_date'  => 'date',
        'approved_at'    => 'datetime',
        'assigned_at'    => 'datetime',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'pickup_lat'     => 'decimal:7',
        'pickup_lng'     => 'decimal:7',
        'destination_lat'=> 'decimal:7',
        'destination_lng'=> 'decimal:7',
    ];

    // ─── Relationships ────────────────────────────────────────────────────

    public function project(): BelongsTo
{
    return $this->belongsTo(ProjectEnquiry::class, 'project_id');
}

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('requested_by_id', $employeeId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'requested');
    }

    // ─── Auto-generate request_code ───────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (TripRequest $trip) {
            if (empty($trip->request_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $trip->request_code = 'TREQ-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
