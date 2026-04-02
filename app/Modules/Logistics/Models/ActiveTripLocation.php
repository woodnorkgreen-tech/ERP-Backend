<?php

namespace App\Modules\Logistics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveTripLocation extends Model
{
    protected $fillable = [
        'delivery_id',
        'user_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'vehicle_status',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'speed_kmh'   => 'decimal:2',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
