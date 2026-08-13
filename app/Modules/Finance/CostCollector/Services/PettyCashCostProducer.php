<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

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
