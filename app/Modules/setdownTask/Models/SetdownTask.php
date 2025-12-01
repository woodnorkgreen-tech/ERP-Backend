<?php

namespace App\Modules\setdownTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class SetdownTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'project_id',
        'documentation',
        'issues',
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
}
