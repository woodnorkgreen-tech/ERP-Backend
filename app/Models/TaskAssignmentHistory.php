<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'task_assignment_history';

    protected $fillable = [
        'enquiry_task_id',
        'assigned_to',
        'assigned_by',
        'assigned_at',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
