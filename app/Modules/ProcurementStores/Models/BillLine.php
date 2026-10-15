<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a supplier invoice against a purchase order (Report 76A-3).
 *
 * It says what the supplier charged for one order line — quantity, net unit
 * price, discount, tax — and, through its allocations, exactly which accepted
 * goods-receipt quantities that charge is for. `receipt_value_covered` and
 * `cost_difference` are computed from those allocations by
 * BillMatchingService and are never accepted from a request.
 */
class BillLine extends Model
{
    public const BASIS_STATED = 'stated';
    /** A summary bill with no line detail: allocated at order prices, so it can state no price difference. */
    public const BASIS_DERIVED = 'derived';

    protected $fillable = [
        'bill_id', 'line_no', 'purchase_order_item_id', 'description', 'quantity', 'unit_price_net', 'discount_amount',
        'net_amount', 'vat_treatment_id', 'vat_amount', 'gross_amount', 'allocated_quantity', 'receipt_value_covered',
        'cost_difference', 'variance_reason', 'posted_to_inventory', 'posted_to_variance', 'allocation_basis',
        'credited_quantity', 'credited_net', 'status',
    ];

    protected $casts = [
        'quantity' => 'decimal:6', 'unit_price_net' => 'decimal:4', 'discount_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'allocated_quantity' => 'decimal:6',
        'receipt_value_covered' => 'decimal:2', 'cost_difference' => 'decimal:2', 'posted_to_inventory' => 'decimal:2',
        'posted_to_variance' => 'decimal:2', 'credited_quantity' => 'decimal:6', 'credited_net' => 'decimal:2',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function vatTreatment()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\VatTreatment::class, 'vat_treatment_id');
    }

    public function allocations()
    {
        return $this->hasMany(BillLineReceiptAllocation::class);
    }

    /** Every billed unit is matched to an accepted receipt. */
    public function isFullyAllocated(): bool
    {
        return bccomp((string) $this->allocated_quantity, (string) $this->quantity, 6) === 0;
    }

    /** Quantity still billed after credits. */
    public function netQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->credited_quantity, 6);
    }
}
