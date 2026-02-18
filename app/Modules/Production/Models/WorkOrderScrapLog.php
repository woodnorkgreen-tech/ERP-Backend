<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderScrapLog extends Model
{
    use HasFactory;

    protected $table = 'work_order_scrap_logs';

    protected $fillable = [
        'work_order_id',
        'element_material_id',
        'stage',
        'reason',
        'quantity',
        'unit',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:2'
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function elementMaterial(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ElementMaterial::class, 'element_material_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
