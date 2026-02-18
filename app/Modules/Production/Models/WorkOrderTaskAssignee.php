<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderTaskAssignee extends Model
{
    use HasFactory;

    protected $table = 'work_order_task_assignees';

    protected $fillable = [
        'work_order_task_id',
        'assignee_type',
        'assignee_id'
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkOrderTask::class, 'work_order_task_id');
    }
}
