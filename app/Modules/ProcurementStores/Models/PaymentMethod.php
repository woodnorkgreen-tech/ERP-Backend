<?php

namespace App\Modules\ProcurementStores\Models;

use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a supplier bill was paid, as procurement names it.
 *
 * The names here are WNG's own — "Equity Bank", "NCBA Bank" — and are what a
 * clerk recognises on a payment screen. What the money actually left is the
 * `payment_sources` row behind it, which carries the ledger account. Keeping
 * both is deliberate: the familiar name stays on the screen and the account
 * stays with Finance, instead of procurement maintaining a second, GL-less
 * copy of the same list.
 */
class PaymentMethod extends Model
{
    protected $fillable = ['method_name', 'payment_source_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Where the money left from, and therefore which account it credits. */
    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    /**
     * Methods that can actually settle a bill.
     *
     * A method with no payment source names no ledger account, so a payment
     * made through it cannot be posted. Offering it would produce a payment
     * Finance can see on the bill and cannot find in the books.
     */
    public function scopeSettleable($query)
    {
        return $query->where('is_active', true)->whereHas('paymentSource', fn ($source) => $source->where('is_active', true));
    }

    public function payments()
    {
        return $this->hasMany(BillPayment::class);
    }
}
