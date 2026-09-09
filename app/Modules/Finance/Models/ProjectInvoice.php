<?php

namespace App\Modules\Finance\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    protected $fillable = ['invoice_number','project_enquiry_id','invoice_date','due_date','subtotal','tax_amount','total_amount','status','notes','created_by','issued_by','issued_at','voided_by','voided_at','void_reason','journal_entry_id','accounting_period_id'];
    protected $casts = ['invoice_date'=>'date','due_date'=>'date','subtotal'=>'decimal:2','tax_amount'=>'decimal:2','total_amount'=>'decimal:2','issued_at'=>'datetime','voided_at'=>'datetime'];
    public function enquiry(): BelongsTo { return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function payments(): BelongsToMany { return $this->belongsToMany(\App\Models\EnquiryPayment::class, 'project_invoice_allocations')->withPivot('amount','allocated_by')->withTimestamps(); }

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
