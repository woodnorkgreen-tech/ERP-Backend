<?php

namespace App\Modules\Finance\PettyCash\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Support\SelfApproval;

/**
 * What the current user may do next with a petty-cash requisition (W3,
 * Report 61), and the one presentation of its state.
 *
 * Each rule restates the precondition of the PettyCashRequisitionController
 * action it describes (approve, reject, disburse, confirmReceipt,
 * submitSurrender, returnSurrenderForCorrection, reconcileSurrender,
 * retryAdvancePosting) and PettyCashSurrenderReversalService, with the
 * backend's own refusal as the reason. Screens show an action only when
 * `allowed`; the controllers still enforce every rule.
 */
final class PettyCashActions
{
    /** @return array<string, array{allowed: bool, reason: ?string}> */
    public static function forRequisition(User $user, PettyCashRequisition $requisition): array
    {
        $status = $requisition->status;
        $requester = (int) $requisition->user_id === (int) $user->id;
        $selfAllowed = SelfApproval::allowedFor($user);
        $review = $user->can('reviewRequisition', Payment::class);
        $pay = $user->can('create', Payment::class);
        $mayView = $requester || $user->can('viewAllRequisitions', Payment::class);

        return [
            'approve' => self::rule([
                [$review, 'You are not authorized to approve requisitions.'],
                [$status === 'pending', 'Only a pending requisition can be approved.'],
                [! $requester || $selfAllowed, 'You raised this requisition, so someone else has to approve it.'],
            ]),
            'reject' => self::rule([
                [$review, 'You are not authorized to reject requisitions.'],
                [$status === 'pending', 'Only a pending requisition can be rejected.'],
                [! $requester || $selfAllowed, 'You raised this requisition, so someone else has to reject it.'],
            ]),
            'disburse' => self::rule([
                [$pay, 'You are not authorized to disburse requisitions.'],
                [$status === 'approved', 'Only an approved requisition can be disbursed.'],
                [! $requester || $selfAllowed, 'You raised this requisition, so someone else has to pay it out.'],
            ]),
            'confirm_receipt' => self::rule([
                [$requester, 'Only the requester can confirm receipt.'],
                [$status === 'disbursed', 'Only a disbursed requisition can have its receipt confirmed.'],
            ]),
            'submit_surrender' => self::rule([
                [in_array($status, ['disbursed', 'received', 'surrender_returned'], true), 'Only disbursed or received requisitions can be surrendered.'],
                [$requester || $pay, 'Only the requester or Finance can submit this surrender.'],
            ]),
            'return_surrender' => self::rule([
                [$pay, 'You are not authorized to review surrenders.'],
                [$status === 'surrender_pending', 'Only a submitted surrender can be returned for correction.'],
                [! $requester, 'You cannot review your own surrender.'],
            ]),
            'reconcile_surrender' => self::rule([
                [$pay, 'You are not authorized to reconcile surrenders.'],
                [in_array($status, ['disbursed', 'received', 'surrender_pending'], true), 'This surrender cannot be reconciled in its current state.'],
            ]),
            'reverse_surrender' => self::rule([
                [$user->can(Permissions::FINANCE_JOURNALS_REVERSE), 'You do not have permission to reverse surrenders.'],
                [$status === 'surrendered', 'Only a reconciled surrender can be reversed.'],
                [! $requester, 'The requester cannot reverse their own surrender.'],
                [(bool) $requisition->surrender_journal_entry_id, 'This surrender has no posted clearing journal to reverse.'],
            ]),
            'retry_posting' => self::rule([
                [$review, 'You are not authorized to correct petty cash postings.'],
                [(bool) $requisition->advance_gl_posting_failed_at, 'The advance has no failed ledger posting to retry.'],
            ]),
            'download_voucher' => self::rule([
                [$mayView, 'You may only download vouchers for your own requisitions.'],
            ]),
        ];
    }

    /**
     * The user-facing state (Report 61 §46). Backend statuses are unchanged;
     * this names them without implying closure before the workflow closes.
     * `received` is "Funds received — awaiting surrender", never "Complete".
     */
    public static function state(PettyCashRequisition $requisition): string
    {
        if ($requisition->advance_gl_posting_failed_at) {
            return 'gl_posting_failed';
        }

        return match ($requisition->status) {
            'pending' => 'awaiting_approval',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'disbursed' => 'disbursed',
            'received' => 'funds_received',
            'surrender_pending' => 'surrender_submitted',
            'surrender_returned' => 'returned_for_correction',
            'surrendered' => 'reconciled',
            default => (string) $requisition->status,
        };
    }

    /** @param  list<array{0: bool, 1: string}>  $conditions */
    private static function rule(array $conditions): array
    {
        foreach ($conditions as [$holds, $reason]) {
            if (! $holds) {
                return ['allowed' => false, 'reason' => $reason];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }
}
