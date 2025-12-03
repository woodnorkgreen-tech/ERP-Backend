<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TaskHistory extends Model
{
    use HasFactory;

    protected $table = 'task_history';

    protected $fillable = [
        'task_id',
        'user_id',
        'action',
        'field_name',
        'old_value',
        'new_value',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the task that owns the history record.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to filter by action type.
     */
    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Scope a query to filter by field name.
     */
    public function scopeByField(Builder $query, string $fieldName): Builder
    {
        return $query->where('field_name', $fieldName);
    }

    /**
     * Scope a query to filter by user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get a human-readable description of the change.
     *
     * @return string
     */
    public function getChangeDescription(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $user = $this->user ? $this->user->name : 'System';

        switch ($this->action) {
            case 'created':
                return "{$user} created the task";
            case 'updated':
                if ($this->field_name) {
                    return "{$user} changed {$this->field_name} from '{$this->old_value}' to '{$this->new_value}'";
                }
                return "{$user} updated the task";
            case 'deleted':
                return "{$user} deleted the task";
            case 'restored':
                return "{$user} restored the task";
            case 'status_changed':
                return "{$user} changed status from '{$this->old_value}' to '{$this->new_value}'";
            case 'assigned':
                return "{$user} assigned the task";
            default:
                return "{$user} performed action: {$this->action}";
        }
    }

    /**
     * Create a history record for a task creation.
     *
     * @param Task $task
     * @param int|null $userId
     * @return TaskHistory
     */
    public static function logCreation(Task $task, ?int $userId = null): TaskHistory
    {
        return static::create([
            'task_id' => $task->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'created',
            'description' => 'Task created',
        ]);
    }

    /**
     * Create a history record for a task update.
     *
     * @param Task $task
     * @param string $fieldName
     * @param mixed $oldValue
     * @param mixed $newValue
     * @param int|null $userId
     * @return TaskHistory
     */
    public static function logUpdate(Task $task, string $fieldName, $oldValue, $newValue, ?int $userId = null): TaskHistory
    {
        return static::create([
            'task_id' => $task->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'updated',
            'field_name' => $fieldName,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : (string)$oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : (string)$newValue,
        ]);
    }

    /**
     * Create a history record for a task deletion.
     *
     * @param Task $task
     * @param int|null $userId
     * @return TaskHistory
     */
    public static function logDeletion(Task $task, ?int $userId = null): TaskHistory
    {
        return static::create([
            'task_id' => $task->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'deleted',
            'description' => 'Task deleted',
        ]);
    }

    /**
     * Create a history record for a task restoration.
     *
     * @param Task $task
     * @param int|null $userId
     * @return TaskHistory
     */
    public static function logRestoration(Task $task, ?int $userId = null): TaskHistory
    {
        return static::create([
            'task_id' => $task->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => 'restored',
            'description' => 'Task restored',
        ]);
    }
}
