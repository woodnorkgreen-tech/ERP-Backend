<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ProjectEnquiry;

class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'logistics_deliveries';

    protected $fillable = [
        'enquiry_id',
        'driver_id',
        'vehicle_id',
        'route_id',
        'status',
        'scheduled_at',
        'delivered_at'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function trackingLogs(): HasMany
    {
        return $this->hasMany(DeliveryTrackingLog::class);
    }
}
