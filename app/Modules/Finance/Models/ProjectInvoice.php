<?php

namespace App\Modules\Finance\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A client invoice.
 *
 * The header's money columns are DERIVED from `lines` by `InvoicePricer` — they
 * are not an independent claim. Before Stage 1 of the general ledger plan they
 * were typed by a person, so the Value Added Tax on a client invoice had no rate
 * behind it and nothing to check it against, while supplier tax on the cost side
 * had been priced off effective-dated treatments for months. Write through the
 * pricer, never straight into `subtotal` / `tax_amount` / `total_amount`.
 */
class ProjectInvoice extends Model
{
    protected $fillable = ['invoice_number','project_enquiry_id','credits_invoice_id','invoice_date','due_date','subtotal','tax_amount','total_amount','status','notes','created_by','issued_by','issued_at','voided_by','voided_at','void_reason','journal_entry_id','accounting_period_id','checked_by','checked_at','returned_by','returned_at','return_reason','resubmitted_at','no_quote_exception_reason','no_quote_exception_requested_by','no_quote_exception_approved_by','no_quote_exception_approved_at','no_quote_exception_evidence_reference','payment_term_id'];
    protected $casts = ['invoice_date'=>'date','due_date'=>'date','subtotal'=>'decimal:2','tax_amount'=>'decimal:2','total_amount'=>'decimal:2','issued_at'=>'datetime','voided_at'=>'datetime','checked_at'=>'datetime','returned_at'=>'datetime','resubmitted_at'=>'datetime','no_quote_exception_approved_at'=>'datetime'];
    public function enquiry(): BelongsTo { return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function payments(): BelongsToMany { return $this->belongsToMany(\App\Models\EnquiryPayment::class, 'project_invoice_allocations')->withPivot('amount','allocated_by')->withTimestamps(); }

    /** W1-1: who checked/reviewed this draft — never the preparer (`created_by`). */
    public function checkedBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_by'); }

    /** Return for Correction: who sent it back, if it ever was. */
    public function returnedBy(): BelongsTo { return $this->belongsTo(User::class, 'returned_by'); }

    /** W1-2: who requested, and who authorized, issuing without an approved commercial basis. */
    public function noQuoteExceptionRequestedBy(): BelongsTo { return $this->belongsTo(User::class, 'no_quote_exception_requested_by'); }
    public function noQuoteExceptionApprovedBy(): BelongsTo { return $this->belongsTo(User::class, 'no_quote_exception_approved_by'); }

    /** W1-7: the configured term this invoice's due date was computed from, if any. */
    public function paymentTerm(): BelongsTo { return $this->belongsTo(PaymentTerm::class, 'payment_term_id'); }

    /** Private evidence retrieved only through authenticated Finance routes. */
    public function attachments(): MorphMany
    {
        return $this->morphMany(FinanceAttachment::class, 'source');
    }

    /** The invoice this row corrects. Null on an ordinary invoice. */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_invoice_id');
    }

    /** Credit notes raised against this invoice. Always empty on a credit note itself. */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credits_invoice_id');
    }

    public function isCreditNote(): bool
    {
        return $this->credits_invoice_id !== null;
    }

    /**
     * Adds `net_total_amount` — this invoice's total after every non-void
     * credit note raised against it. Credit note totals are stored negative
     * (see the migration that added `credits_invoice_id`), so this is a plain
     * sum, not a subtraction that could be applied to the wrong sign by a
     * future caller.
     *
     * The one definition of "what this invoice is really worth now" that the
     * invoice list, receivables ageing and the allocation cap all share —
     * mirroring why `scopeWithVerifiedPaidAmount()` exists.
     */
    public function scopeWithNetTotal(Builder $query): Builder
    {
        return $query->addSelect([
            'net_total_amount' => self::query()
                ->selectRaw('project_invoices.total_amount + COALESCE(SUM(credit_notes.total_amount), 0)')
                ->from('project_invoices as credit_notes')
                ->whereColumn('credit_notes.credits_invoice_id', 'project_invoices.id')
                ->where('credit_notes.status', '!=', 'void'),
        ]);
    }

    /**
     * Adds `paid_amount` as the sum of this invoice's allocations, counting
     * only verified, non-reversed payments — the one definition of "what
     * counts as paid" shared by every reader of an invoice's balance.
     *
     * Before this scope existed, `EnquiryController::projectInvoices()` summed
     * every allocation with no status filter, so a `pending` or `reversed`
     * `EnquiryPayment` wrongly reduced the reported balance a client still
     * owed. `FinanceService::getPaymentProgress()` already applied this same
     * filter correctly on the quote-progress path; this scope is that pattern
     * made reusable instead of a second, independent copy of the fix.
     */
    public function scopeWithVerifiedPaidAmount(Builder $query): Builder
    {
        return $query->withSum(['payments as paid_amount' => function ($q) {
            $q->whereNull('enquiry_payments.reversed_at')
                ->where('enquiry_payments.status', 'verified');
        }], 'project_invoice_allocations.amount');
    }

    /** What is being billed, priced line by line. Ordered as the client sees it. */
    public function lines(): HasMany
    {
        return $this->hasMany(ProjectInvoiceLine::class, 'project_invoice_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The entry that recognised this invoice's revenue.
     *
     * Null means the invoice was issued before the ledger recorded revenue at
     * all. That is left visible rather than back-filled: inventing revenue into
     * a month already reported on would be worse than an honest gap.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
