<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\ProjectInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The one reading of an invoice's state (Report 58 §6).
 *
 * An invoice has two independent facts, and they are reported separately so
 * neither is forced into the other:
 *
 *  - review_state: where the document stands in its control workflow —
 *    draft, returned_for_correction, awaiting_review (resubmitted), checked,
 *    issued, void — plus credit_note for a credit note row;
 *  - payment_state: for an issued invoice, whether money has been applied —
 *    unpaid, partially_paid, paid — and null before issue or when void.
 *
 * `review_state` keeps the legacy combined value the per-project endpoint has
 * always returned (issued / partially_paid / paid), so its existing consumers
 * are unchanged; `document_state` is the pure workflow value.
 *
 * No new state is invented: everything here is derived from `status`,
 * `checked_at`, `returned_at`, `resubmitted_at` and the verified-allocation and
 * credit-note sums the model's scopes already define.
 */
final class InvoiceState
{
    /** Filterable states, in lifecycle order. */
    public const STATES = ['draft', 'returned_for_correction', 'awaiting_review', 'checked', 'issued', 'partially_paid', 'paid', 'void'];

    public static function awaitingCorrection(ProjectInvoice $invoice): bool
    {
        return $invoice->status === 'draft' && (bool) $invoice->returned_at
            && (! $invoice->resubmitted_at || Carbon::parse($invoice->returned_at)->gt(Carbon::parse($invoice->resubmitted_at)));
    }

    /** Workflow position only, never payment. */
    public static function documentState(ProjectInvoice $invoice): string
    {
        return match (true) {
            $invoice->status === 'void' => 'void',
            $invoice->isCreditNote() => 'credit_note',
            in_array($invoice->status, ['issued', 'paid'], true) => 'issued',
            (bool) $invoice->checked_at => 'checked',
            self::awaitingCorrection($invoice) => 'returned_for_correction',
            (bool) $invoice->resubmitted_at => 'awaiting_review',
            default => 'draft',
        };
    }

    /** Whether money has been applied; null before issue, for credit notes and when void. */
    public static function paymentState(ProjectInvoice $invoice, float $paid, float $netTotal): ?string
    {
        if ($invoice->isCreditNote() || ! in_array($invoice->status, ['issued', 'paid'], true)) {
            return null;
        }
        $balance = max(0, $netTotal - $paid);

        return match (true) {
            $invoice->status === 'paid' || $balance <= 0 => 'paid',
            $paid > 0 || $balance < $netTotal => 'partially_paid',
            default => 'unpaid',
        };
    }

    /** The combined value the per-project invoice list has always returned. */
    public static function reviewState(ProjectInvoice $invoice, float $paid, float $netTotal): string
    {
        $document = self::documentState($invoice);
        if ($document !== 'issued') {
            return $document;
        }

        return match (self::paymentState($invoice, $paid, $netTotal)) {
            'paid' => 'paid',
            'partially_paid' => 'partially_paid',
            default => 'issued',
        };
    }

    /**
     * Days past due while money is still owed; 0 otherwise, so a settled
     * invoice is never overdue. The same rule as ReceivablesAgeingService
     * (issued or paid, balance above zero, measured from the start of today),
     * so an invoice's list row and its ageing bucket always agree.
     */
    public static function daysOverdue(ProjectInvoice $invoice, float $balance): int
    {
        if ($invoice->isCreditNote() || ! in_array($invoice->status, ['issued', 'paid'], true) || $balance <= 0 || ! $invoice->due_date) {
            return 0;
        }
        $due = Carbon::parse($invoice->due_date)->startOfDay();
        $today = now()->startOfDay();

        return $due->lt($today) ? (int) $due->diffInDays($today) : 0;
    }

    /**
     * Restricts a ProjectInvoice query to one state, in SQL, with the same
     * meaning as reviewState(). The verified-paid and credit-note sums are the
     * model scopes' own definitions, restated as correlated subqueries so the
     * filter runs before pagination.
     */
    public static function whereState(Builder $query, string $state): Builder
    {
        $paid = '(select coalesce(sum(a.amount),0) from project_invoice_allocations a join enquiry_payments p on p.id = a.enquiry_payment_id'
            .' where a.project_invoice_id = project_invoices.id and p.status = \'verified\' and p.reversed_at is null)';
        $net = '(project_invoices.total_amount + (select coalesce(sum(c.total_amount),0) from project_invoices c'
            .' where c.credits_invoice_id = project_invoices.id and c.status = \''.ProjectInvoice::EFFECTIVE_CREDIT_STATUS.'\'))';
        $returned = fn (Builder $q) => $q->whereNotNull('returned_at')
            ->where(fn (Builder $r) => $r->whereNull('resubmitted_at')->orWhereColumn('returned_at', '>', 'resubmitted_at'));

        return match ($state) {
            'void' => $query->where('status', 'void'),
            'checked' => $query->where('status', 'draft')->whereNotNull('checked_at'),
            'returned_for_correction' => $query->where('status', 'draft')->whereNull('checked_at')->where($returned),
            'awaiting_review' => $query->where('status', 'draft')->whereNull('checked_at')->whereNotNull('resubmitted_at')->whereNot($returned),
            'draft' => $query->where('status', 'draft')->whereNull('checked_at')->whereNull('resubmitted_at')->whereNull('returned_at'),
            'paid' => $query->where(fn (Builder $q) => $q->where('status', 'paid')
                ->orWhere(fn (Builder $i) => $i->where('status', 'issued')->whereRaw("$net - $paid <= 0"))),
            'partially_paid' => $query->where('status', 'issued')->whereRaw("$paid > 0")->whereRaw("$net - $paid > 0"),
            'issued' => $query->where('status', 'issued')->whereRaw("$paid = 0")->whereRaw("$net > 0"),
            // Not a state: money still owed (used by the overdue filter).
            'owed' => $query->whereRaw("$net - $paid > 0"),
            default => $query,
        };
    }
}
