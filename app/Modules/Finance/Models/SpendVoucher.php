<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendVoucher extends Model
{
    protected $table = 'spend_vouchers';

    protected $fillable = [
        'voucher_no',
        'type',
        'status',
        'transacted_at',
        'posting_date',
        'accounting_period_id',
        'payment_source_id',
        'custodian_user_id',
        'requester_user_id',
        'payee_type_id',
        'payee_id',
        'payee_name',
        'payee_phone',
        'payee_kra_pin',
        'payment_method',
        'payment_reference',
        'currency',
        'fx_rate',
        'total_amount',
        'base_total_amount',
        'supplier_id',
        'supplier_invoice_no',
        'supplier_invoice_date',
        'etims_invoice_no',
        'etims_cuin',
        'buyer_pin_captured',
        'net_amount',
        'vat_amount',
        'wht_amount',
        'net_cash_paid',
        'tax_due_date',
        'petty_cash_disbursement_id',
        'petty_cash_top_up_id',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'received_by',
        'received_at',
        'digital_signature',
        'reversal_of_id',
        'notes',
    ];

    protected $casts = [
        'transacted_at' => 'datetime',
        'posting_date' => 'date',
        'total_amount' => 'decimal:2',
        'base_total_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'net_cash_paid' => 'decimal:2',
        'buyer_pin_captured' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * Cost lines settled by this voucher — NOT AVAILABLE.
     *
     * Removed rather than left in place: it was declared as
     * `hasMany(CostLine::class, 'spend_voucher_id')`, and `cost_lines` has no
     * such column. Any code touching it raised
     * "Unknown column 'cost_lines.spend_voucher_id'", and both index() and
     * show() eager-loaded it — so the spend voucher list returned a 500 as soon
     * as a single voucher existed. The client swallowed that into console.error,
     * which is why it went unnoticed.
     *
     * The linkage itself is the right design and is still missing: a voucher is
     * meant to settle verified cost lines, which is what would give the module a
     * payables sub-ledger and a three-way match. Building it means adding the
     * column, deciding which lines a voucher may settle and what settling does
     * to their status — a schema and workflow change, not a relation. Until then
     * there is nothing here to relate to, and a throwing relation is worse than
     * an absent one.
     */

    /**
     * A voucher belongs to the reporting month its posting date falls in.
     *
     * The create endpoint resolves this and refuses when no open period covers
     * today, which is the right conversation to have with a person. This covers
     * every other writer: JournalPostingService will not post a voucher with no
     * period, so one written without it is a row that cannot be paid and says
     * nothing about why.
     */
    protected static function booted(): void
    {
        static::creating(function (self $voucher): void {
            if ($voucher->accounting_period_id !== null) {
                return;
            }

            $voucher->accounting_period_id = AccountingPeriod::forDate(
                $voucher->posting_date ?? $voucher->transacted_at ?? now(),
            )?->id;
        });
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }
}
