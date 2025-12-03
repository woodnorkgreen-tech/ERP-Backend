<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSavedView extends Model
{
    use HasFactory;

    protected $table = 'task_saved_views';

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'filters',
        'sort_config',
        'per_page',
        'is_default',
        'is_shared',
    ];

    protected $casts = [
        'filters' => 'array',
        'sort_config' => 'array',
        'per_page' => 'integer',
        'is_default' => 'boolean',
        'is_shared' => 'boolean',
    ];

    /**
     * Get the user who owns this saved view.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get views for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get default views.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to get shared views.
     */
    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    /**
     * Get the filter configuration as an array.
     */
    public function getFilters(): array
    {
        return $this->filters ?? [];
    }

    /**
     * Get the sort configuration.
     */
    public function getSortConfig(): array
    {
        return $this->sort_config ?? ['sort_by' => 'created_at', 'sort_direction' => 'desc'];
    }

    /**
     * Apply this saved view to a query.
     */
    public function applyToQuery($query)
    {
        // This would be used to apply the saved view filters to a Task query
        // Implementation would depend on how the TaskRepository is used
        return $query;
    }

    /**
     * Create a new default view for a user.
     */
    public static function createDefaultForUser(int $userId, string $name, array $filters = []): self
    {
        // Remove existing default for this user
        static::forUser($userId)->default()->update(['is_default' => false]);

        return static::create([
            'name' => $name,
            'user_id' => $userId,
            'filters' => $filters,
            'is_default' => true,
        ]);
    }
}