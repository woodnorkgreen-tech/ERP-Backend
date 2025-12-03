<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    use HasFactory;

    protected $table = 'task_assignments';

    protected $fillable = [
        'task_id',
        'user_id',
        'assigned_by',
        'assigned_at',
        'role',
        'is_primary',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that this assignment belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user assigned to the task.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who made the assignment.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ==================== Methods ====================

    /**
     * Boot the model and register model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Set assigned_at timestamp if not provided
        static::creating(function ($assignment) {
            if (!$assignment->assigned_at) {
                $assignment->assigned_at = now();
            }
        });
    }
}
