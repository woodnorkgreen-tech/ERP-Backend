<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use App\Modules\UniversalTask\Models\Contexts\LogisticsTaskContext;
use App\Modules\UniversalTask\Models\Contexts\DesignTaskContext;
use App\Modules\UniversalTask\Models\Contexts\FinanceTaskContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = [
        'title',
        'description',
        'task_type',
        'status',
        'priority',
        'parent_task_id',
        'taskable_type',
        'taskable_id',
        'department_id',
        'assigned_user_id',
        'created_by',
        'estimated_hours',
        'actual_hours',
        'due_date',
        'started_at',
        'completed_at',
        'blocked_reason',
        'tags',
        'metadata',
        'completion_percentage',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'completion_percentage' => 'decimal:2',
    ];

    /**
     * Default attribute values for the model.
     *
     * @var array
     */
    protected $attributes = [
        'status' => 'pending',
        'priority' => 'medium',
        'estimated_hours' => 0,
        'actual_hours' => 0,
        'completion_percentage' => 0,
        'tags' => '[]',
        'metadata' => '{}',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * @var array
     */
    protected $appends = ['subtasks_count'];

    // ==================== Relationships ====================

    /**
     * Get the department that owns the task.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo('App\Modules\HR\Models\Department');
    }

    /**
     * Get the user assigned to the task.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the user who created the task.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the parent task.
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /**
     * Get the subtasks of this task.
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * Get the task dependencies.
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /**
     * Get the task assignments.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    /**
     * Get the task issues.
     */
    public function issues(): HasMany
    {
        return $this->hasMany(TaskIssue::class, 'task_id');
    }

    /**
     * Get the task experience logs.
     */
    public function experienceLogs(): HasMany
    {
        return $this->hasMany(TaskExperienceLog::class, 'task_id');
    }

    /**
     * Get the task comments.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    /**
     * Get the task attachments.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'task_id');
    }

    /**
     * Get the task time entries.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class, 'task_id');
    }

    /**
     * Get the task history.
     */
    public function history(): HasMany
    {
        return $this->hasMany(TaskHistory::class, 'task_id');
    }

    /**
     * Get the owning taskable model (polymorphic).
     */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the logistics context for this task.
     */
    public function logisticsContext(): HasOne
    {
        return $this->hasOne(LogisticsTaskContext::class);
    }

    /**
     * Get the design context for this task.
     */
    public function designContext(): HasOne
    {
        return $this->hasOne(DesignTaskContext::class);
    }

    /**
     * Get the finance context for this task.
     */
    public function financeContext(): HasOne
    {
        return $this->hasOne(FinanceTaskContext::class);
    }

    // ==================== Scopes ====================

    /**
     * Scope a query to only include pending tasks.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include in-progress tasks.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope a query to only include completed tasks.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include overdue tasks.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'overdue')
            ->orWhere(function ($q) {
                $q->where('due_date', '<', now())
                  ->whereNotIn('status', ['completed', 'cancelled']);
            });
    }

    /**
     * Scope a query to only include blocked tasks.
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('status', 'blocked');
    }

    /**
     * Scope a query to filter by department.
     */
    public function scopeByDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope a query to filter by task type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('task_type', $type);
    }

    /**
     * Scope a query to filter by priority.
     */
    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to filter by assigned user.
     */
    public function scopeAssignedToUser(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    /**
     * Scope a query to filter by creator.
     */
    public function scopeCreatedBy(Builder $query, int $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope a query to filter tasks due between two dates.
     */
    public function scopeDueBetween(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('due_date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter tasks due today.
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->whereDate('due_date', today());
    }

    /**
     * Scope a query to filter tasks due this week.
     */
    public function scopeDueThisWeek(Builder $query): Builder
    {
        return $query->whereBetween('due_date', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    /**
     * Scope a query to only include root tasks (no parent).
     */
    public function scopeRootTasks(Builder $query): Builder
    {
        return $query->whereNull('parent_task_id');
    }

    /**
     * Scope a query to get subtasks of a specific task.
     */
    public function scopeSubtasksOf(Builder $query, int $parentTaskId): Builder
    {
        return $query->where('parent_task_id', $parentTaskId);
    }

    // ==================== Methods ====================

    /**
     * Get the subtasks count attribute.
     *
     * @return int
     */
    public function getSubtasksCountAttribute(): int
    {
        return $this->subtasks()->count();
    }

    /**
     * Calculate completion percentage based on subtasks.
     */
    public function calculateCompletionPercentage(): float
    {
        $subtasks = $this->subtasks;

        if ($subtasks->isEmpty()) {
            return $this->status === 'completed' ? 100.0 : 0.0;
        }

        $completedCount = $subtasks->where('status', 'completed')->count();
        $totalCount = $subtasks->count();

        return round(($completedCount / $totalCount) * 100, 2);
    }

    /**
     * Check if the task is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->due_date) {
            return false;
        }

        return $this->due_date->isPast() 
            && !in_array($this->status, ['completed', 'cancelled']);
    }

    /**
     * Check if the task can transition to a new status.
     * 
     * @param string $newStatus The target status
     * @return bool True if transition is allowed, false otherwise
     */
    public function canTransitionTo(string $newStatus): bool
    {
        // If transitioning to in_progress, check dependencies
        if ($newStatus === 'in_progress') {
            // Check if all prerequisite dependencies are completed
            if ($this->hasIncompleteDependencies()) {
                return false;
            }
        }

        // If transitioning to blocked, require a blocked_reason
        if ($newStatus === 'blocked' && empty($this->blocked_reason)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the task has incomplete dependencies.
     * Only checks 'blocks' and 'blocked_by' dependency types.
     * 
     * @return bool True if there are incomplete dependencies, false otherwise
     */
    public function hasIncompleteDependencies(): bool
    {
        // Check for tasks that this task depends on (blocks/blocked_by types)
        return $this->dependencies()
            ->whereIn('dependency_type', ['blocks', 'blocked_by'])
            ->whereHas('dependsOnTask', function ($query) {
                $query->whereNotIn('status', ['completed', 'cancelled']);
            })
            ->exists();
    }

    /**
     * Check if the task has unresolved issues.
     */
    public function hasUnresolvedIssues(): bool
    {
        return $this->issues()
            ->whereNull('resolved_at')
            ->exists();
    }

    /**
     * Get the dependency chain for this task.
     * Returns all tasks that this task depends on, recursively.
     * 
     * @param array $visited Array of task IDs already visited (to prevent infinite loops)
     * @return array Array of Task models in the dependency chain
     */
    public function getDependencyChain(array $visited = []): array
    {
        $chain = [];
        
        // Add current task to visited to prevent circular dependencies
        $visited[] = $this->id;
        
        foreach ($this->dependencies as $dependency) {
            $dependentTask = $dependency->dependsOnTask;
            
            // Skip if task doesn't exist or we've already visited it
            if (!$dependentTask || in_array($dependentTask->id, $visited)) {
                continue;
            }
            
            $chain[] = $dependentTask;
            
            // Recursively get dependencies of the dependent task
            $subChain = $dependentTask->getDependencyChain($visited);
            $chain = array_merge($chain, $subChain);
            
            // Update visited array with newly discovered tasks
            foreach ($subChain as $task) {
                $visited[] = $task->id;
            }
        }

        return $chain;
    }

    /**
     * Get all ancestor tasks (parent, grandparent, etc.).
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $current = $this->parentTask;

        while ($current) {
            $ancestors[] = $current;
            $current = $current->parentTask;
        }

        return $ancestors;
    }

    /**
     * Get all descendant tasks (children, grandchildren, etc.).
     */
    public function getDescendants(): array
    {
        $descendants = [];

        foreach ($this->subtasks as $subtask) {
            $descendants[] = $subtask;
            $descendants = array_merge($descendants, $subtask->getDescendants());
        }

        return $descendants;
    }

    /**
     * Get the effective assignee (subtask's assignee or parent's if null).
     */
    public function getEffectiveAssignee(): ?User
    {
        // If this task has an assigned user, return it
        if ($this->assigned_user_id) {
            return $this->assignedUser;
        }

        // If this is a subtask without an assignee, inherit from parent
        if ($this->parent_task_id && $this->parentTask) {
            return $this->parentTask->getEffectiveAssignee();
        }

        // No assignee found
        return null;
    }

    /**
     * Calculate total actual hours from time entries.
     */
    public function calculateActualHours(): float
    {
        return $this->timeEntries()->sum('hours');
    }

    /**
     * Calculate time variance (actual - estimated).
     */
    public function calculateTimeVariance(): ?float
    {
        if (!$this->estimated_hours) {
            return null;
        }

        $actualHours = $this->calculateActualHours();
        return round($actualHours - $this->estimated_hours, 2);
    }

    /**
     * Update actual_hours field based on time entries.
     */
    public function updateActualHours(): void
    {
        $this->actual_hours = $this->calculateActualHours();
        $this->save();
    }

    /**
     * Validate that setting a parent task doesn't create a circular relationship.
     * 
     * @param int|null $parentTaskId The proposed parent task ID
     * @return bool True if valid, false if circular relationship detected
     */
    public function validateParentTask(?int $parentTaskId): bool
    {
        // If no parent, it's valid
        if (!$parentTaskId) {
            return true;
        }

        // Can't be its own parent
        if ($parentTaskId === $this->id) {
            return false;
        }

        // Check if the proposed parent is a descendant of this task
        $descendants = $this->getDescendants();
        foreach ($descendants as $descendant) {
            if ($descendant->id === $parentTaskId) {
                return false;
            }
        }

        // Check if setting this parent would create a cycle
        // by traversing up the parent chain
        $parentTask = static::find($parentTaskId);
        if (!$parentTask) {
            return true; // Parent doesn't exist yet, allow it
        }

        $ancestors = $parentTask->getAncestors();
        foreach ($ancestors as $ancestor) {
            if ($ancestor->id === $this->id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a consolidated view of issues and experience logs in chronological order.
     * 
     * @return \Illuminate\Support\Collection Collection of issues and logs with type indicator
     */
    public function getConsolidatedActivityLog()
    {
        // Get all issues with a type indicator
        $issues = $this->issues()->get()->map(function ($issue) {
            return [
                'id' => $issue->id,
                'type' => 'issue',
                'title' => $issue->title,
                'content' => $issue->description,
                'severity' => $issue->severity ?? null,
                'issue_type' => $issue->issue_type ?? null,
                'status' => $issue->status ?? null,
                'user_id' => $issue->reported_by,
                'timestamp' => $issue->reported_at,
                'model' => $issue,
            ];
        });

        // Get all experience logs with a type indicator
        $logs = $this->experienceLogs()->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'type' => 'experience_log',
                'title' => $log->title,
                'content' => $log->content,
                'log_type' => $log->log_type,
                'tags' => $log->tags,
                'is_public' => $log->is_public,
                'user_id' => $log->user_id,
                'timestamp' => $log->logged_at,
                'model' => $log,
            ];
        });

        // Merge and sort by timestamp
        return $issues->concat($logs)->sortBy('timestamp')->values();
    }

    /**
     * Boot the model and register model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Validate parent task before saving to prevent circular relationships
        static::saving(function ($task) {
            if ($task->isDirty('parent_task_id')) {
                if (!$task->validateParentTask($task->parent_task_id)) {
                    throw new \InvalidArgumentException(
                        'Cannot set parent task: circular relationship detected'
                    );
                }
            }
        });
    }
}
