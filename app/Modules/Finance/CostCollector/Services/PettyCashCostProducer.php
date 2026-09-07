<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;

/**
 * Petty cash disbursements → project cost lines.
 *
 * Petty cash remains the cash-custody ledger and is not modified. This reads its
 * disbursements and records the corresponding project cost, so the two ledgers
 * answer their own questions — "where is our cash" and "what did this project
 * cost" — from one set of payments.
 *
 * Disbursements land VERIFIED: they were approved and paid before this existed.
 * Asking someone to re-approve a payment already out of the tin would be
 * ceremony with no decision behind it.
 */
class PettyCashCostProducer
{
    public function __construct(
        private CostCollectorService $collector,
    ) {}

    /**
     * Streamed with a cursor rather than chunkById. This traversal writes while
     * it reads, and chunkById re-queries for every chunk — so a row written by
     * this run can influence a later chunk's window. A cursor takes one stable
     * result set up front and is not exposed to that.
     *
     * @return array{examined: int, posted: int, skipped_no_job: int, skipped_unmatched: int, skipped_inactive: int}
     */
    public function backfill(): array
    {
        $tally = [
            'examined' => 0, 'posted' => 0,
            'skipped_no_job' => 0, 'skipped_unmatched' => 0, 'skipped_inactive' => 0,
        ];

        foreach (PettyCashDisbursement::query()->orderBy('id')->cursor() as $disbursement) {
            $tally['examined']++;
            $tally[$this->postFor($disbursement)]++;
        }

        return $tally;
    }

    /** @return 'posted'|'skipped_no_job'|'skipped_unmatched'|'skipped_inactive' */
    public function postFor(PettyCashDisbursement $disbursement): string
    {
        // A voided or archived payment is not a project cost. Voids already have
        // a reversing entry on the cash ledger; mirroring them here would post a
        // cost that was explicitly undone.
        if ($disbursement->status !== 'active' || $disbursement->is_archived) {
            return 'skipped_inactive';
        }

        // The promise is discharged the moment the cash leaves, whether or not
        // the payment can be attributed to a job below. Releasing here — after
        // the void check, before the attribution checks — is what stops a paid
        // requisition being counted twice (once promised, once spent) or
        // leaving a commitment on the project forever.
        $this->releaseRequisitionCommitment($disbursement);

        // Settling a supplier invoice is a payment, not a cost.
        //
        // The goods on that invoice were already charged to the job by the
        // procurement relay: accrued when Stores accepted the delivery, turned
        // actual when Stores issued the material, each stage retiring the one
        // before. Cash leaving the tin afterwards discharges the liability the
        // accrual recorded — it does not buy anything a second time. Posting
        // here as well would charge the job twice for the same materials, and
        // CostAccountService sums actual + accrued + committed, so both would
        // land in the same total.
        //
        // Only reachable since supplier invoices could be paid from petty cash;
        // before that a disbursement never carried a bill, which is why nothing
        // needed to say this.
        if ($disbursement->requisition?->bill_id) {
            return 'skipped_supplier_settlement';
        }

        if (blank($disbursement->job_number)) {
            return 'skipped_no_job';
        }

        $enquiry = $this->resolveEnquiry($disbursement->job_number);

        // ADM-coded spend is real, but it is overhead against an internal code
        // rather than a client job — it has no cost object to attach to, and
        // inventing one would put overhead into a project's margin.
        if (! $enquiry) {
            return 'skipped_unmatched';
        }

        $this->collector->postFromSource(
            new CostContext(
                expenseCode: (string) ($disbursement->expenseCode?->code ?? ''),
                amount: (string) $disbursement->amount,
                nature: CostLine::NATURE_ACTUAL,
                enquiryId: $enquiry->id,
                jobNumber: $disbursement->job_number,
                consumesLineId: $disbursement->planned_cost_line_id,
                sourceType: PettyCashDisbursement::class,
                sourceId: $disbursement->id,
                taxAmount: (string) ($disbursement->tax_amount ?? '0'),
                incurredAt: (string) ($disbursement->date_disbursed ?? $disbursement->created_at),
                payeeName: $disbursement->receiver,
                description: $disbursement->description,
                details: array_filter([
                    // The only classification these 1,554 rows carry: a free-text
                    // account name from the retired chart. Kept verbatim so the
                    // lines can be mapped to expense codes once the catalogue is
                    // complete, rather than being lost to an "uncategorised" bucket.
                    'legacy_account' => $disbursement->account,
                    'receipt_type' => $disbursement->receipt_type,
                    'receipt_number' => $disbursement->receipt_number,
                    'transaction_cost' => $disbursement->transaction_cost,
                    'payment_source_id' => $disbursement->payment_source_id,
                    'payment_method' => $disbursement->payment_method,
                    'transaction_code' => $disbursement->transaction_code,
                    'venue' => $disbursement->venue,
                ]),
            ),
            ['site' => $disbursement->venue],
        );

        if (bccomp((string) ($disbursement->transaction_cost ?? '0'), '0', 2) === 1) {
            $this->collector->postFromSource(
                new CostContext(
                    expenseCode: 'OE-FIN-001',
                    amount: (string) $disbursement->transaction_cost,
                    nature: CostLine::NATURE_ACTUAL,
                    enquiryId: $enquiry->id,
                    jobNumber: $disbursement->job_number,
                    sourceType: PettyCashDisbursement::class,
                    sourceId: $disbursement->id,
                    sourceRef: 'transaction-fee',
                    taxAmount: '0',
                    incurredAt: (string) ($disbursement->date_disbursed ?? $disbursement->created_at),
                    payeeName: $disbursement->paymentSource?->name ?: 'Payment provider',
                    description: 'Transaction fee: ' . $disbursement->description,
                    details: array_filter([
                        'related_payment_reference' => $disbursement->transaction_code,
                        'payment_source_id' => $disbursement->payment_source_id,
                    ]),
                ),
                ['site' => $disbursement->venue],
            );
        }

        return 'posted';
    }

    /**
     * An approved requisition → a committed cost line.
     *
     * A requisition is a claim about the future: cash will be needed. It is the
     * petty-cash equivalent of an approved purchase order, and is recorded the
     * same way — as a commitment, which counts against the project's budget but
     * never reaches the general ledger. The cost itself is recorded later, from
     * the disbursement, when there is a payment to record.
     *
     * Idempotent on (source_type, source_id): re-approving cannot commit twice.
     *
     * @return 'committed'|'skipped_not_approved'|'skipped_no_project'|'skipped_no_amount'|'skipped_office_code'
     */
    public function commitFor(PettyCashRequisition $requisition): string
    {
        // Only an approved requisition is a promise. A pending one is a request
        // nobody has agreed to yet, and a disbursed one is already an actual.
        if ($requisition->status !== 'approved') {
            return 'skipped_not_approved';
        }

        // Office and departmental spend is real, but it has no project to be
        // committed against. Inventing one would put overhead into a margin.
        $enquiry = $requisition->enquiry ?? $requisition->project?->enquiry;

        if (! $enquiry) {
            return 'skipped_no_project';
        }

        if (bccomp((string) ($requisition->total_amount ?? '0'), '0', 2) !== 1) {
            return 'skipped_no_amount';
        }

        // The catalogue says which codes may carry a job number, and the office
        // codes may not — airtime and staff welfare are overhead, not a job's
        // cost. Nothing enforces that rule at write time, and the request form
        // still offers a project panel whatever the category, so a requester can
        // attach one to office spend. Committing it would put overhead into that
        // project's margin, which is the outcome the disbursement path already
        // refuses ADM-coded payments to avoid.
        $expenseCode = $requisition->requisitionType?->defaultExpenseCode;

        if ($expenseCode && $expenseCode->job_id_rule === ExpenseCode::JOB_NOT_ALLOWED) {
            return 'skipped_office_code';
        }

        $this->collector->postFromSource(
            new CostContext(
                // The requisition type names the form; the expense code it
                // carries names the accounting treatment. One taxonomy, so the
                // commitment and the payment that settles it classify alike.
                expenseCode: (string) ($requisition->requisitionType?->defaultExpenseCode?->code ?? ''),
                amount: (string) $requisition->total_amount,
                nature: CostLine::NATURE_COMMITTED,
                enquiryId: $enquiry->id,
                jobNumber: $enquiry->job_number,
                sourceType: PettyCashRequisition::class,
                sourceId: $requisition->id,
                // One key per approval, not per requisition.
                //
                // postFromSource() is idempotent on (source_type, source_id,
                // source_ref) and returns the existing line untouched — which is
                // what stops a re-approval double-committing. But an edited
                // requisition goes back to pending and is approved again, and
                // with a fixed key that second approval would silently keep the
                // first one's amount. Keying on the approval instant means the
                // re-approval records what was actually approved, while a
                // repeated event for the same approval still lands once.
                sourceRef: 'approval-' . ($requisition->approved_at?->format('YmdHis') ?? 'initial'),
                taxAmount: '0',
                incurredAt: (string) ($requisition->approved_at ?? now()),
                payeeName: $requisition->payee_name ?: $requisition->requester_name,
                description: $requisition->purpose
                    ? "Approved fund requisition: {$requisition->purpose}"
                    : "Approved fund requisition {$requisition->requisition_number}",
                details: array_filter([
                    'requisition_number' => $requisition->requisition_number,
                    'requisition_type' => $requisition->requisitionType?->name,
                    'venue' => $requisition->venue,
                ]),
            ),
            ['site' => $requisition->venue],
        );

        return 'committed';
    }

    /**
     * Retire a requisition's commitment because the approval behind it is gone.
     *
     * The payment path below releases a promise that was kept. This releases one
     * that was withdrawn — an approved requisition edited back to pending, which
     * is also the only route by which an approved requisition can be rejected.
     * Without it that commitment has no other exit: release is otherwise the job
     * of a payment which, in this case, is never coming.
     *
     * @return 'released'|'nothing_open'
     */
    public function releaseFor(PettyCashRequisition $requisition, string $reason): string
    {
        $commitment = $this->openCommitmentFor($requisition->id);

        if (! $commitment) {
            return 'nothing_open';
        }

        $this->collector->releaseCommitment($commitment, $reason);

        return 'released';
    }

    /**
     * Retire the commitment a disbursement is settling.
     *
     * The mirror of the purchase-order path, where an accepted goods receipt
     * releases the order's commitment before the accrual is posted. Safe to
     * call more than once: releaseCommitment() only acts on a line that is
     * still an open commitment.
     */
    private function releaseRequisitionCommitment(PettyCashDisbursement $disbursement): void
    {
        if (! $disbursement->requisition_id) {
            return;
        }

        $commitment = $this->openCommitmentFor($disbursement->requisition_id);

        if ($commitment) {
            $this->collector->releaseCommitment(
                $commitment,
                "Released by petty cash payment {$disbursement->id}.",
            );
        }
    }

    /**
     * The commitment a requisition is currently carrying, if any.
     *
     * A requisition approved more than once leaves a line per approval, each
     * under its own source_ref, but only ever one of them open — every
     * withdrawal releases the previous. `latest('id')` therefore reads the
     * promise that stands now rather than a superseded one.
     */
    private function openCommitmentFor(int $requisitionId): ?CostLine
    {
        return CostLine::where('nature', CostLine::NATURE_COMMITTED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->where('source_type', PettyCashRequisition::class)
            ->where('source_id', $requisitionId)
            ->latest('id')
            ->first();
    }

    /**
     * Job numbers exist in two notations. Enquiries use `WNG-01-2026-004`;
     * older disbursements use `WNG/01/26/004`. Normalising the second form
     * recovers 70 payments that would otherwise sit unattributed.
     */
    public static function normaliseJobNumber(string $jobNumber): string
    {
        $value = strtoupper(trim($jobNumber));

        if (! str_contains($value, '/')) {
            return $value;
        }

        $parts = array_values(array_filter(explode('/', $value), 'strlen'));

        if (count($parts) !== 4) {
            return $value;
        }

        [$prefix, $month, $year, $sequence] = $parts;

        return sprintf(
            '%s-%s-%s-%s',
            $prefix,
            str_pad($month, 2, '0', STR_PAD_LEFT),
            strlen($year) === 2 ? '20' . $year : $year,
            str_pad($sequence, 3, '0', STR_PAD_LEFT),
        );
    }

    private function resolveEnquiry(string $jobNumber): ?ProjectEnquiry
    {
        $exact = ProjectEnquiry::whereRaw('UPPER(TRIM(job_number)) = ?', [strtoupper(trim($jobNumber))])->first();

        if ($exact) {
            return $exact;
        }

        $normalised = self::normaliseJobNumber($jobNumber);

        return $normalised === strtoupper(trim($jobNumber))
            ? null
            : ProjectEnquiry::whereRaw('UPPER(TRIM(job_number)) = ?', [$normalised])->first();
    }
}
