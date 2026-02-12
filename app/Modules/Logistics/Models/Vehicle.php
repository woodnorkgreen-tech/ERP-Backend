<?php

namespace App\Modules\Logistics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'logistics_vehicles';

    protected $fillable = [
        'plate_number',
        'make',
        'model',
        'type',
        'capacity',
        'fuel_type',
        'status',
        'last_service_date',
        'insurance_expiry'
    ];

    protected $casts = [
        'last_service_date' => 'date',
        'insurance_expiry' => 'date',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
