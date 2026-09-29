<?php

namespace App\Modules\ProcurementStores\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderAmendment extends Model
{
    protected $fillable = [
        'purchase_order_id', 'amendment_number', 'reason', 'requested_by', 'requested_at',
        'is_commercial', 'changed_fields', 'original_snapshot', 'proposed_snapshot',
        'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'is_commercial' => 'boolean',
        'changed_fields' => 'array',
        'original_snapshot' => 'array',
        'proposed_snapshot' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
