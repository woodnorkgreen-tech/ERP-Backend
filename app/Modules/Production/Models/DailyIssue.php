<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyIssue extends Model
{
    use HasFactory;

    protected $table = 'daily_issues';

    protected $fillable = [
        'job_card_id',
        'description',
        'resolution',
        'resolved_at',
        'status',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the job card for this issue.
     */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Scope to get unresolved issues.
     */
    public function scopeUnresolved($query)
    {
        return $query->where('status', '!=', 'resolved');
    }

    /**
     * Scope to get resolved issues.
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}
