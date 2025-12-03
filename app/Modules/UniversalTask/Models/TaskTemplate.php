<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class TaskTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'task_templates';

    protected $fillable = [
        'name',
        'description',
        'category',
        'version',
        'previous_version_id',
        'is_active',
        'template_data',
        'variables',
        'created_by',
        'updated_by',
        'tags',
    ];

    protected $casts = [
        'template_data' => 'array',
        'variables' => 'array',
        'tags' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    // ==================== Relationships ====================

    /**
     * Get the user who created the template.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the template.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the previous version of this template.
     */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'previous_version_id');
    }

    /**
     * Get all newer versions of this template.
     */
    public function newerVersions(): HasMany
    {
        return $this->hasMany(TaskTemplate::class, 'previous_version_id');
    }

    // ==================== Scopes ====================

    /**
     * Scope a query to only include active templates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to get the latest version of each template.
     */
    public function scopeLatestVersions(Builder $query): Builder
    {
        return $query->whereNotIn('id', function ($subQuery) {
            $subQuery->select('previous_version_id')
                ->from('task_templates')
                ->whereNotNull('previous_version_id');
        });
    }

    // ==================== Methods ====================

    /**
     * Create a new version of this template.
     * 
     * @param array $data The updated template data
     * @param int $userId The user creating the new version
     * @return TaskTemplate The new version
     */
    public function createNewVersion(array $data, int $userId): TaskTemplate
    {
        // Mark current version as inactive
        $this->is_active = false;
        $this->save();

        // Create new version
        $newVersion = static::create([
            'name' => $data['name'] ?? $this->name,
            'description' => $data['description'] ?? $this->description,
            'category' => $data['category'] ?? $this->category,
            'version' => $this->version + 1,
            'previous_version_id' => $this->id,
            'is_active' => true,
            'template_data' => $data['template_data'] ?? $this->template_data,
            'variables' => $data['variables'] ?? $this->variables,
            'created_by' => $userId,
            'updated_by' => $userId,
            'tags' => $data['tags'] ?? $this->tags,
        ]);

        return $newVersion;
    }

    /**
     * Get all versions of this template (including this one).
     * 
     * @return \Illuminate\Support\Collection
     */
    public function getAllVersions()
    {
        $versions = collect([$this]);

        // Get all previous versions
        $current = $this;
        while ($current->previous_version_id) {
            $current = $current->previousVersion;
            if ($current) {
                $versions->push($current);
            } else {
                break;
            }
        }

        // Get all newer versions
        $newerVersions = $this->newerVersions;
        while ($newerVersions->isNotEmpty()) {
            $versions = $versions->concat($newerVersions);
            $newerVersions = $newerVersions->flatMap(function ($version) {
                return $version->newerVersions;
            });
        }

        return $versions->sortBy('version');
    }

    /**
     * Get the latest version of this template.
     * 
     * @return TaskTemplate
     */
    public function getLatestVersion(): TaskTemplate
    {
        $current = $this;
        
        while ($current->newerVersions()->exists()) {
            $current = $current->newerVersions()->latest('version')->first();
        }

        return $current;
    }

    /**
     * Validate template data structure.
     * 
     * @return bool
     */
    public function validateTemplateData(): bool
    {
        if (!is_array($this->template_data)) {
            return false;
        }

        // Template data should have 'tasks' array
        if (!isset($this->template_data['tasks']) || !is_array($this->template_data['tasks'])) {
            return false;
        }

        // Each task should have required fields
        foreach ($this->template_data['tasks'] as $task) {
            if (!isset($task['title'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get variable names defined in this template.
     * 
     * @return array
     */
    public function getVariableNames(): array
    {
        if (!$this->variables || !is_array($this->variables)) {
            return [];
        }

        return array_keys($this->variables);
    }

    /**
     * Boot the model and register model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Validate template data before saving
        static::saving(function ($template) {
            if (!$template->validateTemplateData()) {
                throw new \InvalidArgumentException(
                    'Invalid template data structure: must contain tasks array with title for each task'
                );
            }
        });
    }
}
