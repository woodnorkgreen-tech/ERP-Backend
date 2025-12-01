<?php

namespace App\Modules\setupTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetupTaskIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'setup_task_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'reported_by',
        'assigned_to',
        'resolved_at',
        'resolution',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // Relationships
    public function setupTask(): BelongsTo
    {
        return $this->belongsTo(SetupTask::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }
}
