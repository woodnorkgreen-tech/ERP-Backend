<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TaskExperienceLog extends Model
{
    use HasFactory;

    protected $table = 'task_experience_logs';

    protected $fillable = [
        'task_id',
        'user_id',
        'title',
        'content',
        'log_type',
        'tags',
        'is_public',
        'logged_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_public' => 'boolean',
        'logged_at' => 'datetime',
    ];

    /**
     * Get the task that owns the experience log.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who created the experience log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to filter by log type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('log_type', $type);
    }

    /**
     * Scope a query to only include public logs.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope a query to filter by tag.
     */
    public function scopeByTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeByDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('logged_at', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by multiple criteria.
     * 
     * @param Builder $query
     * @param array $filters Array of filters: ['type' => 'observation', 'tags' => ['tag1'], 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']
     * @return Builder
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (isset($filters['type'])) {
            $query->byType($filters['type']);
        }

        if (isset($filters['tags'])) {
            foreach ((array) $filters['tags'] as $tag) {
                $query->byTag($tag);
            }
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
        }

        if (isset($filters['is_public'])) {
            $query->where('is_public', $filters['is_public']);
        }

        return $query;
    }
}
