<?php

namespace App\Modules\ProcurementStores\Models;

use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\ProcurementStores\Services\PurchaseOrderPriceFeedback;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;



class PurchaseOrder extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'requisition_id',
        'po_number',
        'date',
        'supplier_id',
        'due_date',
        'delivery_address',
        'description',
        'total_amount',
        'status',
        'user_id',
        'submitted_at',
        'approved_at',
        'approved_by',
        'returned_by',
        'returned_at',
        'return_reason',
        'resubmitted_at',
        'senior_approval_required',
        'senior_approved_by',
        'senior_approved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
        'resubmitted_at' => 'datetime',
        'senior_approval_required' => 'boolean',
        'senior_approved_at' => 'datetime',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo('App\Models\User', 'user_id')->with('employee');
    }

    public function approvedBy()
    {
        return $this->belongsTo('App\Models\User', 'approved_by')->with('employee');
    }

    /** W2-6: who sent this order back for correction, if it ever was. */
    public function returnedBy()
    {
        return $this->belongsTo('App\Models\User', 'returned_by')->with('employee');
    }

    /** W2-1: who gave the additional senior approval, if this order needed one. */
    public function seniorApprovedBy()
    {
        return $this->belongsTo('App\Models\User', 'senior_approved_by')->with('employee');
    }

    // CHANGED: invoices() -> bills() and Invoice::class -> Bill::class
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    /** W2-2: supplier evidence — the same generic finance_attachments the Wave 1 invoice uses. */
    public function attachments()
    {
        return $this->morphMany(\App\Modules\Finance\Models\FinanceAttachment::class, 'source');
    }

    /** W2-4: every proposed change to this order since it was approved, newest first. */
    public function amendments()
    {
        return $this->hasMany(PurchaseOrderAmendment::class)->orderByDesc('amendment_number');
    }

    /**
     * W2-4: while a commercial amendment awaits its own approval, the
     * original approved terms remain authoritative — receiving and billing
     * must not proceed against a proposed change nobody has agreed to yet.
     * An administrative amendment is never gated, since it never pauses
     * anything by design.
     */
    public function hasPendingCommercialAmendment(): bool
    {
        return $this->amendments()->where('status', 'pending')->where('is_commercial', true)->exists();
    }

    /**
     * W2-3: the running balance every staged-billing check is measured
     * against — `Remaining Billable = Approved PO Value − Sum(Valid Bills)`.
     * "Valid" excludes only cancelled bills; a bill awaiting verification still
     * reserves its share of the commitment, the same way an unpaid approved
     * requisition already reserves cover in PurchaseApprovalPolicy.
     *
     * Summed on `net_amount`, not gross `amount`: `total_amount` (and every
     * PurchaseOrderItem total it is built from) is VAT-exclusive — no VAT
     * field exists on an order line at all — so a bill's own VAT must be
     * excluded from this comparison for it to be the same figure on both
     * sides, exactly as PurchaseOrderWorkflow::bill()'s existing three-way
     * match already compares `Bill::netAmount()` against the order total,
     * never the gross invoice amount.
     */
    public function totalBilled(?int $excludingBillId = null): string
    {
        return (string) $this->bills()
            ->where('status', '!=', 'cancelled')
            ->when($excludingBillId, fn ($query) => $query->whereKeyNot($excludingBillId))
            ->sum('net_amount');
    }

    public function remainingBillable(?int $excludingBillId = null): string
    {
        $remaining = bcsub((string) $this->total_amount, $this->totalBilled($excludingBillId), 2);

        return bccomp($remaining, '0.00', 2) > 0 ? $remaining : '0.00';
    }

    /**
     * W2-1: the threshold in force today, or null if Finance has not signed
     * one off — `approvedValue()` (not `value()`) so a merely-proposed
     * number never silently starts gating approvals nobody agreed to.
     */
    public static function seniorApprovalThreshold(): ?string
    {
        $value = FinanceSetting::approvedValue('purchase_order_senior_approval_threshold');

        return is_numeric($value) ? (string) $value : null;
    }

    public function submitForApproval()
    {
        $threshold = self::seniorApprovalThreshold();

        $this->update([
            'status' => 'pending_approval',
            'submitted_at' => now(),
            // Decided once, at submission, off the threshold in force right
            // now — not re-evaluated live at approval time, so a threshold
            // Finance changes mid-flight cannot retroactively add or remove
            // the requirement on an order already awaiting a decision.
            'senior_approval_required' => $threshold !== null
                && bccomp((string) $this->total_amount, $threshold, 2) > 0,
        ]);
    }

    /**
     * W2-6: send a `pending_approval` order back to whoever raised it,
     * distinct from the amendment workflow (W2-4) — this is for an order
     * that was never approved in the first place.
     *
     * Closure-gate §14: `returned_by/at`/`return_reason` alone named who
     * returned it and why, but not what the requester actually changed —
     * only the final, corrected values survived. A `PurchaseOrderCorrection`
     * row now snapshots the submitted values at the moment of return (see
     * commercialSnapshot()) so a real before/after exists, without folding
     * this into the amendment mechanism, which answers a different question
     * (an already-approved order) at a different, separately-gated moment.
     */
    public function returnForCorrection(int $userId, string $reason): void
    {
        if ($this->status !== 'pending_approval') {
            throw new InvalidArgumentException('Only an order awaiting approval can be returned for correction.');
        }

        $this->update([
            'status' => 'returned_for_correction',
            'returned_by' => $userId,
            'returned_at' => now(),
            'return_reason' => $reason,
        ]);

        $number = ((int) $this->corrections()->max('correction_number')) + 1;
        $this->corrections()->create([
            'correction_number' => $number,
            'returned_by' => $userId,
            'returned_at' => now(),
            'return_reason' => $reason,
            'previous_snapshot' => $this->commercialSnapshot(),
        ]);
    }

    /** W2-6: the requester/procurement side corrects it, then resubmits — approval restarts. */
    public function resubmit(int $userId): void
    {
        if ($this->status !== 'returned_for_correction') {
            throw new InvalidArgumentException('Only an order returned for correction can be resubmitted.');
        }

        $threshold = self::seniorApprovalThreshold();

        $this->update([
            'status' => 'pending_approval',
            'resubmitted_at' => now(),
            'senior_approval_required' => $threshold !== null
                && bccomp((string) $this->total_amount, $threshold, 2) > 0,
        ]);

        // The open correction cycle this resubmission closes — always
        // exactly one, since returnForCorrection() requires pending_approval
        // (i.e. not already returned_for_correction) before opening another.
        $this->corrections()->whereNull('resubmitted_at')->latest('correction_number')->first()?->update([
            'corrected_by' => $userId,
            'corrected_at' => now(),
            'corrected_snapshot' => $this->commercialSnapshot(),
            'resubmitted_at' => now(),
        ]);
    }

    /** W2-6: every return/correct/resubmit cycle this order has been through, newest first. */
    public function corrections()
    {
        return $this->hasMany(PurchaseOrderCorrection::class)->orderByDesc('correction_number');
    }

    /** The order's current commercial shape, as a plain array — the same shape PurchaseOrderAmendmentController snapshots. */
    public function commercialSnapshot(): array
    {
        $this->loadMissing('items');

        return [
            'supplier_id' => $this->supplier_id,
            'delivery_address' => $this->delivery_address,
            'description' => $this->description,
            'due_date' => optional($this->due_date)->toDateString(),
            'date' => optional($this->date)->toDateString(),
            'total_amount' => (string) $this->total_amount,
            'items' => $this->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id,
                'material_id' => $item->material_id,
                'custom_description' => $item->custom_description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'uom_id' => $item->uom_id,
            ])->values()->all(),
        ];
    }

    /**
     * W2-1: the additional senior sign-off a high-value order needs before
     * final approval — layered on top of, never instead of, the normal
     * approve() call below, which still refuses to finalise until this has
     * happened.
     */
    public function seniorApprove(int $userId): void
    {
        if ($this->status !== 'pending_approval') {
            throw new InvalidArgumentException('Only an order awaiting approval can receive senior approval.');
        }
        if (! $this->senior_approval_required) {
            throw new InvalidArgumentException('This order is not above the senior-approval threshold.');
        }

        $this->update([
            'senior_approved_by' => $userId,
            'senior_approved_at' => now(),
        ]);
    }

    public function approve($userId)
    {
        if ($this->senior_approval_required && ! $this->senior_approved_at) {
            throw new InvalidArgumentException(
                'This order is above the senior-approval threshold and needs senior approval before it can be approved.'
            );
        }

        DB::transaction(function () use ($userId) {
            $this->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $userId,
            ]);

            app(PurchaseOrderPriceFeedback::class)->apply($this);
        });
    }
    /** Backwards-compatible latest receipt for older resources. */
    public function goodsReceiptNote()
    {
        return $this->hasOne(GoodsReceiptNote::class)->latestOfMany();
    }

    public function goodsReceiptNotes()
    {
        return $this->hasMany(GoodsReceiptNote::class);
    }
    public static function generatePONumber()
    {
        $year = date('Y');
        $prefix = "PO-{$year}-";

        $lastPO = self::where('po_number', 'like', "{$prefix}%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = (int) substr($lastPO->po_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
