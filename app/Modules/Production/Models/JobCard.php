<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Str;

class JobCard extends Model
{
    use HasFactory;

    protected $table = 'job_cards';

    protected $fillable = [
        'public_token',
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
        'clock_in_time' => 'string',
        'clock_out_time' => 'string',
        'total_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::saving(function (JobCard $jobCard) {
            if (empty($jobCard->public_token)) {
                $jobCard->public_token = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the worker/technician for this job card.
     * W7-10: Migrated to Employee Records only. Historical TechnicalLabour references
     * are preserved in the database but new operations use Employee Records.
     */
    public function worker()
    {
        return $this->belongsTo(\App\Modules\HR\Models\Employee::class, 'worker_id');
    }

    /**
     * Get the worker data from Employee Records.
     * W7-10: Consolidated on Employee Records per WNG decision.
     */
    public function getWorkerDataAttribute()
    {
        if ($this->worker_id && $this->worker_id > 0) {
            $employee = \App\Modules\HR\Models\Employee::find($this->worker_id);
            if ($employee) {
                return [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'employee_number' => $employee->employee_id ?? 'EMP-' . $employee->id,
                    'department' => $employee->department->name ?? 'Unknown',
                    'source' => 'employee',
                    'specialization' => $employee->department->name ?? 'General',
                    'day_rate' => 150.00
                ];
            }
        }
        
        return null;
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
