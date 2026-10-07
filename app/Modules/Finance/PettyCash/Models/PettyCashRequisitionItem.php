<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\HR\Models\Employee;

class PettyCashRequisitionItem extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_requisition_items';

    protected static function booted(): void
    {
        $invalidate = function (self $item) {
            $r = $item->requisition()->first();
            if ($r?->verification_status === 'verified') {
                $r->invalidateVerification();
                $r->save();
            }
        };
        static::created($invalidate);
        static::deleted($invalidate);
        static::updated(function (self $item) use ($invalidate) {
            if ($item->wasChanged(['description', 'remarks', 'details', 'amount', 'payee_id', 'payee_name', 'payee_phone', 'supplier_id', 'other_recipient_reference'])) {
                $invalidate($item);
            }
        });
    }


    protected $fillable = [
        'requisition_id',
        'description',
        'remarks',
        'details',
        'amount',
        'payee_id',
        'payee_name',
        'payee_phone', 'supplier_id', 'other_recipient_reference',
        'digital_signature',
        'received_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'details' => 'array',
    ];

    /**
     * Get the requisition this item belongs to.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    /**
     * Get the payee for this item.
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_id');
    }

    /** Report 75R-A: a line payable to a supplier or service provider. */
    public function supplier()
    {
        return $this->belongsTo(\App\Modules\ProcurementStores\Models\Supplier::class, 'supplier_id');
    }

    /** Report 75R-A: the Payments that funded this line, voided ones included. */
    public function paymentAllocations()
    {
        return $this->hasMany(RequisitionPaymentAllocation::class, 'requisition_item_id');
    }
}
