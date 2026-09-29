<?php

namespace App\Modules\ProcurementStores\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** W2-6 closure-gate §14: one Return-for-Correction cycle's before/after audit trail. */
class PurchaseOrderCorrection extends Model
{
    protected $fillable = [
        'purchase_order_id', 'correction_number',
        'returned_by', 'returned_at', 'return_reason', 'previous_snapshot',
        'corrected_by', 'corrected_at', 'corrected_snapshot', 'resubmitted_at',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
        'corrected_at' => 'datetime',
        'resubmitted_at' => 'datetime',
        'previous_snapshot' => 'array',
        'corrected_snapshot' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
