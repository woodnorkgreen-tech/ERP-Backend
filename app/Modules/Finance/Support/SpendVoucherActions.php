<?php

namespace App\Modules\Finance\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\SpendVoucher;
use App\Support\SelfApproval;
use Illuminate\Support\Facades\DB;

/**
 * What the current user may do next with a payment voucher (W4, Report 63),
 * and the one presentation of its state.
 *
 * Each rule restates the precondition of the SpendVoucherController action it
 * describes (cancel, correct, resubmit, approve, returnForCorrection, reject,
 * seniorApprove, post) and of PaymentController::reverse, with the backend's
 * own refusal as the reason. Screens show an action only when `allowed`; the
 * controllers still enforce every rule.
 *
 * A voucher is a settlement document. Posting it mints the Payment (the cash
 * fact) and the journal that relieves the liability, in one transaction; it
 * never recognises a cost. The cost was recognised once, when the cost line it
 * pays was verified.
 */
final class SpendVoucherActions
{
    public const SENIOR_THRESHOLD_KEY = 'spend_voucher_senior_approval_threshold';

    private const AWAITING = ['pending_approval', 'draft'];

    private const RETURNED = ['returned_for_correction', 'corrected'];

    /** @return array<string, array{allowed: bool, reason: ?string}> */
    public static function forVoucher(User $user, SpendVoucher $voucher, ?Payment $payment = null): array
    {
        $status = $voucher->status;
        $review = $voucher->review_state;
        $uid = (int) $user->id;
        $requester = (int) $voucher->requester_user_id === $uid;
        $approver = $voucher->approved_by !== null && (int) $voucher->approved_by === $uid;
        $self = SelfApproval::allowedFor($user);
        $awaiting = in_array($status, self::AWAITING, true);
        $returned = in_array($review, self::RETURNED, true);
        $create = $user->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE);
        $approve = $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        return [
            'cancel' => self::rule([
                [$create, 'You are not authorized to cancel payment vouchers.'],
                [$awaiting, 'Only a voucher awaiting approval can be cancelled.'],
                [$requester, 'Only the person who created this voucher can cancel it.'],
            ]),
            'correct' => self::rule([
                [$create, 'You are not authorized to correct payment vouchers.'],
                [$review === 'returned_for_correction' && $requester, 'Only the original requester can correct a returned voucher.'],
            ]),
            'resubmit' => self::rule([
                [$create, 'You are not authorized to resubmit payment vouchers.'],
                [$returned && $requester, 'Only the original requester can resubmit a returned voucher.'],
            ]),
            'approve' => self::rule([
                [$approve, 'You are not authorized to approve payment vouchers.'],
                [$awaiting, 'Only vouchers awaiting approval can be approved.'],
                [! $returned, 'This voucher was returned for correction. It can be approved once the requester resubmits it.'],
                [! $requester || $self, 'You requested this payment voucher, so someone else has to approve it.'],
            ]),
            'return' => self::rule([
                [$approve, 'You are not authorized to review payment vouchers.'],
                [$awaiting, 'Only a voucher awaiting approval can be returned.'],
                [! $returned, 'This voucher is already with its requester for correction.'],
                [! $requester || $self, 'The requester cannot review their own voucher.'],
            ]),
            'reject' => self::rule([
                [$approve, 'You are not authorized to reject payment vouchers.'],
                [$awaiting, 'Only a voucher awaiting approval can be rejected.'],
                [! $requester || $self, 'The requester cannot reject their own voucher. Cancel it instead.'],
            ]),
            'senior_approve' => self::rule([
                [$user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR), 'You are not authorized to give senior approval.'],
                [$status === 'approved' && $review === 'awaiting_senior_approval', 'This voucher is not awaiting senior approval.'],
                [! ($requester || $approver) || $self, 'Senior approval must be independent of the requester and ordinary approver.'],
            ]),
            'post' => self::rule([
                [$user->can(Permissions::FINANCE_SPEND_VOUCHERS_POST), 'You are not authorized to post payment vouchers.'],
                [$status === 'approved' && ! $voucher->posted_at, 'Only an approved, unposted voucher can be posted.'],
                [$review !== 'awaiting_senior_approval', 'This voucher is above the senior-approval threshold and needs senior approval before it can be posted.'],
                [! ($requester || $approver) || $self, 'The requester and approver cannot post this voucher.'],
                [$status !== 'approved' || self::periodOpen($voucher), 'The accounting period this voucher belongs to is not open.'],
            ]),
            'reverse' => self::rule([
                [$user->can(Permissions::FINANCE_PAYMENTS_REVERSE), 'You are not authorized to reverse payments.'],
                [$status === 'posted' && $payment !== null, 'Only a posted voucher has a payment to reverse.'],
                [$payment === null || $payment->status === 'active', 'This payment is already voided.'],
                [$payment === null || ! DB::table('finance_statement_matches')->where('payment_id', $payment->id)->exists(),
                    'This payment is reconciled. Unmatch it from the statement before reversing it.'],
            ]),
        ];
    }

    /**
     * The voucher's state, one key per situation a person acts on.
     *
     * `rejected` covers two different endings in the stored status: a reviewer's
     * refusal (rejected_by set) and the requester's own withdrawal (cancel()
     * leaves it unset). They are shown apart.
     */
    public static function state(SpendVoucher $voucher): string
    {
        return match (true) {
            $voucher->status === 'reversed' => 'reversed',
            $voucher->status === 'posted' => 'posted',
            $voucher->status === 'rejected' => $voucher->rejected_by ? 'rejected' : 'cancelled',
            $voucher->status === 'approved' && $voucher->review_state === 'awaiting_senior_approval' => 'awaiting_senior_approval',
            $voucher->status === 'approved' => 'approved',
            $voucher->review_state === 'returned_for_correction' => 'returned_for_correction',
            $voucher->review_state === 'corrected' => 'corrected',
            $voucher->review_state === 'resubmitted' => 'resubmitted',
            default => 'awaiting_approval',
        };
    }

    /**
     * The three facts the brief keeps apart. Approval is not posting, and
     * posting a voucher is the moment it pays: SpendVoucherController::post
     * mints the Payment and the journal together, so the two never disagree.
     *
     * @return array{approval: string, posting: string, payment: string}
     */
    public static function facets(SpendVoucher $voucher, ?Payment $payment): array
    {
        $state = self::state($voucher);

        return [
            'approval' => match ($state) {
                'awaiting_approval', 'resubmitted' => 'pending',
                'returned_for_correction', 'corrected' => 'returned',
                'awaiting_senior_approval' => 'senior_pending',
                'rejected' => 'rejected',
                'cancelled' => 'cancelled',
                default => 'approved',
            },
            'posting' => match ($state) {
                'posted' => 'posted',
                'reversed' => 'reversed',
                default => 'not_posted',
            },
            'payment' => match (true) {
                $payment === null => 'unpaid',
                $payment->status === 'active' => 'paid',
                default => 'voided',
            },
        ];
    }

    /**
     * W4-2 as it stands, without inventing a threshold or an approver.
     *
     * `approvedValue()` is what approve() enforces; `value()` also shows a
     * proposed threshold nobody has signed off, which is not enforced.
     *
     * @return array{state: string, threshold: ?string, proposed: ?string, approvers: int}
     */
    public static function seniorPolicy(): array
    {
        $approved = FinanceSetting::approvedValue(self::SENIOR_THRESHOLD_KEY);
        $proposed = FinanceSetting::value(self::SENIOR_THRESHOLD_KEY);

        try {
            $approvers = User::permission(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR)->count();
        } catch (\Throwable) {
            $approvers = 0;
        }

        return [
            'state' => is_numeric($approved) ? 'active' : (is_numeric($proposed) ? 'awaiting_sign_off' : 'not_configured'),
            'threshold' => is_numeric($approved) ? number_format((float) $approved, 2, '.', '') : null,
            'proposed' => ! is_numeric($approved) && is_numeric($proposed) ? number_format((float) $proposed, 2, '.', '') : null,
            'approvers' => $approvers,
        ];
    }

    private static function periodOpen(SpendVoucher $voucher): bool
    {
        $period = $voucher->accounting_period_id ? AccountingPeriod::query()->find($voucher->accounting_period_id) : null;

        return $period !== null && $period->isOpen();
    }

    /** @param list<array{0: bool, 1: string}> $checks */
    private static function rule(array $checks): array
    {
        foreach ($checks as [$ok, $reason]) {
            if (! $ok) {
                return ['allowed' => false, 'reason' => $reason];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }
}
