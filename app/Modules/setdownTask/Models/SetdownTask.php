<?php

namespace App\Modules\setdownTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SetdownTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'project_id',
        'documentation',
        'issues',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'documentation' => 'array',
        'issues' => 'array',
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

    public function photos(): HasMany
    {
        return $this->hasMany(SetdownTaskPhoto::class);
    }

    public function checklist(): HasOne
    {
        return $this->hasOne(SetdownChecklist::class);
    }

    public function setdownIssues(): HasMany
    {
        return $this->hasMany(SetdownTaskIssue::class);
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
