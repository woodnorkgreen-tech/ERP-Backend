<?php

namespace App\Modules\Finance\Support;

use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\User;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Support\SelfApproval;

/**
 * What the current user may do next with an invoice or a client receipt (W1).
 *
 * Each rule restates the precondition of the controller action it describes
 * (EnquiryController: check/return/issue/update/void/credit-notes/allocate;
 * verifyPayment/updatePayment/deletePayment and FinanceService), with the
 * controller's own refusal as the reason. The screens show an action only when
 * `allowed` is true and explain why otherwise, and the work queue applies the
 * same maker/checker rules, so the three never disagree. The controllers still
 * enforce every rule themselves; this only describes them.
 *
 * @phpstan-type Action array{allowed: bool, reason: ?string}
 */
final class ReceivablesActions
{
    /**
     * @param  array{paid: float, net_total: float, allocations: bool, credit_notes: bool, allocatable_receipts: bool}  $facts
     * @return array<string, array{allowed: bool, reason: ?string}>
     */
    public static function forInvoice(User $user, ProjectInvoice $invoice, array $facts): array
    {
        $draft = $invoice->status === 'draft';
        $returned = InvoiceState::awaitingCorrection($invoice);
        $preparer = (int) $invoice->created_by === (int) $user->id;
        $balance = max(0, $facts['net_total'] - $facts['paid']);
        $credit = $invoice->isCreditNote();

        return [
            'edit' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS), 'You do not have permission to prepare invoices.'],
                [$draft, 'Only a draft invoice can be corrected.'],
                [! $invoice->checked_at, 'This invoice has already been checked. It must be returned for correction first.'],
                [$preparer, 'Only the preparer of this invoice can correct it.'],
            ]),
            'check' => self::rule([
                [! $credit, 'A credit note is issued by a second person, not checked.'],
                [$user->can(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK), 'You do not have permission to check invoices.'],
                [$draft, 'Only a draft invoice can be checked.'],
                [! $invoice->checked_at, 'This invoice has already been checked.'],
                [! $returned, 'This invoice was returned to its preparer for correction. It can be checked once they resubmit it.'],
                [! $preparer, 'You prepared this invoice, so someone else has to check it.'],
            ]),
            'return' => self::rule([
                [! $credit, 'A credit note cannot be returned for correction.'],
                [$user->can(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK), 'You do not have permission to review invoices.'],
                [$draft, 'Only a draft can be returned for correction.'],
                [! $returned, 'This invoice is already with its preparer for correction.'],
                [! $preparer, 'You prepared this invoice, so someone else has to return it.'],
            ]),
            'issue' => $credit
                ? self::rule([
                    [$user->can(Permissions::FINANCE_RECEIVABLES_REVERSE), 'You do not have permission to issue credit notes.'],
                    [$draft, 'Only a draft credit note can be issued.'],
                    [! $preparer, 'You raised this credit note, so someone else has to issue it.'],
                ])
                : self::rule([
                    [$user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS), 'You do not have permission to issue invoices.'],
                    [$draft, 'Only draft invoices can be issued.'],
                    [(bool) $invoice->checked_at, 'This invoice has not been checked yet. It must be reviewed before it can be issued.'],
                ]),
            'void' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_REVERSE), 'You do not have permission to void invoices.'],
                [$invoice->status !== 'void', 'This invoice has already been voided.'],
                [! $facts['allocations'], 'A receipt has been applied to this invoice, so it cannot be voided. Reverse the allocation first.'],
                [! $facts['credit_notes'], 'A credit note has been issued against this invoice, so it cannot be voided. Void the credit note first.'],
            ]),
            'credit_note' => self::rule([
                [! $credit, 'A credit note cannot itself be credited.'],
                [$user->can(Permissions::FINANCE_RECEIVABLES_REVERSE), 'You do not have permission to raise credit notes.'],
                [in_array($invoice->status, ['issued', 'paid'], true), 'Only an issued invoice can be credited.'],
            ]),
            'allocate' => self::rule([
                [! $credit, 'Receipts are applied to invoices, not credit notes.'],
                [$user->can(Permissions::FINANCE_RECEIVABLES_RECORD), 'You do not have permission to apply receipts.'],
                [$invoice->status === 'issued', 'Only an issued, unpaid invoice can receive a payment allocation.'],
                [$balance > 0, 'This invoice has no balance left to settle.'],
                [$facts['allocatable_receipts'], 'This project has no verified client money left to apply.'],
            ]),
        ];
    }

    /**
     * @param  array{allocations: bool}  $facts
     * @return array<string, array{allowed: bool, reason: ?string}>
     */
    public static function forReceipt(User $user, EnquiryPayment $payment, array $facts): array
    {
        $reversed = $payment->reversed_at || $payment->status === 'reversed';
        $recorder = (int) $payment->recorded_by === (int) $user->id;

        return [
            'verify' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_VERIFY), 'You do not have permission to verify receipts.'],
                [! $reversed, 'A reversed receipt cannot be verified.'],
                [$payment->status !== 'verified', 'This receipt has already been verified.'],
                [! $recorder || SelfApproval::allowedFor($user), 'This receipt was recorded by you, so someone else has to confirm it.'],
            ]),
            'correct' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_CORRECT), 'You do not have permission to correct receipts.'],
                [! $reversed, 'A reversed receipt cannot be edited. Record a new receipt instead.'],
                [! $payment->journal_entry_id, 'This receipt has already reached the general ledger. Reverse it and record a replacement.'],
                [! $facts['allocations'], 'This receipt is allocated to an invoice and cannot be edited. Its invoice allocation must be corrected first.'],
            ]),
            'reverse' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_REVERSE), 'You do not have permission to reverse receipts.'],
                [! $reversed, 'This receipt has already been reversed.'],
                [! $facts['allocations'], 'This receipt is allocated to an invoice and cannot be reversed. Its invoice allocation must be corrected first.'],
            ]),
        ];
    }

    /**
     * Project billing controls (Report 59): the commercial basis, the deposit
     * required before production, releasing production, and recording money.
     * Each restates EnquiryController::waiveQuoteRequirement /
     * updateReceivablesTerms / releaseProject (+ ReleaseFinanceGateAction) /
     * logPayment (+ FinanceService::logPayment).
     *
     * @param  array<string, mixed>  $progress  FinanceService::getPaymentProgress()
     * @return array<string, array{allowed: bool, reason: ?string}>
     */
    public static function forProjectBilling(User $user, array $progress): array
    {
        $thresholdMet = (bool) $progress['is_threshold_met'];

        return [
            'set_commercial_basis' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS), 'You do not have permission to set a project\'s billing basis.'],
                [! $progress['has_approved_quote'], 'This project already has an approved quote.'],
            ]),
            'change_deposit_terms' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS), 'You do not have permission to change a project\'s deposit terms.'],
            ]),
            'release' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_RELEASE), 'You do not have permission to release projects into production.'],
                [! $progress['finance_released'], 'Production has already been released.'],
                [$progress['has_approved_quote'] || $progress['quote_requirement_waived'], 'Finance release requires an approved quote or a formally recorded no-quote exception.'],
                [$thresholdMet || $user->can(Permissions::FINANCE_RECEIVABLES_OVERRIDE),
                    "The verified deposit is below the {$progress['threshold_percentage']}% target. Early release requires the receivables override permission."],
            ]),
            'record_receipt' => self::rule([
                [$user->can(Permissions::FINANCE_RECEIVABLES_RECORD), 'You do not have permission to record receipts.'],
                [(bool) $progress['can_record_payments'], $progress['quote_requirement_waived']
                    ? 'Enter the quote amount in Project Billing before recording payments.'
                    : 'A quote must be approved or formally waived before payments can be recorded.'],
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
