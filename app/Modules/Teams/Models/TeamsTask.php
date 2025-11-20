<?php

namespace App\Modules\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\Project;
use App\Models\User;

class TeamsTask extends Model
{
    protected $fillable = [
        'task_id',
        'project_id',
        'category_id',
        'team_type_id',
        'status',
        'required_members',
        'assigned_members_count',
        'max_members',
        'start_date',
        'end_date',
        'estimated_hours',
        'actual_hours',
        'notes',
        'special_requirements',
        'priority',
        'created_by',
        'updated_by',
        'completed_at'
    ];

    protected $casts = [
        'required_members' => 'integer',
        'assigned_members_count' => 'integer',
        'max_members' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'completed_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer'
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TeamCategory::class);
    }

    public function teamType(): BelongsTo
    {
        return $this->belongsTo(TeamType::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamsMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(TeamsMember::class)->where('is_active', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Accessors
    public function getCompletionPercentageAttribute(): float
    {
        if ($this->required_members === 0) {
            return 100.0;
        }
        return round(($this->assigned_members_count / $this->required_members) * 100, 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!$this->end_date) {
            return false;
        }
        return $this->end_date->isPast() && $this->status !== 'completed';
    }

    public function getDaysUntilDeadlineAttribute(): ?int
    {
        if (!$this->end_date) {
            return null;
        }
        return $this->end_date->diffInDays(Carbon::now(), false);
    }

    // Scopes
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('end_date', '<', Carbon::now())
                    ->where('status', '!=', 'completed');
    }
}