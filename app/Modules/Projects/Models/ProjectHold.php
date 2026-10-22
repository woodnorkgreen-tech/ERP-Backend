<?php

namespace App\Modules\Projects\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report 78C — a job paused by management. Open while released_at is null.
 * Append-only: releasing stamps the row, it is never deleted.
 */
class ProjectHold extends Model
{
    protected $fillable = [
        'project_enquiry_id', 'reason', 'placed_by', 'placed_at',
        'released_by', 'released_at', 'release_reason',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by')->select('id', 'name')->without('roles');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by')->select('id', 'name')->without('roles');
    }
}
