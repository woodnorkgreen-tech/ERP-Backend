<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class TeamsMember extends Model
{
    protected $fillable = [
        'teams_task_id',
        'member_name',
        'member_email',
        'member_phone',
        'member_role',
        'hourly_rate',
        'is_lead',
        'is_active',
        'assigned_at',
        'assigned_by',
        'unassigned_at',
        'unassigned_by',
        'efficiency_rating',
        'performance_notes'
    ];

    protected $casts = [
        'is_lead' => 'boolean',
        'is_active' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'efficiency_rating' => 'decimal:2',
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'assigned_by' => 'integer',
        'unassigned_by' => 'integer'
    ];

    // Relationships
    public function teamsTask(): BelongsTo
    {
        return $this->belongsTo(TeamsTask::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function unassigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unassigned_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLeads($query)
    {
        return $query->where('is_lead', true);
    }
}