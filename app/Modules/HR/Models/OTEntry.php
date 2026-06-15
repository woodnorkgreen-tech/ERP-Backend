<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OTEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ot_entries';

    protected $fillable = [
        'employee_id',
        'attendance_record_id',
        'source_type',
        'technical_labour_id',
        'project_id',
        'job_title',
        'location',
        'work_date',
        'start_time',
        'end_time',
        'hours',
        'notes',
        'status',
        'submitted_by',
        'supervisor_approved_by',
        'supervisor_approved_at',
        'hr_approved_by',
        'hr_approved_at',
        'rejected_reason',
        'supersedes_entry_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'supervisor_approved_at' => 'datetime',
        'hr_approved_at' => 'datetime',
        'hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function technicalLabour(): BelongsTo
    {
        return $this->belongsTo(TechnicalLabour::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function supervisorApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_approved_by');
    }

    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(OTEntry::class, 'supersedes_entry_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(OTFlag::class, 'ot_entry_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ot_entry_id');
    }
}
