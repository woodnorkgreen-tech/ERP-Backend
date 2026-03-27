<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveHandover extends Model
{
    protected $fillable = [
        'leave_request_id',
        'employee_id',
        'project_name',
        'task_description',
        'current_status',
        'pending_actions',
        'handed_over_to_employee_id',
        'department',
        'follow_up_deadline',
        'update_during_leave',
        'updated_by',
        'updated_at',
    ];

    protected $casts = [
        'follow_up_deadline' => 'date',
        'updated_at' => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function handedOverTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handed_over_to_employee_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
