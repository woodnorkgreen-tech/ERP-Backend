<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceWorkSchedule extends Model
{
    protected $fillable = [
        'name', 'shift_start', 'shift_end', 'grace_minutes', 'break_minutes',
        'earliest_clock_in', 'latest_clock_out', 'half_day_minutes',
        'working_days', 'is_overnight', 'is_default', 'is_active',
    ];

    protected $casts = [
        'working_days' => 'array',
        'grace_minutes' => 'integer',
        'break_minutes' => 'integer',
        'half_day_minutes' => 'integer',
        'is_overnight' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
