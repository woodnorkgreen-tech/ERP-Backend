<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_code', 'batch_id', 'driver_id', 'vehicle_id',
        'total_stops', 'completed_stops', 'status',
        'delivery_date', 'departure_time', 'notes',
        'started_at', 'completed_at',
        'total_km', 'total_duration_minutes', 'avg_speed_kmh', 'on_time',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DispatchBatch::class, 'batch_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(DeliveryStop::class)->orderBy('stop_order');
    }

    // No return type hint — avoids TypeError with PHP version differences
    public function latestLocation()
    {
        return $this->hasOne(ActiveTripLocation::class)->latest('recorded_at');
    }

    protected static function booted(): void
    {
        static::creating(function (Delivery $delivery) {
            if (empty($delivery->delivery_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $delivery->delivery_code = 'DEL-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
