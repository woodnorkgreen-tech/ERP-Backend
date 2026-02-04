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
        'clock_in_time' => 'string',
        'clock_out_time' => 'string',
        'total_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the worker/technician for this job card.
     * Handles transition from employees to technical labour.
     */
    public function worker()
    {
        return $this->belongsTo(\App\Modules\HR\Models\TechnicalLabour::class, 'worker_id')
            ->withDefault(function ($worker, $jobCard) {
                // If no technical labour found, try to find employee and create dummy data
                if ($jobCard->worker_id && $jobCard->worker_id > 0) {
                    $employee = \App\Modules\HR\Models\Employee::find($jobCard->worker_id);
                    if ($employee) {
                        $worker->id = $employee->id;
                        $worker->full_name = $employee->first_name . ' ' . $employee->last_name;
                        $worker->phone = $employee->phone ?? 'EMP-' . $employee->id;
                        $worker->email = $employee->email ?? '';
                        $worker->specialization = $employee->department->name ?? 'General';
                        $worker->day_rate = 150.00;
                        $worker->status = 'active';
                    }
                }
            });
    }

    /**
     * Get the worker data with proper handling for technical labour and legacy employees.
     */
    public function getWorkerDataAttribute()
    {
        // Try to find technical labour first
        $techLabour = \App\Modules\HR\Models\TechnicalLabour::find($this->worker_id);
        if ($techLabour) {
            $nameParts = explode(' ', $techLabour->full_name);
            return [
                'id' => $techLabour->id,
                'first_name' => $nameParts[0] ?? '',
                'last_name' => implode(' ', array_slice($nameParts, 1)),
                'employee_number' => $techLabour->phone ?? 'TECH-' . $techLabour->id,
                'department' => 'Technical Resource Pool',
                'source' => 'technical_labour',
                'specialization' => $techLabour->specialization,
                'day_rate' => $techLabour->day_rate
            ];
        }
        
        // Fallback to employee for legacy data
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
