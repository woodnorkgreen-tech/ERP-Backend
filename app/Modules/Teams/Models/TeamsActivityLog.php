<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class TeamsActivityLog extends Model
{
    protected $fillable = [
        'teams_task_id',
        'teams_member_id',
        'action',
        'old_values',
        'new_values',
        'performed_by',
        'ip_address',
        'user_agent',
        'metadata'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'performed_by' => 'integer'
    ];

    // Relationships
    public function teamsTask(): BelongsTo
    {
        return $this->belongsTo(TeamsTask::class);
    }

    public function teamsMember(): BelongsTo
    {
        return $this->belongsTo(TeamsMember::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // Scopes
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByTask($query, int $taskId)
    {
        return $query->where('teams_task_id', $taskId);
    }

    public function scopeByMember($query, int $memberId)
    {
        return $query->where('teams_member_id', $memberId);
    }
}