<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScheduleAssignment extends Model
{
    protected $fillable = [
        'work_schedule_id', 'employee_id', 'department_id', 'effective_from', 'effective_to',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkSchedule::class, 'work_schedule_id');
    }
}
