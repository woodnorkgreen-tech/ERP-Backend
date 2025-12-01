<?php

namespace App\Modules\setupTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SetupTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'project_id',
        'setup_notes',
        'completion_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'setup_notes' => 'string',
        'completion_notes' => 'string',
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
        return $this->hasMany(SetupTaskPhoto::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SetupTaskIssue::class);
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
