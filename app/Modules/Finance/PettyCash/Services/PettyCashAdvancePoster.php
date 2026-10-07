<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Critical Risk C5 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * posting a disbursement's advance journal entry used to be wrapped in a
 * catch that only logged a warning — cash left the float while the General
 * Ledger silently recorded nothing for it, with no signal to anyone and no
 * way to retry short of a console session.
 *
 * This is the one place both the original attempt
 * (PettyCashRequisitionController::disburse()) and a later retry
 * (PettyCashRequisitionController::retryAdvancePosting()) go through, so the
 * requisition's own gl-posting-failed columns stay the single source of
 * truth for whether Finance still needs to act — never just a log line.
 * Retrying is safe to call as many times as needed: postPettyCashAdvance()
 * posts through postBalancedEntry(), whose entry_no idempotency check means
 * a disbursement that already posted is simply returned again, not
 * duplicated.
 */
class PettyCashAdvancePoster
{
    public function __construct(
        private JournalPostingService $posting,
        private NotificationService $notifications,
    ) {
    }

    /**
     * The disbursement itself (the cash-side fact) is assumed to already
     * exist and to have committed before this runs — a GL-posting failure
     * here never rolls that back; it only decides what the requisition's
     * posting-status columns say afterwards.
     */
    public function attempt(PettyCashRequisition $requisition): void
    {
        $disbursement = $requisition->disbursement;
        if (! $disbursement) {
            return;
        }

        try {
            $entry = $this->posting->postPettyCashAdvance($disbursement);

            $requisition->forceFill([
                'advance_journal_entry_id' => $entry?->id ?? $requisition->advance_journal_entry_id,
                'advance_gl_posting_failed_at' => null,
                'advance_gl_posting_error' => null,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Could not post petty cash advance journal', [
                'requisition_id' => $requisition->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $requisition->forceFill([
                'advance_gl_posting_failed_at' => now(),
                'advance_gl_posting_error' => $e->getMessage(),
            ])->save();

            $this->alertFinance($requisition, $e);
        }
    }

    /**
     * Report 75R-A: the same guarantee for a requisition paid by receiver, where
     * each transfer is its own Payment and its own journal.
     *
     * The posting state lives on the Payment, not on the parent, so three
     * transfers are never represented by one journal link. The Payment row is
     * locked for the duration, which serialises a retry against a reversal of
     * the same transfer, and the entry number is derived from the Payment id, so
     * calling this again for a transfer that already posted returns the existing
     * entry instead of posting a second one.
     */
    public function attemptPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'active') {
                return;
            }

            try {
                // Savepoint: a failed post must not take the failure record down with it.
                [$entry] = DB::transaction(fn () => [
                    $this->posting->postPettyCashAdvance($payment),
                    $this->posting->postPaymentFee($payment),
                ]);

                $payment->forceFill([
                    'advance_journal_entry_id' => $entry?->id ?? $payment->advance_journal_entry_id,
                    'advance_gl_posting_failed_at' => null,
                    'advance_gl_posting_error' => null,
                ])->save();
            } catch (Throwable $e) {
                Log::warning('Could not post requisition payment advance journal', [
                    'payment_id' => $payment->id,
                    'child_reference' => $payment->requisition_child_reference,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                $payment->forceFill([
                    'advance_gl_posting_failed_at' => now(),
                    'advance_gl_posting_error' => $e->getMessage(),
                ])->save();

                if ($payment->requisition) {
                    $this->alertFinance($payment->requisition, $e, $payment);
                }
            }
        });
    }

    /**
     * The whole body is one try/catch, not just the dispatch call: resolving
     * recipients can itself throw (e.g. a permission row that has not been
     * created/synced yet in this environment), and this method is called
     * from inside attempt()'s own catch block — an exception escaping here
     * would replace the real GL-posting failure with an unrelated 500,
     * exactly the "a courtesy that can fail on its own" case this exists to
     * guard against.
     */
    private function alertFinance(PettyCashRequisition $requisition, Throwable $e, ?Payment $payment = null): void
    {
        try {
            $recipients = $this->recipientIds();
            if (! $recipients) {
                return;
            }

            $this->notifications->dispatchNotification(
                type: 'petty_cash_advance_posting_failed',
                title: 'Petty cash advance did not reach the ledger',
                message: sprintf(
                    'Requisition %s disbursed KES %s, but the advance could not be posted to the general ledger: %s. The float and the books will disagree until this is corrected.',
                    $payment?->requisition_child_reference ?: $requisition->requisition_number,
                    number_format((float) ($payment?->amount ?? $requisition->total_amount), 2),
                    $e->getMessage(),
                ),
                module: 'finance',
                urgency: 'critical',
                data: [
                    'requisition_id' => $requisition->id,
                    'requisition_number' => $requisition->requisition_number,
                    'payment_id' => $payment?->id,
                    'error' => $e->getMessage(),
                ],
                users: $recipients,
            );
        } catch (Throwable $notifyException) {
            // A notification that cannot be delivered must never be mistaken
            // for the underlying GL failure being resolved — the persistent
            // columns set above are the real record; this is a courtesy.
            Log::warning('Petty cash advance-posting-failed alert could not be sent', [
                'requisition_id' => $requisition->id,
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
