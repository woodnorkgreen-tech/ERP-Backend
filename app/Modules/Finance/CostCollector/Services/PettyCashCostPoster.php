<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wave 1 Closure Gate §3.D: `RecordPettyCashCost` posts a disbursement's
 * cost/GL entry a moment later, on a queue, after the cash has already left
 * the float — the same shape of problem STAB-4 solved for requisition
 * advances (`PettyCashAdvancePoster`), just one step later in the same
 * disbursement's life. This is that same pattern, reused rather than
 * reinvented: the disbursement's own posting-status columns are the single
 * source of truth for whether Finance still needs to act, never just a log
 * line, and this is the one place both the original (queued) attempt and a
 * later controlled retry go through.
 *
 * Retrying is safe to call as many times as needed — `PettyCashCostProducer`
 * posts through `postFromSource()`/`postBalancedEntry()`, both idempotent on
 * their own source/entry-number keys, so a disbursement whose cost already
 * posted is simply confirmed again, not duplicated.
 */
class PettyCashCostPoster
{
    public function __construct(
        private PettyCashCostProducer $producer,
        private NotificationService $notifications,
    ) {
    }

    public function attempt(Payment $disbursement): string
    {
        try {
            $outcome = $this->producer->postFor($disbursement);

            if ($disbursement->cost_gl_posting_failed_at) {
                $disbursement->forceFill([
                    'cost_gl_posting_failed_at' => null,
                    'cost_gl_posting_error' => null,
                ])->save();
            }

            return $outcome;
        } catch (Throwable $e) {
            Log::warning('Could not post petty cash disbursement cost/GL entry', [
                'disbursement_id' => $disbursement->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $disbursement->forceFill([
                'cost_gl_posting_failed_at' => now(),
                'cost_gl_posting_error' => $e->getMessage(),
            ])->save();

            $this->alertFinance($disbursement, $e);

            return 'gl_posting_failed';
        }
    }

    /**
     * The whole body is one try/catch, not just the dispatch call — see
     * PettyCashAdvancePoster::alertFinance() for why: a notification failure
     * must never replace the real GL-posting failure with an unrelated error.
     */
    private function alertFinance(Payment $disbursement, Throwable $e): void
    {
        try {
            $recipients = $this->recipientIds();
            if (! $recipients) {
                return;
            }

            $this->notifications->dispatchNotification(
                type: 'petty_cash_cost_posting_failed',
                title: 'A petty cash disbursement did not reach the ledger',
                message: sprintf(
                    'Disbursement %s (KES %s) left the float, but its cost/GL entry could not be posted: %s. The float and the books will disagree until this is corrected.',
                    $disbursement->payment_no ?: $disbursement->id,
                    number_format((float) $disbursement->amount, 2),
                    $e->getMessage(),
                ),
                module: 'finance',
                urgency: 'critical',
                data: [
                    'disbursement_id' => $disbursement->id,
                    'payment_no' => $disbursement->payment_no,
                    'error' => $e->getMessage(),
                ],
                users: $recipients,
            );
        } catch (Throwable $notifyException) {
            Log::warning('Petty cash cost-posting-failed alert could not be sent', [
                'disbursement_id' => $disbursement->id,
                'exception' => $notifyException::class,
                'error' => $notifyException->getMessage(),
            ]);
        }
    }

    /** @return array<int, int> */
    private function recipientIds(): array
    {
        return User::query()
            ->permission(Permissions::FINANCE_PETTY_CASH_UPDATE)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }
}
