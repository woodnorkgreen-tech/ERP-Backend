<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManifestSubmissionLink extends Model
{
    protected $table = 'logistics_manifest_links';

    protected $fillable = ['logistics_task_id', 'token', 'categories', 'expires_at', 'revoked_at', 'created_by'];

    protected $casts = [
        'categories' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function logisticsTask(): BelongsTo
    {
        return $this->belongsTo(LogisticsTask::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ManifestSubmission::class, 'manifest_link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function isAvailable(): bool
    {
        return !$this->revoked_at && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
