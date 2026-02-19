<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderRework extends Model
{
    use HasFactory;

    protected $table = 'work_order_reworks';

    protected $fillable = [
        'work_order_id',
        'source_type',
        'source_ref',
        'qc_stage',
        'title',
        'reason',
        'status',
        'assigned_workstation',
        'assigned_to',
        'target_date',
        'qc_status',
        'qc_reason',
        'is_change_request',
        'created_by'
    ];

    protected $casts = [
        'is_change_request' => 'boolean',
        'target_date' => 'date'
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
