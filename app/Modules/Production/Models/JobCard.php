<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\HR\Models\Employee;

class JobCard extends Model
{
    use HasFactory;

    protected $table = 'job_cards';

    protected $fillable = [
        'worker_id',
        'date',
        'clock_in_time',
        'clock_out_time',
        'total_hours',
        'overtime_hours',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in_time' => 'datetime',
        'clock_out_time' => 'datetime',
        'total_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the worker/technician for this job card.
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'worker_id');
    }

    /**
     * Get the approver (production lead) for this job card.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    /**
     * Get the tasks for this job card.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(DailyTask::class);
    }

    /**
     * Get the issues for this job card.
     */
    public function issues(): HasMany
    {
        return $this->hasMany(DailyIssue::class);
    }
}
