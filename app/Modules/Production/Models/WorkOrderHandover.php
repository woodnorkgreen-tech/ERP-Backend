<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderHandover extends Model
{
    use HasFactory;

    protected $table = 'work_order_handovers';

    protected $fillable = [
        'work_order_id',
        'job_number',
        'client_name',
        'project',
        'description',
        'quantity',
        'condition',
        'handed_over_by',
        'received_by',
        'remarks',
        'created_by'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
