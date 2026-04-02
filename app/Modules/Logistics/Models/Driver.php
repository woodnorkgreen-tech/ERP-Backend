<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    // In App\Modules\Logistics\Models\Driver
public function user(): HasOne
{
    // If your drivers table has a user_id column (for login)
    return $this->hasOne(\App\Models\User::class, 'employee_id', 'employee_id');
}
}
