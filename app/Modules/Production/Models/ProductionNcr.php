<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionNcr extends Model
{
    use HasFactory;

    protected $table = 'production_ncrs';

    protected $fillable = [
        'ncr_number',
        'work_order_id',
        'work_order_rework_id',
        'defect_code_id',
        'root_cause_code_id',
        'source_type',
        'source_ref',
        'shift',
        'raised_by_name',
        'job_order_no',
        'qc_stage',
        'workstation',
        'severity',
        'status',
        'description',
        'quantity_affected',
        'failure_description',
        'primary_sop_breached',
        'conformance_type',
        'items_rejected',
        'items_rejected_status',
        'rejects_location',
        'production_impact',
        'client_impacted',
        'immediate_action_taken',
        'root_cause_category',
        'root_cause_description',
        'preventive_action',
        'reinspection_performed',
        'reinspection_performed_status',
        'reinspection_performed_other',
        'reinspection_results',
        'image_path',
        'image_original_name',
        'resolution',
        'supervisor_approval',
        'supervisor_approved_by',
        'supervisor_approved_at',
        'containment_action',
        'corrective_action',
        'due_date',
        'detected_at',
        'detected_by',
        'owner_user_id',
        'is_concession_approved',
        'concession_reason',
        'closed_at',
        'closed_by',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'detected_at' => 'datetime',
        'quantity_affected' => 'decimal:2',
        'items_rejected' => 'decimal:2',
        'client_impacted' => 'boolean',
        'reinspection_performed' => 'boolean',
        'supervisor_approval' => 'boolean',
        'supervisor_approved_at' => 'datetime',
        'is_concession_approved' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function rework(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRework::class, 'work_order_rework_id');
    }

    public function defectCode(): BelongsTo
    {
        return $this->belongsTo(ProductionDefectCode::class, 'defect_code_id');
    }

    public function rootCauseCode(): BelongsTo
    {
        return $this->belongsTo(ProductionRootCauseCode::class, 'root_cause_code_id');
    }

    public function detector(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'detected_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'closed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionNcrEvent::class, 'ncr_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductionNcrAssignment::class, 'ncr_id');
    }

    public function closure(): HasOne
    {
        return $this->hasOne(ProductionNcrClosure::class, 'ncr_id');
    }
}
