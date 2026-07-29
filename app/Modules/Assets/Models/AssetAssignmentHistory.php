<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignmentHistory extends Model
{
    protected $table = 'asset_assignment_history';

    protected $fillable = [
        'asset_id',
        'hire_request_id',
        'held_by',
        'assigned_by',
        'started_at',
        'ended_at',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function hireRequest(): BelongsTo
    {
        return $this->belongsTo(AssetHireRequest::class);
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }
}
