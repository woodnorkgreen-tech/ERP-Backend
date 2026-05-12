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
}