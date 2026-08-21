<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\HR\Models\HRAuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only class that moves a cost line between states.
 *
 * CostCollectorService is the only thing that CREATES lines; this is the only
 * thing that changes their status. Splitting the two keeps both invariants
 * checkable: nothing else inserts, nothing else transitions.
 *
 * Amounts are never edited here either. Tax is the single exception, and only at
 * verification — recoverability depends on eTIMS validity and the claim window,
 * which nobody on site can judge, so the person capturing enters what the receipt
 * says and Finance splits it afterwards.
 */
class CostVerificationService
{
    /** Long enough to be a reason rather than a keystroke. Matches the
     *  petty-cash requisition self-approval override. */
    private const OVERRIDE_REASON_MIN = 15;

    private JournalPostingService $journalPostingService;
    private CostTaxPricer $pricer;

    public function __construct(
        private CostNotifier $notifier,
        ?JournalPostingService $journalPostingService = null,
        ?CostTaxPricer $pricer = null,
    ) {
        $this->journalPostingService = $journalPostingService ?? new JournalPostingService();
        // The same pricer the verification screen previews with, so what Finance
        // was shown before clicking is exactly what gets written.
        $this->pricer = $pricer ?? new CostTaxPricer();
    }

    /**
     * @param array{tax_amount?: string, vat_treatment_id?: int, wht_category_id?: int} $tax
     */
    public function verify(
        CostLine $line,
        User $verifier,
        array $tax = [],
        ?string $overrideReason = null,
    ): CostLine {
        return DB::transaction(function () use ($line, $verifier, $tax, $overrideReason) {
            // Re-read under a row lock. Without this, two browser requests can
            // both observe `submitted` and both attempt to post the same fact.
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($line, CostLine::STATUS_VERIFIED);
            $this->assertPeriodOpen($line);

            $isOwn = $this->assertMayVerify($line, $verifier, $overrideReason);

            $line->fill($this->pricer->attributesFor($line, $tax));

            // Availability is a verification-time fact. Other pending reports
            // may have been verified since this line was first captured.
            if ($line->consumes_line_id) {
                $planned = CostLine::whereKey($line->consumes_line_id)->lockForUpdate()->first();
                $drawn = (string) CostLine::where('consumes_line_id', $line->consumes_line_id)
                    ->where('status', CostLine::STATUS_VERIFIED)->sum('net_amount');
                $before = bcsub((string) ($planned?->net_amount ?? '0'), $drawn ?: '0', 2);
                $line->forceFill([
                    'budget_remaining_before' => $before,
                    'budget_remaining_after' => bcsub($before, (string) $line->net_amount, 2),
                ]);
            }

            $line->forceFill([
                'status' => CostLine::STATUS_VERIFIED,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'query_note' => null,
            ])->save();

            $line->refresh();

            // Inside the transaction on purpose: an override that is not
            // recorded did not happen, so the verification and its written
            // justification commit together or not at all.
            if ($isOwn) {
                $this->recordSelfVerification($line, $verifier, (string) $overrideReason);
            }

            // Only what has actually happened reaches the ledger. Planned and
            // committed lines are management figures — a budget and a purchase
            // order are not economic events, and journalling them would put
            // money the business has not spent into a tax return. Producers have
            // always applied this filter; this path did not, so a committed line
            // routed through verification would have posted.
            if (in_array($line->nature, [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL], true)) {
                $this->journalPostingService->postCostLine($line);
            }

            $this->notifier->verified($line);

            return $line;
        });
    }

    /**
     * Separation of duties, and its one documented exception.
     *
     * Enforced on the record rather than by permission, because a Finance user
     * who reports their own taxi fare still must not be the one who approves it.
     * `verifyOwn` names who may set that aside — Super Admin, or anyone holding
     * APPROVALS_SELF_APPROVE, for a one-person finance function or an
     * out-of-hours close — and the override still costs a written reason,
     * exactly as self-approving a petty-cash requisition already does.
     *
     * @return bool whether this was a self-verification, so the caller records it
     */
    private function assertMayVerify(CostLine $line, User $verifier, ?string $reason): bool
    {
        $isOwn = $line->submitted_by_user_id
            && $line->submitted_by_user_id === $verifier->id;

        if (! $isOwn) {
            return false;
        }

        if (! $verifier->can('verifyOwn', $line)) {
            throw CostValidationException::withErrors([
                'verified_by' => ['You reported this cost, so someone else has to verify it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'],
            ]);
        }

        if (mb_strlen(trim((string) $reason)) < self::OVERRIDE_REASON_MIN) {
            throw CostValidationException::withErrors([
                'override_reason' => [sprintf(
                    'Explain why independent verification is unavailable (at least %d characters).',
                    self::OVERRIDE_REASON_MIN,
                )],
            ]);
        }

        return true;
    }

    /**
     * The audit trail for a deliberately broken control.
     *
     * HRAuditLog despite the name — it is the general audit sink this codebase
     * already uses for finance events, spend-voucher posting included. The cost
     * ledger has no log of its own, and inventing a second one for a single
     * event would leave overrides split across two places to look.
     */
    private function recordSelfVerification(CostLine $line, User $verifier, string $reason): void
    {
        HRAuditLog::create([
            'user_id' => $verifier->id,
            'action' => 'cost_self_verification_override',
            'model_type' => CostLine::class,
            'model_id' => $line->id,
            'message' => sprintf(
                '%s self-verified %s of %s %s using the audited override.',
                $verifier->name,
                $line->ref,
                $line->currency ?: 'KES',
                $line->net_amount,
            ),
            'context' => [
                'reason' => trim($reason),
                'net_amount' => (string) $line->net_amount,
                'job_number' => $line->job_number,
                'expense_code_id' => $line->expense_code_id,
            ],
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Send it back with a question rather than killing it.
     *
     * Rejection loses the cost; a query keeps it alive and routes it back to the
     * person who can answer. Most "bad" submissions are incomplete, not wrong.
     */
    public function query(CostLine $line, User $verifier, string $note): CostLine
    {
        return $this->transition($line, CostLine::STATUS_QUERIED, [
            'query_note' => $note,
            'verified_by' => $verifier->id,
            'verified_at' => null,
        // The one that matters most: without it a query goes back to nobody.
        ], fn (CostLine $fresh) => $this->notifier->queried($fresh));
    }

    public function reject(CostLine $line, User $verifier, string $reason): CostLine
    {
        return $this->transition($line, CostLine::STATUS_REJECTED, [
            'query_note' => $reason,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ], fn (CostLine $fresh) => $this->notifier->rejected($fresh));
    }

    /**
     * Move a line to a terminal-ish state under the same lock `verify()` takes.
     *
     * These three read their status from the route-bound model and wrote over it
     * unconditionally, while `verify()` re-read under a row lock. That asymmetry
     * is losable money: a verify and a reject racing on one line both saw
     * `submitted`, verify posted the journal and committed, and reject then
     * stamped `rejected` from its stale read — leaving a rejected cost with a
     * posted, un-reversed entry behind it. Re-reading inside the lock makes the
     * loser fail its transition check instead of winning the write.
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(CostLine):void  $notify
     */
    private function transition(
        CostLine $line,
        string $target,
        array $attributes,
        callable $notify,
    ): CostLine {
        return DB::transaction(function () use ($line, $target, $attributes, $notify) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($line, $target);

            $line->forceFill(['status' => $target, ...$attributes])->save();

            $notify($line);

            return $line;
        });
    }

    /**
     * Back out a verified cost.
     *
     * Unposted lines are reversed by state; posted lines additionally receive a
     * balanced compensating journal in the current open period.
     */
    public function reverse(CostLine $line, User $verifier, string $reason): CostLine
    {
        return DB::transaction(function () use ($line, $verifier, $reason) {
            // Locked and re-read for the same reason as `verify()`: without it
            // two reversals of one posted line both pass the transition check,
            // and only `reverseCostLine`'s own idempotency stands between that
            // and a double compensating journal.
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($line, CostLine::STATUS_REVERSED);

            if ($line->posted_at) {
                try {
                    $this->journalPostingService->reverseCostLine($line, $verifier->id, $reason);
                } catch (InvalidArgumentException $e) {
                    throw CostValidationException::withErrors([
                        'status' => [$e->getMessage()],
                    ]);
                }
            } else {
                $this->assertPeriodOpen($line);
            }

            $line->forceFill([
                'status' => CostLine::STATUS_REVERSED,
                'query_note' => $reason,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ])->save();

            $this->notifier->reversed($line);

            return $line;
        });
    }

    /**
     * Reopen a queried line once the submitter has answered.
     *
     * Locked like the rest: `capture_meta` is read, appended to and written
     * back, so two answers racing would drop one of them silently — and a lost
     * response to a query is precisely the record that proves the query was
     * dealt with.
     */
    public function resubmit(CostLine $line, User $responder, string $response): CostLine
    {
        return DB::transaction(function () use ($line, $responder, $response) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($line, CostLine::STATUS_SUBMITTED);

            $meta = $line->capture_meta ?? [];
            $meta['query_responses'][] = [
                'response' => $response,
                'responded_by' => $responder->id,
                'responded_at' => now()->toIso8601String(),
            ];

            $line->forceFill([
                'status' => CostLine::STATUS_SUBMITTED,
                'capture_meta' => $meta,
            ])->save();

            // Back in the queue, so the verifiers need telling again — otherwise
            // an answered query sits waiting for someone who does not know it
            // moved.
            $this->notifier->submitted($line);

            return $line;
        });
    }

    /**
     * Re-point a cost at the budget line it belongs to — or detach it.
     *
     * This is a re-classification, not a re-pricing: no amount moves, so the
     * journal is untouched and no reversal is needed. That is what makes it
     * safe to allow after verification, which matters because the person who
     * knows which budget line a cost belongs to is usually Finance reading it
     * afterwards, not the technician who paid for it on site.
     *
     * The budget snapshot is recomputed rather than left as captured. It records
     * what the budget looked like when the cost was charged to it, and once the
     * cost is charged somewhere else the old snapshot describes a draw that no
     * longer exists.
     */
    public function reclassify(
        CostLine $line,
        User $user,
        ?int $plannedLineId,
        string $reason,
    ): CostLine {
        return DB::transaction(function () use ($line, $user, $plannedLineId, $reason) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->nature === CostLine::NATURE_PLANNED) {
                throw CostValidationException::withErrors([
                    'consumes_line_id' => ['A budget line cannot itself consume a budget line.'],
                ]);
            }

            if (in_array($line->status, [CostLine::STATUS_REJECTED, CostLine::STATUS_REVERSED], true)) {
                throw CostValidationException::withErrors([
                    'status' => ["A {$line->status} cost cannot be reclassified."],
                ]);
            }

            if ($plannedLineId === $line->consumes_line_id) {
                throw CostValidationException::withErrors([
                    'consumes_line_id' => ['This cost is already coded that way.'],
                ]);
            }

            $planned = $plannedLineId ? $this->assertPlannedLine($line, $plannedLineId) : null;

            $meta = $line->capture_meta ?? [];
            $meta['reclassifications'][] = [
                'from' => $line->consumes_line_id,
                'to' => $plannedLineId,
                'reason' => $reason,
                'by' => $user->id,
                'at' => now()->toIso8601String(),
            ];

            $line->forceFill([
                'consumes_line_id' => $plannedLineId,
                'capture_meta' => $meta,
                ...$this->budgetSnapshotFor($line, $planned),
            ])->save();

            return $line;
        });
    }

    /**
     * The planned line must exist, be a budget line, and belong to the same
     * project — coding a cost against another project's budget would move spend
     * between jobs without anything recording that it had happened.
     */
    private function assertPlannedLine(CostLine $line, int $plannedLineId): CostLine
    {
        $planned = CostLine::whereKey($plannedLineId)->lockForUpdate()->first();

        if (! $planned || $planned->nature !== CostLine::NATURE_PLANNED) {
            throw CostValidationException::withErrors([
                'consumes_line_id' => ['That is not a budget line.'],
            ]);
        }

        if ($planned->project_enquiry_id !== $line->project_enquiry_id) {
            throw CostValidationException::withErrors([
                'consumes_line_id' => ['That budget line belongs to a different project.'],
            ]);
        }

        return $planned;
    }

    /**
     * What the budget looked like at the moment this cost was pointed at it.
     *
     * The line's own draw is excluded from the "before" figure so that
     * re-pointing a verified cost does not count it against itself.
     *
     * @return array<string, ?string>
     */
    private function budgetSnapshotFor(CostLine $line, ?CostLine $planned): array
    {
        if (! $planned) {
            return ['budget_remaining_before' => null, 'budget_remaining_after' => null];
        }

        $drawn = (string) CostLine::where('consumes_line_id', $planned->id)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->whereKeyNot($line->id)
            ->sum('net_amount');

        $before = bcsub((string) $planned->net_amount, $drawn ?: '0', 2);

        return [
            'budget_remaining_before' => $before,
            'budget_remaining_after' => bcsub($before, (string) $line->net_amount, 2),
        ];
    }

    private function assertCanTransition(CostLine $line, string $target): void
    {
        if (! $line->canTransitionTo($target)) {
            throw CostValidationException::withErrors([
                'status' => ["A {$line->status} cost cannot become {$target}."],
            ]);
        }
    }

    private function assertPeriodOpen(CostLine $line): void
    {
        $period = $line->accounting_period_id
            ? AccountingPeriod::find($line->accounting_period_id)
            : null;

        if ($period && ! $period->isOpen()) {
            throw CostValidationException::withErrors([
                'accounting_period_id' => [sprintf(
                    'The %s %d period is %s.', $period->starts_on->format('F'), $period->year, $period->status,
                )],
            ]);
        }
    }
}
