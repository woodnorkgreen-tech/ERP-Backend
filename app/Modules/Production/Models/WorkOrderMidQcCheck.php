<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderMidQcCheck extends Model
{
    use HasFactory;

    protected $table = 'work_order_mid_qc_checks';

    protected $fillable = [
        'work_order_id',
        'workstation',
        'qc_stage',
        'category',
        'title',
        'notes',
        'status',
        'checked_by',
        'checked_at'
    ];

    protected $casts = [
        'checked_at' => 'datetime'
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'checked_by');
    }
}
