<?php

namespace App\Modules\Finance\Services;

use App\Constants\Permissions;
use App\Listeners\ProjectBudgetLines;
use App\Listeners\RecordGoodsReceiptAccruals;
use App\Listeners\RecordPettyCashCommitment;
use App\Listeners\RecordPettyCashCost;
use App\Listeners\RecordPurchaseOrderCommitments;
use App\Listeners\ReleasePettyCashCommitment;
use App\Listeners\ReversePettyCashCost;
use App\Listeners\SyncBudgetWithMaterialsList;
use App\Models\User;
use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Runs a cost-chain posting in the request that caused it, and remembers what
 * happened.
 *
 * ## The problem this closes (Report 76 P0-7)
 *
 * Eight listeners behind a project's budget, commitment, accrual and actual
 * cost were `ShouldQueue`. On a host with an asynchronous queue driver
 * and no worker — which is what production's own `jobs` table shows: 1,088
 * rows nothing ever picked up — none of them runs. No commitment when an order
 * is approved, no accrual (so no Inventory debit) when goods arrive, no cost
 * when a job-coded payment leaves the float, no reversal when it is voided.
 * And nothing fails: `failed()` is never called for a job that never started.
 * The test suite cannot see it either, because it runs `QUEUE_CONNECTION=sync`.
 *
 * ## What happens instead
 *
 *   business transaction commits
 *     → the posting runs synchronously, in the same PHP process
 *     → it succeeds: the row reads `posted`, with what it did
 *     → it fails:    the row reads `failed`, with the error, and Finance is told
 *     → an authorised person retries; every handler is idempotent on its own
 *       source key, so a retry of something that half-ran posts the rest and
 *       nothing twice
 *
 * A failure never reaches the caller. That is the property the queue was
 * originally there to provide — a cost-ledger problem must not stop somebody
 * approving an order or paying from the tin — and it is kept: the difference
 * is that the failure is now a row with an owner instead of a job with none.
 *
 * The row is written inside the caller's transaction and the work is deferred
 * to after its commit, so a posting cannot exist for a business event that
 * rolled back, and a business event cannot commit without its posting being
 * owed on record. The one gap left is a process that dies between the commit
 * and the work: that row stays `pending`, shows as stale after
 * {@see FinanceEventPosting::STALE_AFTER_MINUTES} minutes, and is retried the
 * same way (or swept by `finance:process-event-postings`).
 *
 * Notifications, emails and other non-financial side effects are not routed
 * through here and stay on the queue.
 */
class FinanceEventPoster
{
    public const PO_COMMITMENT = 'purchase_order_commitment';
    public const GRN_ACCRUAL = 'goods_receipt_accrual';
    public const REQUISITION_COMMITMENT = 'requisition_commitment';
    public const REQUISITION_COMMITMENT_RELEASE = 'requisition_commitment_release';
    public const PAYMENT_COST = 'payment_cost';
    public const PAYMENT_COST_REVERSAL = 'payment_cost_reversal';
    public const BUDGET_PROJECTION = 'budget_projection';
    public const BUDGET_MATERIALS_SYNC = 'budget_materials_sync';

    /**
     * Posting type => the class that does the work, through `post(array $payload): string`.
     * These are the former queued listeners; their event wiring is unchanged.
     */
    public const HANDLERS = [
        self::PO_COMMITMENT => RecordPurchaseOrderCommitments::class,
        self::GRN_ACCRUAL => RecordGoodsReceiptAccruals::class,
        self::REQUISITION_COMMITMENT => RecordPettyCashCommitment::class,
        self::REQUISITION_COMMITMENT_RELEASE => ReleasePettyCashCommitment::class,
        self::PAYMENT_COST => RecordPettyCashCost::class,
        self::PAYMENT_COST_REVERSAL => ReversePettyCashCost::class,
        self::BUDGET_PROJECTION => ProjectBudgetLines::class,
        self::BUDGET_MATERIALS_SYNC => SyncBudgetWithMaterialsList::class,
    ];

    /** What each type is, in words a Finance user reads. */
    public const LABELS = [
        self::PO_COMMITMENT => 'Purchase order commitment',
        self::GRN_ACCRUAL => 'Goods received — accrual and stock value',
        self::REQUISITION_COMMITMENT => 'Requisition commitment',
        self::REQUISITION_COMMITMENT_RELEASE => 'Requisition commitment release',
        self::PAYMENT_COST => 'Project cost of a payment',
        self::PAYMENT_COST_REVERSAL => 'Reversal of a voided payment\'s project cost',
        self::BUDGET_PROJECTION => 'Project budget lines',
        self::BUDGET_MATERIALS_SYNC => 'Budget material list (from the materials task)',
    ];

    /** How many postings are executing in this process right now. */
    private static int $running = 0;

    /**
     * Record that a posting is owed and run it once the caller's transaction
     * has committed.
     *
     * @param  bool  $afterResponse  run after the HTTP response has been sent
     *                               rather than before it. For work that is
     *                               idempotent, not a ledger entry, and raised
     *                               by a screen that saves continuously — the
     *                               budget projection. Still the same process,
     *                               still no worker; the row is `pending` in
     *                               the meantime.
     */
    public function record(string $type, int $subjectId, array $payload = [], bool $afterResponse = false): FinanceEventPosting
    {
        if (! isset(self::HANDLERS[$type])) {
            throw new InvalidArgumentException("Unknown finance posting type '{$type}'.");
        }

        $posting = FinanceEventPosting::updateOrCreate(
            ['posting_type' => $type, 'subject_id' => $subjectId],
            [
                'payload' => $payload ?: null,
                'status' => FinanceEventPosting::STATUS_PENDING,
                'requested_by' => auth()->id(),
                'requested_at' => now(),
            ],
        );

        $run = function () use ($posting): void {
            $this->attempt($posting);
        };

        // A posting raised from inside another posting (the materials sync
        // rewrites the budget, which announces a projection) runs in place:
        // the after-response hook is already executing and would not pick up
        // work added to it now.
        if ($afterResponse && self::$running === 0 && $this->servingAnHttpRequest()) {
            \Illuminate\Support\defer($run);
        } else {
            DB::afterCommit($run);
        }

        return $posting;
    }

    /**
     * Only an HTTP request has a response to run after. A console command, a
     * sweep or a test has none, so there the posting simply runs in place.
     */
    protected function servingAnHttpRequest(): bool
    {
        return ! app()->runningInConsole();
    }

    /**
     * Run one posting now. Never throws: the outcome is on the row.
     */
    public function attempt(FinanceEventPosting $posting): FinanceEventPosting
    {
        $posting = FinanceEventPosting::find($posting->id);
        if (! $posting) {
            return new FinanceEventPosting(['status' => FinanceEventPosting::STATUS_PENDING]);
        }

        $posting->forceFill([
            'status' => FinanceEventPosting::STATUS_PROCESSING,
            'attempts' => $posting->attempts + 1,
            'last_attempt_at' => now(),
        ])->save();

        self::$running++;

        try {
            $outcome = app(self::HANDLERS[$posting->posting_type])->post($posting->payload ?? [], $posting->subject_id);

            $posting->forceFill([
                'status' => FinanceEventPosting::STATUS_POSTED,
                'outcome' => mb_substr((string) $outcome, 0, 191),
                'last_error' => null,
                'posted_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            // The posting may have died inside a transaction of its own; make
            // sure the failure record is not written into a broken one.
            $this->recordFailure($posting, $exception);
        } finally {
            self::$running--;
        }

        return $posting;
    }

    /** An authorised, attributed re-run. Safe to repeat. */
    public function retry(FinanceEventPosting $posting, int $actorId): FinanceEventPosting
    {
        $posting->forceFill(['last_retried_by' => $actorId])->save();

        return $this->attempt($posting);
    }

    /**
     * Re-run everything failed or left unfinished. For a console sweep.
     *
     * @return array{examined:int, posted:int, failed:int}
     */
    public function sweep(int $limit = 500): array
    {
        $tally = ['examined' => 0, 'posted' => 0, 'failed' => 0];

        FinanceEventPosting::query()->needingAttention()->orderBy('id')->limit($limit)->get()
            ->each(function (FinanceEventPosting $posting) use (&$tally): void {
                $tally['examined']++;
                $result = $this->attempt($posting);
                $tally[$result->status === FinanceEventPosting::STATUS_POSTED ? 'posted' : 'failed']++;
            });

        return $tally;
    }

    private function recordFailure(FinanceEventPosting $posting, Throwable $exception): void
    {
        report($exception);

        $error = mb_substr($exception->getMessage() ?: $exception::class, 0, 2000);

        try {
            $posting->forceFill([
                'status' => FinanceEventPosting::STATUS_FAILED,
                'last_error' => $error,
            ])->save();
        } catch (Throwable $writeFailure) {
            Log::critical('A finance posting failed and its failure could not be recorded', [
                'posting_id' => $posting->id,
                'posting_type' => $posting->posting_type,
                'subject_id' => $posting->subject_id,
                'error' => $error,
                'write_error' => $writeFailure->getMessage(),
            ]);

            return;
        }

        Log::error('Finance posting failed', [
            'posting_id' => $posting->id,
            'posting_type' => $posting->posting_type,
            'subject_id' => $posting->subject_id,
            'attempts' => $posting->attempts,
            'error' => $error,
        ]);

        $this->alertFinance($posting, $error);
    }

    /** Wholly inside a try: a notification problem must never mask the posting's. */
    private function alertFinance(FinanceEventPosting $posting, string $error): void
    {
        try {
            $recipients = User::query()
                ->permission(Permissions::FINANCE_COSTS_VERIFY)
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            if (! $recipients) {
                return;
            }

            app(NotificationService::class)->dispatchNotification(
                type: 'finance_posting_failed',
                title: 'A cost posting did not reach the books',
                message: sprintf(
                    '%s (record %d) could not be posted: %s. The source document is saved; the project cost and ledger are behind it until this is retried.',
                    self::LABELS[$posting->posting_type] ?? $posting->posting_type,
                    $posting->subject_id,
                    $error,
                ),
                module: 'finance',
                urgency: 'critical',
                data: [
                    'finance_event_posting_id' => $posting->id,
                    'posting_type' => $posting->posting_type,
                    'subject_id' => $posting->subject_id,
                    'url' => '/finance/setup/readiness',
                ],
                users: $recipients,
            );
        } catch (Throwable $notifyFailure) {
            Log::warning('Finance posting failure alert could not be sent', [
                'posting_id' => $posting->id,
                'error' => $notifyFailure->getMessage(),
            ]);
        }
    }
}
