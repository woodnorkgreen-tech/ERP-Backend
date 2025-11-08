<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends Model
{
    use HasFactory;

    protected $table = 'workflow_templates';

    protected $fillable = [
        'name',
        'description',
        'type',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function templateTasks(): HasMany
    {
        return $this->hasMany(WorkflowTemplateTask::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }
}
