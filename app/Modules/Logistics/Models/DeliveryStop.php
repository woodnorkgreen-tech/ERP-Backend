<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryStop extends Model
{
    protected $fillable = [
        'delivery_id', 'trip_request_id', 'stop_order',
        'location', 'lat', 'lng',
        'receiver_name', 'receiver_phone',
        'status', 'arrived_at', 'delivered_at', 'delivery_note',
    ];

    protected $casts = [
        'arrived_at'   => 'datetime',
        'delivered_at' => 'datetime',
        'lat'          => 'decimal:7',
        'lng'          => 'decimal:7',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function tripRequest(): BelongsTo
    {
        return $this->belongsTo(TripRequest::class);
    }
}
