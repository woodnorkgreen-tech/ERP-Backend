<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceHoliday extends Model
{
    protected $fillable = [
        'date', 'name', 'source', 'source_reference', 'imported_at', 'is_active',
    ];

    protected $casts = [
        'date' => 'date',
        'imported_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
