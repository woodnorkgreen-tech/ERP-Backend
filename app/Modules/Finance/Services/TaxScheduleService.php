<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\WhtCategory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The two returns WNG actually files with KRA, assembled from the ledger.
 *
 * WNG's statutory accounts are kept in an external package; this system is not
 * where the P&L is prepared and does not try to be. What it uniquely holds is
 * the transaction-level tax detail behind every shilling of project spend —
 * which supplier, under which PIN, against which eTIMS document, at which rate.
 * That detail is what a VAT return and a WHT remittance are made of, and it
 * exists nowhere else in the business.
 *
 * Two design rules run through everything here:
 *
 * 1. **Only posted, unreversed lines count.** A schedule that included
 *    unverified or reversed costs would not reconcile to the ledger it claims
 *    to summarise, and a return that does not tie to the books is worse than no
 *    return — it is an assertion to a revenue authority that cannot be
 *    defended when they ask to see it.
 *
 * 2. **Nothing here writes.** These are read models. Filing is an act performed
 *    by a person on KRA's portal; this produces the schedule they file from and
 *    the evidence they keep, and deliberately does not record "filed" state,
 *    which would be a claim the system cannot verify.
 */
class TaxScheduleService
{
    /**
     * Day of the following month by which VAT and WHT fall due.
     *
     * Read from settings rather than hardcoded. Kenya's current filing calendar
     * puts both on the 20th, but a filing deadline is a Finance Act fact that
     * moves, and a date this system asserts must be one Finance can correct
     * without a deployment.
     */
    private const DUE_DAY_SETTING = 'tax_return_due_day';
    private const DEFAULT_DUE_DAY = 20;

    /** Fallback when a treatment carries no window of its own. */
    private const CLAIM_WINDOW_SETTING = 'input_vat_claim_window_months';

    /**
     * Input VAT claimable for a period — the purchases side of the VAT return.
     *
     * Rows are keyed on the tax point (the supplier's document date), NOT on
     * when the project consumed the material. For a Stores issue those are
     * routinely months apart: material bought in March and issued in July is a
     * March claim, and filing it in July forfeits it.
     *
     * @return array<string, mixed>
     */
    public function vatInputSchedule(string $from, string $to): array
    {
        $rows = $this->claimableLines()
            ->whereBetween('cost_lines.tax_point_date', [$from, $to])
            ->orderBy('cost_lines.tax_point_date')
            ->orderBy('cost_lines.id')
            ->get()
            ->map(fn (object $line) => $this->claimRow($line));

        [$supported, $unsupported] = $rows->partition(fn (array $row) => $row['is_supported']);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'rows' => $rows->values()->all(),
            'totals' => [
                // The figure that goes on the return. Only supported claims —
                // an unsupported one is not a claim WNG can defend, and putting
                // it in the headline invites someone to file it.
                'claimable_vat' => $this->sum($supported, 'vat_amount'),
                'claimable_net' => $this->sum($supported, 'net_amount'),
                'unsupported_vat' => $this->sum($unsupported, 'vat_amount'),
                'line_count' => $rows->count(),
                'unsupported_count' => $unsupported->count(),
            ],
            'due_date' => $this->dueDateFor($to),
            'basis' => 'Verified, posted, unreversed cost lines on a recoverable VAT treatment, '
                . 'dated by the supplier document (tax point).',
        ];
    }

    /**
     * Recoverable VAT that cannot currently be claimed, and what it is costing.
     *
     * This is the report `finance_settings` promised and nothing implemented.
     * It answers one question: how much input tax is WNG about to lose because
     * the eTIMS reference was never captured, and how long is left to fix it.
     *
     * Ordered by how soon each claim dies rather than by value, because the
     * expensive one with four months left is a lower priority than the small one
     * with nine days.
     *
     * @return array<string, mixed>
     */
    public function etimsGap(?string $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::today();

        $rows = $this->claimableLines()
            ->where(function ($q) {
                $q->whereNull('cost_lines.etims_invoice_no')
                    ->orWhere('cost_lines.etims_invoice_no', '')
                    ->orWhereNull('cost_lines.supplier_pin')
                    ->orWhere('cost_lines.supplier_pin', '');
            })
            ->get()
            ->map(fn (object $line) => $this->claimRow($line, $asOf))
            ->sortBy(fn (array $row) => $row['days_to_deadline'] ?? PHP_INT_MAX)
            ->values();

        $expired = $rows->filter(fn (array $row) => $row['claim_status'] === 'expired');
        $expiring = $rows->filter(fn (array $row) => $row['claim_status'] === 'expiring');

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows->all(),
            'totals' => [
                // Already gone. Nothing can be done about these; they are shown
                // so the number is known rather than discovered at audit.
                'vat_forfeited' => $this->sum($expired, 'vat_amount'),
                'forfeited_count' => $expired->count(),
                // Still recoverable, but only if someone acts.
                'vat_at_risk' => $this->sum($expiring, 'vat_amount'),
                'at_risk_count' => $expiring->count(),
                'vat_unsupported_total' => $this->sum($rows, 'vat_amount'),
                'line_count' => $rows->count(),
            ],
            'action' => 'Each row needs the supplier\'s eTIMS invoice number, and a KRA PIN on the supplier record. '
                . 'Both can be added by reversing and re-verifying the cost line.',
        ];
    }

    /**
     * Withholding tax deducted in a month, by payee — the WHT remittance return.
     *
     * One row per payee, because that is how WHT is remitted and how a
     * certificate is issued: the supplier gets one certificate for the month
     * showing the aggregate withheld, not one per delivery note.
     *
     * @return array<string, mixed>
     */
    public function whtSchedule(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $lines = $this->postedLines()
            ->whereBetween('cost_lines.incurred_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->whereNotNull('cost_lines.wht_category_id')
            ->orderBy('cost_lines.incurred_at')
            ->get();

        $rows = $lines
            ->groupBy(fn (object $line) => $line->payee_type_id . ':' . ($line->payee_id ?: 'n/a') . ':' . $line->payee_name)
            ->map(fn (Collection $group) => $this->whtPayeeRow($group))
            // A payee whose whole month withheld nothing does not belong on a
            // remittance return; it belongs in the exposure list below if the
            // aggregate should have crossed a threshold, and nowhere otherwise.
            ->filter(fn (array $row) => bccomp($row['wht_amount'], '0.00', 2) === 1 || $row['aggregation_exposure'])
            ->sortByDesc(fn (array $row) => (float) $row['wht_amount'])
            ->values();

        $exposed = $rows->filter(fn (array $row) => $row['aggregation_exposure']);

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'rows' => $rows->all(),
            'totals' => [
                'wht_remittable' => $this->sum($rows, 'wht_amount'),
                'gross_subject' => $this->sum($rows, 'net_amount'),
                'payee_count' => $rows->count(),
                // The under-withholding the resolver's own comment flagged as a
                // known gap. See whtPayeeRow().
                'under_withheld' => $this->sum($exposed, 'aggregation_shortfall'),
                'exposed_payee_count' => $exposed->count(),
            ],
            'due_date' => $this->dueDateFor($end->toDateString()),
            'basis' => 'Verified, posted, unreversed cost lines carrying a WHT category, by payee, '
                . 'dated on when the cost was incurred.',
        ];
    }

    /**
     * One payee's month, and whether the per-payment threshold under-withheld it.
     *
     * `TaxResolver::withholding()` tests `threshold_amount` against a single
     * payment and says so in its own comment: where a category is marked
     * `aggregate_monthly`, KRA means the threshold to be tested against the
     * supplier's month. Five payments of 20,000 to one consultant under a 24,000
     * threshold each withhold nothing, while the 100,000 month plainly should
     * have. Nobody could see that before, because no supplier-month view
     * existed. This is that view, so the shortfall is computed and named rather
     * than left as a known gap.
     *
     * It is reported, never auto-corrected. Withholding more than was actually
     * deducted from a supplier is not a bookkeeping adjustment — the money was
     * already paid out in full, so recovering it is a conversation with the
     * supplier that Finance has to have.
     *
     * @param  Collection<int, object>  $group
     * @return array<string, mixed>
     */
    private function whtPayeeRow(Collection $group): array
    {
        $first = $group->first();

        $net = $group->reduce(fn (string $carry, object $l) => bcadd($carry, (string) $l->net_amount, 2), '0.00');
        $wht = $group->reduce(fn (string $carry, object $l) => bcadd($carry, (string) $l->wht_amount, 2), '0.00');

        $category = $first->wht_category_id ? WhtCategory::find($first->wht_category_id) : null;

        $shortfall = '0.00';
        $exposure = false;

        if ($category?->aggregate_monthly
            && $category->threshold_amount !== null
            && bccomp($category->rate_percent, '0', 3) === 1
            && bccomp($net, (string) $category->threshold_amount, 2) >= 0) {
            $shouldHave = bcdiv(bcmul($net, (string) $category->rate_percent, 5), '100', 2);
            $shortfall = bcsub($shouldHave, $wht, 2);
            $exposure = bccomp($shortfall, '0.00', 2) === 1;
        }

        return [
            'payee_name' => $first->payee_name,
            'payee_id' => $first->payee_id,
            'payee_type_id' => $first->payee_type_id,
            // The PIN a certificate must carry. Snapshotted on the line, so it
            // is the PIN the deduction was made under.
            'supplier_pin' => $group->pluck('supplier_pin')->filter()->first(),
            'wht_category_code' => $category?->code,
            'wht_category_name' => $category?->name,
            'rate_percent' => $category?->rate_percent,
            'net_amount' => $net,
            'wht_amount' => $wht,
            'payment_count' => $group->count(),
            'aggregation_exposure' => $exposure,
            'aggregation_shortfall' => $exposure ? $shortfall : '0.00',
            'aggregation_note' => $exposure
                ? sprintf(
                    'Each payment fell under the %s threshold, so nothing was withheld, but the month totals %s. '
                    . 'Category is marked aggregate-monthly: %s should have been withheld.',
                    number_format((float) $category->threshold_amount, 2),
                    number_format((float) $net, 2),
                    number_format((float) bcadd($wht, $shortfall, 2), 2),
                )
                : null,
            'refs' => $group->pluck('ref')->all(),
        ];
    }

    /**
     * One claim line, with the window arithmetic applied.
     *
     * @return array<string, mixed>
     */
    private function claimRow(object $line, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $taxPoint = $line->tax_point_date ? Carbon::parse($line->tax_point_date) : null;
        $window = (int) ($line->claim_window_months ?: FinanceSetting::integer(self::CLAIM_WINDOW_SETTING, 6));

        $deadline = ($taxPoint && $window) ? (clone $taxPoint)->addMonthsNoOverflow($window) : null;
        $daysLeft = $deadline ? $asOf->diffInDays($deadline, false) : null;

        $supported = filled($line->etims_invoice_no) && filled($line->supplier_pin);

        return [
            'cost_line_id' => (int) $line->id,
            'ref' => $line->ref,
            'job_number' => $line->job_number,
            'supplier_name' => $line->payee_name,
            'supplier_pin' => $line->supplier_pin,
            'supplier_invoice_no' => $line->supplier_invoice_no,
            'etims_invoice_no' => $line->etims_invoice_no,
            'tax_point_date' => $taxPoint?->toDateString(),
            'treatment_code' => $line->treatment_code,
            'rate_percent' => $line->rate_percent,
            'net_amount' => (string) $line->net_amount,
            'vat_amount' => (string) $line->tax_amount,
            'description' => $line->description,
            'is_supported' => $supported,
            'missing' => array_values(array_filter([
                filled($line->etims_invoice_no) ? null : 'etims_invoice_no',
                filled($line->supplier_pin) ? null : 'supplier_pin',
            ])),
            'claim_deadline' => $deadline?->toDateString(),
            'days_to_deadline' => $daysLeft,
            'claim_status' => $this->claimStatus($supported, $daysLeft),
        ];
    }

    /**
     * Four states, and only one of them is fine.
     *
     * `expiring` is thirty days rather than an arbitrary "soon" because a
     * missing eTIMS number means chasing a supplier for a document, and a month
     * is roughly the least time in which that reliably happens.
     */
    private function claimStatus(bool $supported, ?int $daysLeft): string
    {
        if ($supported) {
            return $daysLeft !== null && $daysLeft < 0 ? 'expired' : 'claimable';
        }

        if ($daysLeft === null) {
            return 'unsupported';
        }

        return match (true) {
            $daysLeft < 0 => 'expired',
            $daysLeft <= 30 => 'expiring',
            default => 'unsupported',
        };
    }

    /**
     * Cost lines that are in the books: verified, journalled, not reversed.
     *
     * `status = verified` already excludes reversals — a reversed cost moves to
     * `reversed` — and `posted_at` excludes anything the journal never accepted.
     * Both conditions are stated rather than relying on one implying the other,
     * because they are separate facts and a producer bug has broken that
     * implication before.
     */
    private function postedLines()
    {
        return DB::table('cost_lines')
            ->where('cost_lines.status', CostLine::STATUS_VERIFIED)
            ->whereNotNull('cost_lines.posted_at')
            ->whereIn('cost_lines.nature', [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL])
            ->select('cost_lines.*');
    }

    /** Posted lines whose VAT treatment actually claims input tax back. */
    private function claimableLines()
    {
        return $this->postedLines()
            ->join('vat_treatments', 'vat_treatments.id', '=', 'cost_lines.vat_treatment_id')
            ->where('vat_treatments.is_recoverable', true)
            ->where('cost_lines.tax_amount', '>', 0)
            ->addSelect([
                'vat_treatments.code as treatment_code',
                'vat_treatments.rate_percent as rate_percent',
                'vat_treatments.claim_window_months as claim_window_months',
            ]);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function sum(Collection $rows, string $key): string
    {
        return $rows->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row[$key], 2), '0.00');
    }

    /**
     * When the period's return falls due: the configured day of the next month.
     */
    private function dueDateFor(string $periodEnd): string
    {
        $day = FinanceSetting::integer(self::DUE_DAY_SETTING, self::DEFAULT_DUE_DAY);

        return Carbon::parse($periodEnd)
            ->addMonthNoOverflow()
            ->startOfMonth()
            // Clamped, so a 31-day setting does not roll February into March.
            ->addDays(min($day, Carbon::parse($periodEnd)->addMonthNoOverflow()->daysInMonth) - 1)
            ->toDateString();
    }
}
