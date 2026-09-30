<?php

namespace App\Modules\Finance\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Support\SelfApproval;

/**
 * What the current user may do next with a supplier bill (W2, Report 60).
 *
 * Each rule restates the precondition of the action it describes —
 * BillController::verify / returnForCorrection / update / recordPayment
 * (+ directPaymentRefusal) and SupplierPaymentGuard — with that action's own
 * refusal as the reason. Screens show an action only when `allowed` is true
 * and say why otherwise; My Actions applies the same rules. The controllers
 * still enforce every one of them; this only describes them.
 */
final class PayablesActions
{
    /**
     * @param  array<string, mixed>  $state  PurchaseOrderWorkflow::bill()
     * @return array<string, array{allowed: bool, reason: ?string}>
     */
    public static function forBill(User $user, Bill $bill, array $state): array
    {
        $preparer = $bill->user_id !== null && (int) $bill->user_id === (int) $user->id;
        $returned = $bill->awaitingCorrection();
        $verified = (bool) ($state['verified'] ?? false);
        $closed = in_array($bill->status, ['paid', 'cancelled'], true);
        $canVerify = $user->can(Permissions::FINANCE_PAYABLES_VERIFY);
        $canPayDirectly = $user->can(Permissions::FINANCE_PETTY_CASH_CREATE);

        return [
            'verify' => self::rule([
                [$canVerify, 'You do not have permission to verify supplier invoices.'],
                [! $verified, 'This invoice has already been verified.'],
                [! $returned, 'This bill was returned to its preparer for correction and cannot be verified until they resubmit it.'],
                [(bool) ($state['eligible_for_verification'] ?? false), $state['blockers'][0] ?? 'This invoice does not yet pass the three-way match.'],
                [! $preparer || SelfApproval::allowedFor($user), 'You recorded this invoice, so someone else has to verify it.'],
            ]),
            'return' => self::rule([
                [$canVerify, 'You do not have permission to return supplier bills for correction.'],
                [! $bill->verified_at, 'A verified bill is corrected by credit or reversal, not returned.'],
                [! $closed && ! $bill->payments()->exists(), 'A paid or cancelled bill cannot be returned for correction.'],
                [! $returned, 'This bill is already with its preparer for correction.'],
                [! $preparer, 'You recorded this bill, so someone else has to return it.'],
            ]),
            'correct' => self::rule([
                [$returned, 'Only a bill returned for correction can be changed.'],
                [$preparer, 'Only the person who recorded this bill can correct it.'],
            ]),
            'pay' => self::rule([
                [$canPayDirectly, 'You are not authorised to pay supplier invoices. Raise a payment request instead.'],
                [$bill->verification_basis !== 'legacy', 'This invoice predates the three-way match and must be paid through an approved requisition.'],
                [(bool) ($state['can_pay'] ?? false), $state['blockers'][0] ?? 'This invoice is not cleared for payment.'],
            ]),
            // The route for someone who may not pay directly: an approved
            // petty-cash requisition against the bill (BillingShow's second door).
            'request_payment' => self::rule([
                [(bool) ($state['can_pay'] ?? false), $state['blockers'][0] ?? 'This invoice is not cleared for payment.'],
                [! $canPayDirectly || $bill->verification_basis === 'legacy', 'You can pay this invoice directly.'],
            ]),
        ];
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
