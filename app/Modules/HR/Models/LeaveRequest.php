<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
<<<<<<< HEAD
=======
    public const STATUS_RECALLED = 'recalled';
>>>>>>> hr

    protected $fillable = [
        'employee_id',
        'contact_employee_id',
        'leave_type_id',
        'created_by',
        'approved_by',
        'start_date',
        'end_date',
        'days_requested',
<<<<<<< HEAD
=======
        'carry_forward_days',
>>>>>>> hr
        'session',
        'status',
        'reason',
        'explanation',
        'handover_notes',
        'attachment_path',
        'review_notes',
        'approved_at',
        'cancelled_at',
<<<<<<< HEAD
=======
        'recalled_at',
        'recalled_by',
        'recall_reason',
>>>>>>> hr
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'days_requested' => 'decimal:1',
<<<<<<< HEAD
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
=======
        'carry_forward_days' => 'decimal:1',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'recalled_at' => 'datetime',
>>>>>>> hr
    ];

    protected $appends = [
        'date_range_label',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function contactEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'contact_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

<<<<<<< HEAD
=======
    public function recalledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalled_by');
    }

>>>>>>> hr
    public function getDateRangeLabelAttribute(): string
    {
        if (!$this->start_date || !$this->end_date) {
            return '';
        }

        return sprintf(
            '%s - %s',
            $this->start_date->format('M j'),
            $this->end_date->format('M j')
        );
    }
}
