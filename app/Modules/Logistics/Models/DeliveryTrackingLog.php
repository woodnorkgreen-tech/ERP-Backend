<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryTrackingLog extends Model
{
    use HasFactory;

    protected $table = 'logistics_delivery_tracking_logs';

    protected $fillable = [
        'delivery_id',
        'status',
        'latitude',
        'longitude',
        'notes',
        'timestamp'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
