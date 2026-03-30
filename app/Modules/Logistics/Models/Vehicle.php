<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'plate_number',
        'vehicle_type',
        'capacity_kg',
        'fuel_type',
        'insurance_expiry',
        'odometer_km',
        'gps_status',
        'gps_lat',
        'gps_lng',
        'gps_last_updated',
        'status',
        'assigned_driver_id',
    ];

    protected $casts = [
        'insurance_expiry'  => 'date',
        'gps_last_updated'  => 'datetime',
        'capacity_kg'       => 'decimal:2',
        'odometer_km'       => 'decimal:2',
        'gps_lat'           => 'decimal:7',
        'gps_lng'           => 'decimal:7',
    ];

    protected $appends = [
        'is_gps_active',
        'is_available',
        'insurance_is_expired',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }

    // ─── Accessors ────────────────────────────────────────────────

    public function getIsGpsActiveAttribute(): bool
    {
        return $this->gps_status === 'active';
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getInsuranceIsExpiredAttribute(): bool
    {
        return $this->insurance_expiry->isPast();
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        // Active and not currently booked
        return $query->where('status', 'active');
    }

    public function scopeGpsActive($query)
    {
        return $query->where('gps_status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('vehicle_type', $type);
    }

    // ─── Auto-generate vehicle_id ─────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle) {
            if (empty($vehicle->vehicle_id)) {
                $last = static::withTrashed()->latest('id')->first();
                $next = $last ? ($last->id + 1) : 1;
                $vehicle->vehicle_id = 'VEH-' . str_pad($next, 3, '0', STR_PAD_LEFT);
            }
        });
    }
}
