<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentAllocation - Links payments to cost lines they settle
 *
 * Phase 2 of Finance Architecture Redesign:
 * Makes "What did this payment settle?" a first-class relationship.
 * Replaces indirect link through spend_voucher_allocations.
 */
class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'cost_line_id',
        'amount',
        'allocation_type',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The payment that made this allocation.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * The cost line being settled by this allocation.
     */
    public function costLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'cost_line_id');
    }

    /**
     * Validate that allocation doesn't exceed cost line balance.
     */
    public function validateAmount(): bool
    {
        $costLine = $this->costLine;
        $existingAllocations = PaymentAllocation::where('cost_line_id', $this->cost_line_id)
            ->where('id', '!=', $this->id)
            ->sum('amount');

        $totalAllocated = $existingAllocations + $this->amount;

        return $totalAllocated <= $costLine->net_amount;
    }
}
