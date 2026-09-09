<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The month-end checklist, and the close itself.
 *
 * This was `ClosePeriodCommand::checklist()` — a private method inside a console
 * command, reachable only by a developer at a terminal. Finance owns the
 * decision to close a month, so Finance needs a screen; and the moment there is
 * a screen there are two implementations of "is this month finished", which
 * drift until they disagree about whether a month may be closed. So the checks
 * live here, and the command and the controller are both callers.
 *
 * ## Why closing a month matters
 *
 * A closed month is one nothing more can be posted into. Until a month is
 * closed, any figure already reported for it can still change underneath the
 * person who reported it. Every one of the 36 seeded periods was `open`, so the
 * guard that refuses to post into a shut month had never once fired and a cost
 * could be back-dated into January 2024.
 *
 * ## Blocking versus warning
 *
 * The distinction is deliberate and is the substance of the checklist:
 *
 * - **Blocking** — closing would put a real transaction in the wrong month, or
 *   would seal arithmetic that is already wrong. There is no reading under which
 *   proceeding is correct, so it stops.
 * - **Warning** — the month is accounting-complete, but there is money or an
 *   obligation somebody should look at before signing it off. Reclaimable tax
 *   with a deadline is the standing example: the books are right either way, and
 *   the close meeting is the last reliable moment anyone looks.
 *
 * A control that blocks on ordinary business gets worked around within a month
 * of shipping, so only the first kind blocks.
 */
class PeriodCloseService
{
    public function __construct(private TaxScheduleService $schedules)
    {
    }

    /**
     * Every check for one month, as data.
     *
     * Returned as an array rather than printed, so the console renders it with
     * ticks and colours and the API returns it as JSON, off one computation.
     *
     * @return array<string, mixed>
     */
    public function checklist(AccountingPeriod $period): array
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        $checks = [
            $this->pendingCosts($from, $to),
            $this->unpostedCosts($from, $to),
            $this->unclaimableInputVat($from, $to),
            $this->withholdingTax($period),
            $this->ledgerBalances($from, $to),
        ];

        $blockers = array_values(array_filter(
            $checks,
            fn (array $check) => $check['severity'] === 'blocker' && ! $check['passed'],
        ));

        return [
            'period' => [
                'id' => $period->id,
                'year' => $period->year,
                'month' => $period->month,
                'label' => $period->starts_on->format('F Y'),
                'starts_on' => $from,
                'ends_on' => $to,
                'status' => $period->status,
            ],
            'checks' => $checks,
            'blockers' => array_column($blockers, 'key'),
            'can_close' => $blockers === [] && $period->isOpen(),
        ];
    }

    /**
     * Close a month, refusing over blockers unless explicitly forced.
     *
     * `$force` exists because a blocker is a statement about the data, not about
     * the business: a month may genuinely need closing while a stuck cost line
     * is still being argued about. Forcing is recorded in the return value so
     * the caller can say so rather than reporting a clean close.
     *
     * @return array<string, mixed>
     */
    public function close(AccountingPeriod $period, ?int $actorId, bool $force = false): array
    {
        if (! $period->isOpen()) {
            throw new InvalidArgumentException(
                "The {$period->starts_on->format('F Y')} period is already {$period->status}."
            );
        }

        $checklist = $this->checklist($period);

        if ($checklist['blockers'] && ! $force) {
            throw new InvalidArgumentException(
                'This month cannot be closed yet: ' . $this->describe($checklist) . '.'
            );
        }

        $period->forceFill([
            'status' => AccountingPeriod::STATUS_CLOSED,
            'locked_at' => now(),
            'locked_by' => $actorId,
        ])->save();

        return $checklist + ['forced' => (bool) $checklist['blockers']];
    }

    /**
     * Lock a month without closing it.
     *
     * Locked and closed both refuse new postings; the difference is intent.
     * Locked says "stop posting, I am reviewing this"; closed says "this month
     * is final". Keeping them apart lets Finance freeze a month during review
     * and still reopen it without the reversal that undoing a close implies.
     */
    public function lock(AccountingPeriod $period, ?int $actorId): AccountingPeriod
    {
        if (! $period->isOpen()) {
            throw new InvalidArgumentException(
                "The {$period->starts_on->format('F Y')} period is already {$period->status}."
            );
        }

        $period->forceFill([
            'status' => AccountingPeriod::STATUS_LOCKED,
            'locked_at' => now(),
            'locked_by' => $actorId,
        ])->save();

        return $period;
    }

    /**
     * Reopen a locked or closed month.
     *
     * Deliberately possible, and deliberately uncomfortable: a reason is
     * required and the reopening is stamped on the period, because a month that
     * was reported on and then reopened is exactly the event an auditor asks
     * about. The alternative — an irreversible close — makes people avoid
     * closing at all, which is how every period ended up open in the first place.
     */
    public function reopen(AccountingPeriod $period, ?int $actorId, string $reason): AccountingPeriod
    {
        if ($period->isOpen()) {
            throw new InvalidArgumentException(
                "The {$period->starts_on->format('F Y')} period is already open."
            );
        }

        $period->forceFill([
            'status' => AccountingPeriod::STATUS_OPEN,
            'reopened_at' => now(),
            'reopened_by' => $actorId,
            'reopen_reason' => $reason,
        ])->save();

        return $period;
    }

    /** A one-line summary of why a close was refused. */
    private function describe(array $checklist): string
    {
        $failed = array_filter(
            $checklist['checks'],
            fn (array $check) => $check['severity'] === 'blocker' && ! $check['passed'],
        );

        return implode('; ', array_column($failed, 'message'));
    }

    /**
     * Costs still awaiting a decision.
     *
     * The real blocker. Once the month is closed these cannot be verified into
     * it at all, so closing over them silently pushes real spend into the wrong
     * month — which is worse than leaving the month open, because it looks
     * finished.
     */
    private function pendingCosts(string $from, string $to): array
    {
        $count = CostLine::whereIn('status', [CostLine::STATUS_SUBMITTED, CostLine::STATUS_QUERIED])
            ->whereBetween('incurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->count();

        return [
            'key' => 'pending_costs',
            'label' => 'Costs awaiting verification',
            'severity' => 'blocker',
            'passed' => $count === 0,
            'message' => $count === 0
                ? 'No cost lines are awaiting verification.'
                : "{$count} cost line(s) are still submitted or queried in this month.",
            'value' => $count,
        ];
    }

    /**
     * Verified but never journalled.
     *
     * A cost that reached `verified` without a journal entry is a posting
     * failure nothing else surfaces — typically a queue worker that stopped —
     * and month-end is the last chance to catch it before the month is sealed.
     */
    private function unpostedCosts(string $from, string $to): array
    {
        $count = CostLine::where('status', CostLine::STATUS_VERIFIED)
            ->whereIn('nature', [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL])
            ->whereNull('posted_at')
            ->whereBetween('incurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->count();

        return [
            'key' => 'unposted_costs',
            'label' => 'Verified costs that reached the ledger',
            'severity' => 'blocker',
            'passed' => $count === 0,
            'message' => $count === 0
                ? 'Every verified cost in this month is journalled.'
                : "{$count} verified cost line(s) never reached the ledger.",
            'value' => $count,
        ];
    }

    /**
     * Reclaimable tax with no document behind it.
     *
     * Warning, not blocker: the month's books are complete either way. But this
     * is money with an expiry date on it, and the close meeting is the last
     * moment anyone reliably looks at the month as a whole.
     */
    private function unclaimableInputVat(string $from, string $to): array
    {
        $schedule = $this->schedules->vatInputSchedule($from, $to);
        $count = (int) $schedule['totals']['unsupported_count'];
        $amount = (float) $schedule['totals']['unsupported_vat'];

        return [
            'key' => 'unclaimable_input_vat',
            'label' => 'Input Value Added Tax that can be claimed',
            'severity' => 'warning',
            'passed' => $count === 0,
            'message' => $count === 0
                ? 'All recoverable input Value Added Tax in this month is substantiated.'
                : sprintf(
                    'KES %s across %d line(s) has no eTIMS reference and cannot be claimed yet.',
                    number_format($amount, 2),
                    $count,
                ),
            'value' => $amount,
        ];
    }

    /**
     * What the month owes the Kenya Revenue Authority in withholding tax.
     *
     * Informational by design. It never blocks, because the remittance is a
     * separate obligation on its own clock — stating it at close is what stops
     * the close and the remittance drifting apart.
     */
    private function withholdingTax(AccountingPeriod $period): array
    {
        $wht = $this->schedules->whtSchedule($period->year, $period->month);
        $underWithheld = (string) $wht['totals']['under_withheld'];

        $message = sprintf(
            'KES %s recorded across %d payee(s). %s',
            number_format((float) $wht['totals']['wht_remittable'], 2),
            $wht['totals']['payee_count'],
            $wht['remittance_rule'],
        );

        if (bccomp($underWithheld, '0.00', 2) === 1) {
            $message .= sprintf(
                ' KES %s under-withheld across %d payee(s) whose month crossed an aggregate threshold.',
                number_format((float) $underWithheld, 2),
                $wht['totals']['exposed_payee_count'],
            );
        }

        return [
            'key' => 'withholding_tax',
            'label' => 'Withholding tax to remit',
            'severity' => 'info',
            'passed' => bccomp($underWithheld, '0.00', 2) !== 1,
            'message' => $message,
            'value' => (float) $wht['totals']['wht_remittable'],
        ];
    }

    /**
     * The ledger's own arithmetic for the month.
     *
     * Reversed originals are counted alongside their compensating entries. They
     * are both real postings, and excluding the original would leave the
     * reversal as an unsupported one-sided figure.
     */
    private function ledgerBalances(string $from, string $to): array
    {
        $sides = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereBetween('je.posting_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE 0 END) as debit")
            ->selectRaw("SUM(CASE WHEN jl.entry_type = 'credit' THEN jl.base_amount ELSE 0 END) as credit")
            ->first();

        $debit = number_format((float) ($sides->debit ?? 0), 2, '.', '');
        $credit = number_format((float) ($sides->credit ?? 0), 2, '.', '');
        $balanced = bccomp($debit, $credit, 2) === 0;

        return [
            'key' => 'ledger_balanced',
            'label' => 'Journals balance',
            'severity' => 'blocker',
            'passed' => $balanced,
            'message' => $balanced
                ? "Journals balance at {$debit}."
                : "Journals for this month do not balance: debit {$debit} against credit {$credit}.",
            'value' => $debit,
        ];
    }
}
