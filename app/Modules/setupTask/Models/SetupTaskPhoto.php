<?php

namespace App\Modules\setupTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetupTaskPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'setup_task_id',
        'filename',
        'original_filename',
        'path',
        'description',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    // Relationships
    public function setupTask(): BelongsTo
    {
        return $this->belongsTo(SetupTask::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    // Accessor for photo URL
    public function getUrlAttribute(): string
    {
        return '/system/storage/' . $this->path;
    }
}
