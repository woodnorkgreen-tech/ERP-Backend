<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report 75R-B: the part of one funded slice (a requisition line paid by one
 * Payment) that a surrender accounts for, as spend or as money returned.
 * This is what says exactly which advance is being cleared.
 */
class PettyCashSurrenderAllocation extends Model
{
    public const SPEND = 'spend';
    public const RETURN = 'return';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function surrender(): BelongsTo
    {
        return $this->belongsTo(PettyCashSurrender::class, 'surrender_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function slice(): BelongsTo
    {
        return $this->belongsTo(RequisitionPaymentAllocation::class, 'requisition_payment_allocation_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisitionItem::class, 'requisition_item_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
