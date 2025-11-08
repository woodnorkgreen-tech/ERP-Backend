<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplateTask extends Model
{
    use HasFactory;

    protected $table = 'workflow_template_tasks';

    protected $fillable = [
        'workflow_template_id',
        'name',
        'description',
        'sequence',
        'department_id',
        'assigned_role',
        'estimated_duration_days',
        'is_required',
        'dependencies',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'dependencies' => 'array',
    ];

    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Department::class);
    }

    public function workflowTasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class);
    }
}
