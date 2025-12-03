<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TaskTimeEntry extends Model
{
    use HasFactory;

    protected $table = 'task_time_entries';

    protected $fillable = [
        'task_id',
        'user_id',
        'hours',
        'date_worked',
        'description',
        'started_at',
        'ended_at',
        'is_billable',
        'metadata',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'date_worked' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_billable' => 'boolean',
        'metadata' => 'array',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that owns the time entry.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who logged the time entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== Scopes ====================

    /**
     * Scope a query to only include billable time entries.
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_billable', true);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('date_worked', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by task.
     */
    public function scopeForTask(Builder $query, int $taskId): Builder
    {
        return $query->where('task_id', $taskId);
    }

    // ==================== Methods ====================

    /**
     * Calculate the duration between started_at and ended_at if both are set.
     *
     * @return float|null Hours calculated from timestamps
     */
    public function calculateDurationFromTimestamps(): ?float
    {
        if ($this->started_at && $this->ended_at) {
            $seconds = $this->started_at->diffInSeconds($this->ended_at);
            return round($seconds / 3600, 2); // Convert to hours
        }

        return null;
    }

    /**
     * Validate that the time entry data is consistent.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        // Hours must be positive
        if ($this->hours <= 0) {
            return false;
        }

        // If both timestamps are provided, ended_at should be after started_at
        if ($this->started_at && $this->ended_at && $this->ended_at->lte($this->started_at)) {
            return false;
        }

        // If timestamps are provided, date_worked should match started_at date
        if ($this->started_at && $this->date_worked->format('Y-m-d') !== $this->started_at->format('Y-m-d')) {
            return false;
        }

        return true;
    }

    /**
     * Get formatted duration string.
     *
     * @return string
     */
    public function getFormattedDuration(): string
    {
        $hours = floor($this->hours);
        $minutes = ($this->hours - $hours) * 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}m";
        }
    }

    // ==================== Boot Method ====================

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate hours from timestamps if not provided
        static::saving(function ($timeEntry) {
            if (!$timeEntry->hours && $timeEntry->started_at && $timeEntry->ended_at) {
                $timeEntry->hours = $timeEntry->calculateDurationFromTimestamps();
            }

            // Set date_worked from started_at if not provided
            if (!$timeEntry->date_worked && $timeEntry->started_at) {
                $timeEntry->date_worked = $timeEntry->started_at->toDateString();
            }
        });
    }
}