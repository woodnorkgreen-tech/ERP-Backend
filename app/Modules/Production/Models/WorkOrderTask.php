<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderTask extends Model
{
    use HasFactory;

    protected $table = 'work_order_tasks';

    protected $fillable = [
        'work_order_id',
        'workstation',
        'title',
        'quantity',
        'priority',
        'due_date',
        'notes',
        'included',
        'status',
        'status_reason',
        'safety_checks',
        'started_at',
        'paused_at',
        'completed_at',
        'created_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'due_date' => 'date',
        'included' => 'boolean',
        'safety_checks' => 'array',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(WorkOrderTaskAssignee::class, 'work_order_task_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WorkOrderTaskEvidence::class, 'work_order_task_id');
    }
}
