<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardWorkflowTask extends Model
{
    protected $table = 'board_workflow_tasks';

    protected $fillable = [
        'job_ref',
        'board_request_id',
        'task_type',
        'assigned_role',
        'assigned_user_id',
        'status',
        'triggered_by_task_id',
        'payload',
        'due_at',
        'claimed_at',
        'claimed_by',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'payload'      => 'array',
        'due_at'       => 'datetime',
        'claimed_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Task type constants ──────────────────────────────────────────────────
    const TYPE_REQUEST_RAISED     = 'request_raised';     // Stores must fulfill
    const TYPE_BOARDS_TO_DISPATCH = 'boards_to_dispatch'; // Logistics must deliver
    const TYPE_BOARDS_AT_STATION  = 'boards_at_station';  // Production must start WIP
    const TYPE_OFFCUT_TO_RETURN   = 'offcut_to_return';   // Stores must rack offcut
    const TYPE_BOARD_RETURNED     = 'board_returned';     // Stores must rack intact returned board

    // ─── Relationships ────────────────────────────────────────────────────────
    public function boardRequest(): BelongsTo
    {
        return $this->belongsTo(BoardRequest::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'triggered_by_task_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_user_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'claimed_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────
    public function scopePendingForRole($query, string $role)
    {
        return $query->where('assigned_role', $role)->where('status', 'pending');
    }

    // ─── State helpers ────────────────────────────────────────────────────────
    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isDone(): bool      { return $this->status === 'done'; }
    public function isOverdue(): bool   { return $this->due_at && $this->due_at->isPast() && !$this->isDone(); }
}
