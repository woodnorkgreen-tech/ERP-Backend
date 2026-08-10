<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Support\Facades\DB;

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
    private JournalPostingService $journalPostingService;

    public function __construct(
        private CostNotifier $notifier,
        ?JournalPostingService $journalPostingService = null,
    ) {
        $this->journalPostingService = $journalPostingService ?? new JournalPostingService();
    }

    /**
     * @param array{tax_amount?: string, vat_treatment_id?: int, wht_category_id?: int} $tax
     */
    public function verify(CostLine $line, User $verifier, array $tax = []): CostLine
    {
        $this->assertCanTransition($line, CostLine::STATUS_VERIFIED);
        $this->assertPeriodOpen($line);

        // Separation of duties. Enforced on the record rather than by permission,
        // because a Finance user who reports their own taxi fare still must not
        // be the one who approves it.
        if ($line->submitted_by_user_id && $line->submitted_by_user_id === $verifier->id) {
            throw CostValidationException::withErrors([
                'verified_by' => ['You cannot verify a cost you reported yourself.'],
            ]);
        }

        return DB::transaction(function () use ($line, $verifier, $tax) {
            $line->fill($this->taxAttributes($line, $tax));

            $line->forceFill([
                'status' => CostLine::STATUS_VERIFIED,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'query_note' => null,
            ])->save();

            $line->refresh();
            
            $this->journalPostingService->postCostLine($line);
            
            $this->notifier->verified($line);

            return $line;
        });
    }

    /**
     * Send it back with a question rather than killing it.
     *
     * Rejection loses the cost; a query keeps it alive and routes it back to the
     * person who can answer. Most "bad" submissions are incomplete, not wrong.
     */
    public function query(CostLine $line, User $verifier, string $note): CostLine
    {
        $this->assertCanTransition($line, CostLine::STATUS_QUERIED);

        $line->forceFill([
            'status' => CostLine::STATUS_QUERIED,
            'query_note' => $note,
            'verified_by' => $verifier->id,
            'verified_at' => null,
        ])->save();

        // The one that matters most: without it a query goes back to nobody.
        $this->notifier->queried($line);

        return $line;
    }

    public function reject(CostLine $line, User $verifier, string $reason): CostLine
    {
        $this->assertCanTransition($line, CostLine::STATUS_REJECTED);

        $line->forceFill([
            'status' => CostLine::STATUS_REJECTED,
            'query_note' => $reason,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ])->save();

        $this->notifier->rejected($line);

        return $line;
    }

    /**
     * Back out a verified cost.
     *
     * While nothing has reached a journal, a reversal is a state change: the line
     * stops counting and says who backed it out and why. Once GL posting exists a
     * posted line will instead need a compensating journal entry, so reversing
     * one is refused here rather than silently doing the wrong thing.
     */
    public function reverse(CostLine $line, User $verifier, string $reason): CostLine
    {
        $this->assertCanTransition($line, CostLine::STATUS_REVERSED);

        if ($line->posted_at) {
            throw CostValidationException::withErrors([
                'status' => ['This cost has been posted to the ledger and must be reversed by journal entry.'],
            ]);
        }

        $this->assertPeriodOpen($line);

        $line->forceFill([
            'status' => CostLine::STATUS_REVERSED,
            'query_note' => $reason,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ])->save();

        $this->notifier->reversed($line);

        return $line;
    }

    /** Reopen a queried line once the submitter has answered. */
    public function resubmit(CostLine $line): CostLine
    {
        $this->assertCanTransition($line, CostLine::STATUS_SUBMITTED);

        $line->forceFill(['status' => CostLine::STATUS_SUBMITTED])->save();

        // Back in the queue, so the verifiers need telling again — otherwise an
        // answered query sits waiting for someone who does not know it moved.
        $this->notifier->submitted($line);

        return $line;
    }

    /** @param array<string, mixed> $tax */
    private function taxAttributes(CostLine $line, array $tax): array
    {
        if (! array_key_exists('tax_amount', $tax)) {
            return array_filter([
                'vat_treatment_id' => $tax['vat_treatment_id'] ?? null,
                'wht_category_id' => $tax['wht_category_id'] ?? null,
            ]);
        }

        $taxAmount = (string) $tax['tax_amount'];

        if (bccomp($taxAmount, (string) $line->amount, 2) === 1) {
            throw CostValidationException::withErrors([
                'tax_amount' => ['Tax cannot exceed the amount on the receipt.'],
            ]);
        }

        $net = bcsub((string) $line->amount, $taxAmount, 2);

        return array_filter([
            'tax_amount' => $taxAmount,
            'net_amount' => $net,
            'base_net_amount' => bcmul($net, (string) $line->fx_rate, 2),
            'vat_treatment_id' => $tax['vat_treatment_id'] ?? null,
            'wht_category_id' => $tax['wht_category_id'] ?? null,
        ], fn ($value) => $value !== null);
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
