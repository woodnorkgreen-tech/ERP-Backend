<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Modules\HR\Models\Department;

class DisciplinaryCase extends Model
{
    protected $table = 'disciplinary_cases';

    protected $fillable = [
        'employee_id',
        'reported_by',
        'allegations',
        'offense_category',
        'date_reported',
        'status',
        'show_cause_issued',
        'show_cause_letter',
        'show_cause_response',
        'show_cause_response_date',
        'investigation_notes',
        'witnesses',
        'attachments',
        'hearing_scheduled',
        'hearing_date',
        'hearing_panel',
        'hearing_minutes',
        'hearing_decision',
        'warning_issued',
        'warning_letter',
        'appeal_submitted',
        'appeal_details',
        'appeal_response',
        'final_decision',
        'suspension_details',
    ];

    protected $casts = [
        'date_reported' => 'datetime',
        'show_cause_response_date' => 'datetime',
        'hearing_date' => 'datetime',
        'suspension_details' => 'array',
        'hearing_panel' => 'array',
        'attachments' => 'array',
        'show_cause_issued' => 'boolean',
        'hearing_scheduled' => 'boolean',
        'appeal_submitted' => 'boolean',
    ];

    const STATUS_REPORTED = 'Reported';
    const STATUS_SHOW_CAUSE_ISSUED = 'Show Cause Issued';
    const STATUS_INVESTIGATING = 'Investigating';
    const STATUS_HEARING_SCHEDULED = 'Hearing Scheduled';
    const STATUS_HEARING_HELD = 'Hearing Held';
    const STATUS_DECISION_MADE = 'Decision Made';
    const STATUS_APPEALED = 'Appealed';
    const STATUS_FINAL = 'Final';

    // The subject of the case is an Employee (employee_id -> employees.id).
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // The reporter is a system user (who logged the case).
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DisciplinaryComment::class, 'case_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(DisciplinaryActivityLog::class, 'case_id');
    }

    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            self::STATUS_REPORTED => 'primary',
            self::STATUS_SHOW_CAUSE_ISSUED => 'warning',
            self::STATUS_INVESTIGATING => 'info',
            self::STATUS_HEARING_SCHEDULED => 'warning',
            self::STATUS_HEARING_HELD => 'info',
            self::STATUS_DECISION_MADE => 'success',
            self::STATUS_APPEALED => 'danger',
            self::STATUS_FINAL => 'secondary',
            default => 'secondary',
        };
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}