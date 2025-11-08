<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTask extends Model
{
    use HasFactory;

    protected $table = 'workflow_tasks';

    protected $fillable = [
        'workflow_instance_id',
        'workflow_template_task_id',
        'assigned_user_id',
        'status',
        'started_at',
        'completed_at',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'due_date' => 'date',
    ];

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function workflowTemplateTask(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateTask::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
