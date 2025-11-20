<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'project_id',
        'team_id',
        'logistics_planning',
        'setup_teams_confirmed',
        'team_confirmation_notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'project_id' => null, // Allow null project_id
    ];

    protected $casts = [
        'logistics_planning' => 'array',
        'setup_teams_confirmed' => 'boolean',
        'status' => 'string',
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Teams\Models\TeamsTask::class, 'team_id');
    }

    public function transportItems(): HasMany
    {
        return $this->hasMany(TransportItem::class);
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(LogisticsChecklist::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

}
