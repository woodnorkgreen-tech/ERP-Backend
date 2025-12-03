<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TaskIssue extends Model
{
    use HasFactory;

    protected $table = 'task_issues';

    protected $fillable = [
        'task_id',
        'title',
        'description',
        'issue_type',
        'severity',
        'status',
        'reported_by',
        'assigned_to',
        'reported_at',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that owns the issue.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who reported the issue.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the user assigned to resolve the issue.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who resolved the issue.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ==================== Scopes ====================

    /**
     * Scope a query to only include open issues.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    /**
     * Scope a query to only include resolved issues.
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereIn('status', ['resolved', 'closed']);
    }

    /**
     * Scope a query to only include critical issues.
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope a query to filter by severity.
     */
    public function scopeBySeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope a query to filter by issue type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('issue_type', $type);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    // ==================== Methods ====================

    /**
     * Check if the issue is critical or high severity.
     */
    public function isCriticalOrHigh(): bool
    {
        return in_array($this->severity, ['critical', 'high']);
    }

    /**
     * Check if the issue is resolved.
     */
    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'closed']);
    }

    /**
     * Mark the issue as resolved.
     */
    public function markAsResolved(int $resolvedBy, ?string $resolutionNotes = null): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
            'resolution_notes' => $resolutionNotes,
        ]);
    }
}
