<?php

namespace App\Modules\setdownTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetdownTaskPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'setdown_task_id',
        'filename',
        'original_filename',
        'path',
        'description',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    // Relationships
    public function setdownTask(): BelongsTo
    {
        return $this->belongsTo(SetdownTask::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    // Accessor for photo URL
    public function getUrlAttribute(): string
    {
        return storage_url($this->path);
    }
}
