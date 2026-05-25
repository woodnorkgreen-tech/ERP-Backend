<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class Grievance extends Model
{
    protected $table = 'grievances';

    protected $fillable = [
        'complainant_id',
        'against_id',
        'description',
        'category',
        'date_reported',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
        'escalated_to',
        'investigation_notes',
        'witnesses',
        'attachments',
    ];

    protected $casts = [
        'date_reported' => 'datetime',
        'resolved_at' => 'datetime',
        'attachments' => 'array',
    ];

    const STATUS_REPORTED = 'Reported';
    const STATUS_INVESTIGATING = 'Investigating';
    const STATUS_RESOLVED = 'Resolved';
    const STATUS_ESCALATED = 'Escalated';
    const STATUS_CLOSED = 'Closed';

    public function complainant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complainant_id');
    }

    public function against(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(GrievanceComment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(GrievanceActivityLog::class);
    }

    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            self::STATUS_REPORTED => 'primary',
            self::STATUS_INVESTIGATING => 'warning',
            self::STATUS_RESOLVED => 'success',
            self::STATUS_ESCALATED => 'danger',
            self::STATUS_CLOSED => 'secondary',
            default => 'secondary',
        };
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
