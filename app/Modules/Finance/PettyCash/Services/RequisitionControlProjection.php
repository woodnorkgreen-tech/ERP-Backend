<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Models\GovernanceAuditLog;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Support\PaymentMethods;
use App\Support\SelfApproval;

/**
 * The one read model of a requisition's control position: verification,
 * approval, and what has actually been paid to whom.
 *
 * Nothing here is stored. Paid and outstanding amounts are summed from the
 * active Payments and their line allocations every time, so a reversal shows
 * the moment it happens and the parent can never claim more than its children
 * paid. A payment made before receiver allocation existed is reported as such
 * and is never shared out between receivers by guesswork.
 */
final class RequisitionControlProjection
{
    public function forRequisition(PettyCashRequisition $r): array
    {
        $r->loadMissing(['items.payee', 'items.supplier', 'payee', 'requester', 'approver', 'responsibleVerifier',
            'verifiedBy', 'disbursements.paymentSource', 'disbursements.requisitionAllocations', 'disbursements.voidedBy', 'disbursements.creator']);
        $verification = app(RequisitionVerificationService::class);
        $current = $verification->isCurrent($r);

        $active = $r->disbursements->where('status', 'active');
        $paid = $active->reduce(fn (string $sum, Payment $p) => bcadd($sum, (string) $p->amount, 2), '0.00');
        $approved = $r->approved_at ? (string) $r->total_amount : '0.00';
        $legacyUnallocated = $active->contains(fn (Payment $p) => ! $p->requisition_child_reference);

        // Report 75R-B: every money figure comes from the one position calculation.
        $position = app(RequisitionPosition::class)->for($r);
        $receivers = $position['receivers'];
        $problem = collect($receivers)->pluck('receiver_problem')->filter()->first();
        $user = auth()->user();
        $canPay = (bool) ($user?->is_active && $user->can('create', Payment::class)
            && $r->approved_at && ! $r->closed_at && $current && in_array($r->status, ['approved', 'disbursed'], true)
            && ! $legacyUnallocated && ! $problem && ! $r->bill_id
            && ((int) $r->user_id !== (int) $user->id || SelfApproval::allowedFor($user))
            // Report 75R-C: the approver is never the payer.
            && ($r->approved_by === null || (int) $r->approved_by !== (int) $user->id));
        $approverIsViewer = $user && $r->approved_by !== null && (int) $r->approved_by === (int) $user->id
            && $user->can('create', Payment::class);
        foreach ($receivers as &$receiver) {
            $receiver['can_pay'] = $canPay && $receiver['outstanding'] !== null
                && bccomp($receiver['outstanding'], '0.00', 2) > 0;
        }
        unset($receiver);

        $closure = app(RequisitionClosureService::class);
        $blockers = $legacyUnallocated ? [] : $closure->blockers($r, $position);
        $receivers = $this->withActions($r, $receivers, $user);
        $payments = $this->payments($r, $receivers);
        $status = self::disbursementStatus(bcsub($approved, $position['released'], 2), $paid);
        $byReceiver = $position['mode'] === RequisitionPosition::BY_RECEIVER
            || (! $legacyUnallocated && bccomp($position['released'], '0.00', 2) > 0);
        $controlState = $this->controlState($r, $position, $payments, $blockers, $current);
        $canClose = (bool) ($byReceiver && ! $r->closed_at && $blockers === [] && $user?->is_active
            && $user->can('create', Payment::class) && (int) $r->user_id !== (int) $user->id);
        $mayAccount = collect($receivers)->contains(fn (array $receiver) => $receiver['actions']['account'] ?? false);

        return [
            'reference' => $r->requisition_number,
            'can_process_payment' => $canPay,
            'payment_block' => $canPay ? null : ($this->paymentBlock($r, $current, $legacyUnallocated, $problem)
                ?? ($approverIsViewer ? RequisitionDisbursementService::APPROVER_IS_PAYER : null)),
            'payment_options' => $canPay ? [
                'sources' => PaymentSource::query()->paymentCapable()->orderBy('name')->get(['id', 'name', 'type']),
                'expense_codes' => ExpenseCode::active()->orderBy('expense_type')->get(['id', 'expense_type']),
                'expense_code_fixed' => $r->requisitionType?->defaultExpenseCode?->only(['id', 'expense_type']),
                'methods' => PaymentMethods::values(),
            ] : null,
            'can_verify' => (bool) ($user?->is_active
                && (int) $user->id === (int) $r->responsible_verifier_id
                && $user->hasPermissionTo(RequisitionVerificationService::PERMISSION, 'web')
                && $r->status === 'pending' && $r->verification_status === 'pending_verification'),
            'verification' => [
                'status' => $r->verification_status === 'verified' && ! $current ? 're_verification_required' : ($r->verification_status ?? 'not_verified'),
                'current' => $current,
                'verifier' => $r->responsibleVerifier?->only(['id', 'name']),
                'verified_by' => $r->verifiedBy?->only(['id', 'name']),
                'verified_at' => $r->verified_at?->toIso8601String(), 'comment' => $r->verification_comment,
            ],
            'approval_status' => $r->approved_at ? 'approved' : ($r->status === 'rejected' ? 'rejected' : 'pending'),
            'requested' => (string) $r->total_amount, 'verified' => $current ? (string) $r->total_amount : null,
            'approved' => $approved, 'disbursed' => $paid, 'outstanding' => bcsub(bcsub($approved, $paid, 2), $position['released'], 2),
            'disbursement_status' => $status,
            'payment_mode' => $legacyUnallocated ? 'single_payment' : ($active->isNotEmpty() ? 'by_receiver' : 'not_paid'),
            // Report 75R-B: the lifecycle after payment, all derived.
            'confirmed_received' => $position['confirmed'], 'awaiting_confirmation' => $position['awaiting_confirmation'],
            'accounted' => $position['accepted'], 'returned' => $position['returned'], 'under_review' => $position['under_review'],
            'released_unused' => $position['released'], 'to_disburse' => $position['to_disburse'], 'to_account' => $position['to_account'],
            'control_state' => $controlState,
            // The one thing that happens next, who does it, and whether that is you.
            'next_step' => $next = $this->nextStep($r, $position, $receivers, $controlState, $current, $canPay, $canClose, $user),
            'steps' => $this->steps($next['stage'] ?? $next['key'], $r),
            'closure_blockers' => $blockers,
            // Report 75R-C: what a person is told when a claim exceeds the advance.
            'overspend_instruction' => RequisitionAccountabilityService::OVERSPEND_INSTRUCTION,
            'can_close' => $canClose,
            'closed' => $r->closed_at ? ['at' => $r->closed_at->toIso8601String(), 'by' => \App\Models\User::query()->whereKey($r->closed_by)->value('name')] : null,
            'advance_control' => $this->advanceControl($receivers),
            'accountability_options' => $mayAccount ? [
                'expense_codes' => ExpenseCode::active()->orderBy('expense_type')->get(['id', 'code', 'expense_type']),
                'expense_code_default' => $r->requisitionType?->defaultExpenseCode?->only(['id', 'expense_type']),
                'receipt_types' => ['etr', 'non_etr', 'none'],
            ] : null,
            'cancelled_unused' => $legacyUnallocated ? null : $position['released'],
            'closure_status' => $r->closed_at ? 'closed' : ($legacyUnallocated ? 'not_evaluated' : ($blockers === [] ? 'ready_to_close' : 'open')),
            'confirmation_status' => match (true) {
                $legacyUnallocated || $position['mode'] === RequisitionPosition::NOT_PAID => $r->received_at ? 'confirmed_parent_receipt' : (bccomp($paid, '0.00', 2) > 0 ? 'confirmation_pending' : 'not_disbursed'),
                bccomp($position['awaiting_confirmation'], '0.00', 2) === 0 => 'confirmed',
                bccomp($position['confirmed'], '0.00', 2) > 0 => 'partially_confirmed',
                default => 'confirmation_pending',
            },
            'accountability_status' => match (true) {
                $byReceiver && $r->closed_at !== null => 'accepted',
                $byReceiver && bccomp($paid, '0.00', 2) > 0 && bccomp($position['to_account'], '0.00', 2) === 0 => 'accounted_in_full',
                $byReceiver && bccomp($position['under_review'], '0.00', 2) > 0 => 'under_finance_review',
                $byReceiver && bccomp(bcadd($position['accepted'], $position['returned'], 2), '0.00', 2) > 0 => 'partially_accounted',
                $r->status === 'surrendered' && $r->surrender_reconciled_at !== null => 'accepted',
                $r->status === 'surrender_returned' => 'returned_for_correction',
                $r->status === 'surrender_pending' => 'under_finance_review',
                bccomp($paid, '0.00', 2) > 0 => 'pending',
                default => 'not_disbursed',
            },
            'receivers' => $receivers,
            // A collection, as Report 75R's callers read it.
            'payments' => collect($payments),
            'posting' => [
                'failed' => collect($payments)->where('posting.state', 'failed')->count(),
                'can_retry' => (bool) $user?->can('reviewRequisition', Payment::class),
            ],
            'story' => $this->story($r, $receivers, $payments, $approved, $paid, $status, $position),
            'audit' => GovernanceAuditLog::query()->with('user:id,name')->where('model_type', PettyCashRequisition::class)
                ->where('model_id', $r->id)->orderBy('id')->get()->map(fn ($event) => [
                    'event' => $event->gate_type, 'message' => $event->message, 'actor' => $event->user?->name,
                    'at' => $event->created_at?->toIso8601String(), 'context' => $event->context,
                ]),
            // What Report 75R listed here is now implemented for requisitions paid by receiver.
            'software_gaps' => [],
        ];
    }

    /**
     * What the signed-in person may do for each receiver. Every button on the
     * screen is one of these; the same rules are enforced again when it is used.
     */
    private function withActions(PettyCashRequisition $r, array $receivers, $user): array
    {
        $accountability = app(RequisitionAccountabilityService::class);
        $open = ! $r->closed_at && $r->status === 'disbursed';
        $positive = fn (?string $amount) => $amount !== null && bccomp($amount, '0.00', 2) > 0;
        $mayRelease = (bool) ($user?->is_active && $user->hasPermissionTo(\App\Constants\Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, 'web')
            && ! $r->closed_at && $r->approved_at && in_array($r->status, ['approved', 'disbursed'], true) && (int) $r->user_id !== (int) $user->id);
        $mayReverse = (bool) ($user?->can(\App\Constants\Permissions::FINANCE_JOURNALS_REVERSE) && (int) $r->user_id !== (int) $user?->id);
        $names = \App\Models\User::query()->whereIn('id', collect($receivers)->flatMap(fn ($receiver) => collect($receiver['surrenders'] ?? [])
            ->flatMap(fn ($s) => [$s['submitted_by_id'], $s['reconciled_by_id']]))->filter()->unique())->pluck('name', 'id');

        foreach ($receivers as &$receiver) {
            $identified = $receiver['receiver_type'] !== 'unresolved' && $receiver['paid'] !== null;
            $basis = $user && $identified ? $accountability->confirmationBasisFor($r, $receiver, $user) : null;
            $submittedOpen = collect($receiver['surrenders'] ?? [])->contains('status', 'submitted');
            $returnedOpen = collect($receiver['surrenders'] ?? [])->contains('status', 'returned');
            $receiver['actions'] = [
                'confirm_receipt' => $open && $basis !== null && $positive($receiver['awaiting_confirmation']),
                'confirm_basis' => $basis,
                'account' => $open && $identified && $user && ! $submittedOpen && $accountability->maySubmit($r, $receiver, $user)
                    && ($positive($receiver['available_to_account']) || $returnedOpen),
                'release_unused' => $mayRelease && $identified && $positive($receiver['to_disburse']),
            ];
            foreach ($receiver['surrenders'] as &$surrender) {
                $review = $user && $open && $surrender['status'] === 'submitted' && $user->is_active && $user->can('create', Payment::class)
                    && (int) $r->user_id !== (int) $user->id && $accountability->receiverUserId($receiver) !== (int) $user->id;
                $surrender['submitted_by'] = $names[$surrender['submitted_by_id']] ?? null;
                $surrender['reconciled_by'] = $names[$surrender['reconciled_by_id']] ?? null;
                $surrender['actions'] = [
                    'reconcile' => $review && bccomp($surrender['overspend'], '0.00', 2) === 0,
                    'return' => (bool) $review,
                    'reverse' => $surrender['status'] === 'reconciled' && $mayReverse,
                ];
            }
            unset($surrender);
        }
        unset($receiver);

        return $receivers;
    }

    /**
     * The one business state shown for the requisition: the earliest stage that
     * is not finished. `status` on the record remains the workflow stage older
     * screens read; this is what a person should be told.
     */
    private function controlState(PettyCashRequisition $r, array $position, array $payments, array $blockers, bool $verified): string
    {
        $zero = fn (string $amount) => bccomp($amount, '0.00', 2) === 0;
        $codes = array_column($blockers, 'code');

        return match (true) {
            $r->closed_at !== null => 'closed',
            $r->status === 'rejected' => 'rejected',
            $position['legacy_single_payment'] => match (true) {
                $r->status === 'surrendered' => 'closed',
                $r->status === 'surrender_pending' => 'accountability_review',
                $r->status === 'surrender_returned' => 'accountability_returned',
                $r->received_at !== null => 'awaiting_accountability',
                default => 'awaiting_receipt_confirmation',
            },
            ! $r->approved_at => $verified ? 'awaiting_approval' : 'awaiting_verification',
            (bool) array_intersect($codes, ['over_disbursed', 'posting_failed', 'overspend_unresolved', 'receiver_not_identified']) => 'exception',
            $zero($position['disbursed']) => $zero($position['released']) ? 'awaiting_disbursement' : 'ready_to_close',
            ! $zero($position['to_disburse']) && ! $zero($position['to_account']) => 'partially_disbursed',
            ! $zero($position['awaiting_confirmation']) => 'awaiting_receipt_confirmation',
            ! $zero($position['under_review']) => 'accountability_review',
            ! $zero($position['to_account']) => $zero(bcadd($position['accepted'], $position['returned'], 2)) ? 'awaiting_accountability' : 'partially_accounted',
            ! $zero($position['to_disburse']) => 'unused_balance_requires_release',
            $blockers === [] => 'ready_to_close',
            default => 'exception',
        };
    }

    /**
     * The next step in plain words: what, who, and whether the signed-in person
     * can do it now. One answer, so every screen shows the same single action.
     *
     * @return array{key: string, label: string, who: string, can_act: bool, hint: ?string}
     */
    private function nextStep(PettyCashRequisition $r, array $position, array $receivers, string $state, bool $verified, bool $canPay, bool $canClose, $user): array
    {
        $step = fn (string $key, string $label, string $who, bool $can, ?string $hint = null) => compact('key', 'label', 'who') + ['can_act' => $can, 'hint' => $hint];
        $names = fn (callable $test) => collect($receivers)->filter($test)->pluck('name')->implode(', ');
        $acts = fn (string $action) => collect($receivers)->contains(fn ($receiver) => $receiver['actions'][$action] ?? false);
        $positive = fn (?string $amount) => $amount !== null && bccomp($amount, '0.00', 2) > 0;

        if ($state === 'closed') {
            return $step('done', 'Closed', '', false);
        }
        if ($r->status === 'rejected') {
            return $step('done', 'Rejected', '', false);
        }
        if (! $r->approved_at && ! $verified) {
            $canVerify = (bool) ($user?->is_active && (int) $user->id === (int) $r->responsible_verifier_id
                && $user->hasPermissionTo(RequisitionVerificationService::PERMISSION, 'web')
                && $r->status === 'pending' && $r->verification_status === 'pending_verification');

            return $step('verify', 'Verify', $r->responsibleVerifier?->name ?? 'A verifier must be chosen', $canVerify,
                $r->verification_status === 'returned_for_correction' ? 'Sent back to the requester to correct.' : null);
        }
        if (! $r->approved_at) {
            $own = $user && (int) $r->user_id === (int) $user->id;
            $canApprove = (bool) ($user?->can('reviewRequisition', Payment::class) && $r->status === 'pending' && (! $own || SelfApproval::allowedFor($user)));

            return $step('approve', 'Approve', 'Finance approver', $canApprove, $own && ! $canApprove ? 'You raised this, so someone else approves it.' : null);
        }
        if ($position['legacy_single_payment']) {
            $mine = (bool) ($user && (int) $r->user_id === (int) $user->id);
            $requester = $r->requester?->name ?? 'Requester';

            return match ($state) {
                'awaiting_receipt_confirmation' => $step('confirm', 'Confirm receipt', $requester, $mine),
                'accountability_review' => $step('reconcile', 'Reconcile', 'Finance', (bool) $user?->can('create', Payment::class) && ! $mine),
                default => $step('account', 'Account for the money', $requester, $mine),
            };
        }

        // Everything still open, earliest first. Several can be open at once
        // (one receiver paid, another not), so a person is shown the one they
        // can do; the stage shown to everyone is always the earliest.
        $open = [];
        if ($positive($position['to_disburse']) && (in_array($state, ['awaiting_disbursement', 'partially_disbursed'], true) || ! $positive($position['disbursed']))) {
            $approverViewing = $user && $r->approved_by !== null && (int) $r->approved_by === (int) $user->id;
            $open[] = $step('pay', 'Pay', 'Finance (not the approver)', $canPay, $approverViewing ? 'You approved this, so a different Finance user pays it.' : null);
        }
        if ($positive($position['awaiting_confirmation'])) {
            $open[] = $step('confirm', 'Confirm receipt', $names(fn ($x) => $positive($x['awaiting_confirmation'] ?? null)), $acts('confirm_receipt'));
        }
        if ($positive($position['under_review'])) {
            $open[] = $step('reconcile', 'Reconcile', 'Finance', collect($receivers)->contains(fn ($x) => collect($x['surrenders'] ?? [])
                ->contains(fn ($s) => ($s['actions']['reconcile'] ?? false) || ($s['actions']['return'] ?? false))));
        }
        if ($positive($position['to_account'])) {
            $open[] = $step('account', 'Account for the money', $names(fn ($x) => $positive($x['to_account'] ?? null)), $acts('account'));
        }
        if ($open === [] && $positive($position['to_disburse'])) {
            $open[] = $step('release', 'Pay the balance or release it', 'Finance', $canPay || $acts('release_unused'));
        }
        if ($open === []) {
            $open[] = $step('close', 'Close', 'Finance', $canClose, $state === 'exception' ? 'Something must be fixed first. See what is blocking below.' : null);
        }

        return (collect($open)->firstWhere('can_act', true) ?? $open[0]) + ['stage' => $open[0]['key']];
    }

    /** The six stages, each done, current or still to come. */
    private function steps(string $current, PettyCashRequisition $r): array
    {
        $order = ['verify' => 'Verify', 'approve' => 'Approve', 'pay' => 'Pay', 'confirm' => 'Confirm', 'account' => 'Account', 'close' => 'Close'];
        $at = match ($current) { 'reconcile' => 'account', 'release' => 'pay', default => $current };
        $index = array_search($at, array_keys($order), true);
        $done = $current === 'done' && $r->status !== 'rejected';

        return collect(array_keys($order))->map(fn ($key, $i) => ['key' => $key, 'label' => $order[$key],
            'state' => $done || ($index !== false && $i < $index) ? 'done' : ($index !== false && $i === $index ? 'current' : 'todo')])->all();
    }

    /**
     * Where the advances on this requisition are held, and on whose say-so.
     * Existing behaviour is labelled as existing, never as approved.
     */
    private function advanceControl(array $receivers): array
    {
        return collect($receivers)->pluck('receiver_type')->unique()->reject(fn ($type) => $type === 'unresolved')->values()
            ->map(function (string $type) {
                $control = \App\Modules\Finance\Support\RequisitionAdvanceControl::for($type);

                return ['receiver_type' => $type, 'account_code' => $control['account_code'], 'account_name' => $control['account_name'],
                    'basis' => $control['basis'], 'setup_item' => \App\Modules\Finance\Support\RequisitionAdvanceControl::item($type)];
            })->all();
    }

    private function payments(PettyCashRequisition $r, array $receivers): array
    {
        $keyByLine = [];
        $purposeByLine = [];
        foreach ($receivers as $receiver) {
            foreach ($receiver['lines'] as $line) {
                $keyByLine[$line['id']] = $receiver['key'];
                $purposeByLine[$line['id']] = $line['purpose'];
            }
        }

        // Report 75R-B: per Payment, whether receipt is confirmed and how much is accounted for.
        $confirmations = $r->receiptConfirmations->whereNull('invalidated_at')->keyBy('payment_id');
        $confirmers = \App\Models\User::query()->whereIn('id', $r->receiptConfirmations->pluck('confirmed_by')->unique())->pluck('name', 'id');
        $accountedByPayment = [];
        $heldByPayment = [];
        foreach ($receivers as $receiver) {
            foreach ($receiver['lines'] as $line) {
                foreach ($line['slices'] ?? [] as $slice) {
                    $accountedByPayment[$slice['payment_id']] = bcadd($accountedByPayment[$slice['payment_id']] ?? '0.00', bcadd($slice['accepted'], $slice['returned'], 2), 2);
                    $heldByPayment[$slice['payment_id']] = bcadd($heldByPayment[$slice['payment_id']] ?? '0.00', bcadd(bcadd($slice['accepted'], $slice['returned'], 2), $slice['under_review'], 2), 2);
                }
            }
        }

        $instalment = [];
        $rows = [];
        foreach ($r->disbursements->sortBy('id') as $p) {
            $receiverKey = $keyByLine[$p->requisitionAllocations->first()?->requisition_item_id] ?? null;
            $number = null;
            if ($receiverKey) {
                $number = $instalment[$receiverKey] = ($instalment[$receiverKey] ?? 0) + 1;
            }
            $rows[] = [
                'id' => $p->id, 'parent_requisition_id' => $p->requisition_id, 'reference' => $p->payment_no,
                'child_reference' => $p->requisition_child_reference,
                'receiver_key' => $receiverKey, 'receiver' => $p->payee_name, 'receiver_type' => $p->payee_type,
                'amount' => (string) $p->amount, 'transaction_cost' => (string) ($p->transaction_cost ?? '0.00'),
                'status' => $p->status, 'instalment' => $number,
                'method' => $p->payment_method, 'source' => $p->paymentSource?->only(['id', 'name']),
                'external_reference' => $p->external_reference, 'date' => $p->date_disbursed?->toDateString(),
                'recorded_at' => $p->created_at?->toIso8601String(), 'recorded_by' => $p->creator?->name,
                'allocations' => $p->requisitionAllocations->map(fn ($a) => [
                    'item_id' => $a->requisition_item_id, 'purpose' => $purposeByLine[$a->requisition_item_id] ?? null,
                    'amount' => (string) $a->allocated_amount,
                ])->values(),
                'posting' => $this->posting($r, $p),
                'reversal' => $p->status === 'voided' ? [
                    'at' => $p->voided_at?->toIso8601String(), 'by' => $p->voidedBy?->name, 'reason' => $p->void_reason,
                ] : null,
                // Reversal is its own authority, and a funded surrender is unwound first.
                'can_reverse' => $p->status === 'active'
                    && (bool) auth()->user()?->can(\App\Constants\Permissions::FINANCE_PAYMENTS_REVERSE)
                    && ! in_array($r->status, ['surrender_pending', 'surrender_returned', 'surrendered'], true)
                    && bccomp($heldByPayment[$p->id] ?? '0.00', '0.00', 2) === 0,
                'receipt' => ($confirmation = $confirmations->get($p->id)) ? [
                    'confirmed_at' => $confirmation->confirmed_at?->toIso8601String(),
                    'confirmed_by' => $confirmers[$confirmation->confirmed_by] ?? null,
                    'basis' => $confirmation->basis, 'represented_name' => $confirmation->represented_name,
                    'evidence_reference' => $confirmation->evidence_reference, 'note' => $confirmation->note,
                ] : null,
                'accounted' => $p->status === 'active' ? ($accountedByPayment[$p->id] ?? '0.00') : null,
                // Kept for callers written against Report 75R.
                'advance_journal_entry_id' => $p->advance_journal_entry_id, 'posting_error' => $p->advance_gl_posting_error,
            ];
        }

        // A receiver paid more than once has instalments; a single payment is just a
        // payment. A payment that leaves the receiver with a balance is a part payment.
        $counts = array_count_values(array_filter(array_column($rows, 'receiver_key')));
        // What a receiver can still be paid: approved, less anything released as unused.
        $approved = [];
        foreach ($receivers as $receiver) {
            $approved[$receiver['key']] = bcsub($receiver['approved'], $receiver['released'] ?? '0.00', 2);
        }
        $running = [];
        foreach ($rows as &$row) {
            $key = $row['receiver_key'];
            $row['is_instalment'] = $key !== null && ($counts[$key] ?? 0) > 1;
            $row['part_payment'] = false;
            if ($key !== null && $row['status'] === 'active') {
                $running[$key] = bcadd($running[$key] ?? '0.00', $row['amount'], 2);
                $row['part_payment'] = bccomp($running[$key], $approved[$key] ?? '0.00', 2) < 0;
            }
        }
        unset($row);

        return $rows;
    }

    /** Ledger state of one cash movement. A pre-allocation payment posts through its parent. */
    private function posting(PettyCashRequisition $r, Payment $p): array
    {
        $allocated = (bool) $p->requisition_child_reference;
        $entryId = $allocated ? $p->advance_journal_entry_id : $r->advance_journal_entry_id;
        $error = $allocated ? $p->advance_gl_posting_error : $r->advance_gl_posting_error;
        $failedAt = $allocated ? $p->advance_gl_posting_failed_at : $r->advance_gl_posting_failed_at;

        return [
            'state' => match (true) {
                $p->status !== 'active' => $entryId ? 'reversed' : 'not_posted',
                $failedAt !== null => 'failed',
                $entryId !== null => 'posted',
                default => 'pending',
            },
            'journal_entry_id' => $entryId,
            'error' => $p->status === 'active' ? $error : null,
        ];
    }

    /** The requisition's history as a person would tell it, oldest first. */
    private function story(PettyCashRequisition $r, array $receivers, array $payments, string $approved, string $paid, string $status, array $position): array
    {
        $kes = fn (string $amount) => 'KES '.number_format((float) $amount, 2);
        $story = [['kind' => 'created', 'title' => 'Created', 'detail' => $kes((string) $r->total_amount).' requested',
            'actor' => $r->requester?->name ?? 'Public requester', 'at' => $r->created_at?->toIso8601String()]];
        if ($r->verified_at) {
            $story[] = ['kind' => 'verified', 'title' => 'Verified', 'detail' => null,
                'actor' => $r->verifiedBy?->name, 'at' => $r->verified_at->toIso8601String()];
        }
        if ($r->approved_at) {
            $story[] = ['kind' => 'approved', 'title' => 'Approved', 'detail' => $kes($approved).' approved',
                'actor' => $r->approver?->name, 'at' => $r->approved_at->toIso8601String()];
        }

        $names = array_column($receivers, 'name', 'key');
        $runningByReceiver = [];
        $events = [];
        foreach ($payments as $p) {
            $label = $p['child_reference'] ? substr($p['child_reference'], strrpos($p['child_reference'], '-') + 1) : 'Payment';
            $events[] = [$p['recorded_at'], 0, $p, $label];
            if ($p['reversal']) {
                $events[] = [$p['reversal']['at'], 1, $p, $label];
            }
        }
        usort($events, fn ($a, $b) => [$a[0], $a[1], $a[2]['id']] <=> [$b[0], $b[1], $b[2]['id']]);

        $approvedByReceiver = array_column($receivers, 'approved', 'key');
        foreach ($events as [$at, $isReversal, $p, $label]) {
            $key = $p['receiver_key'];
            $name = $names[$key] ?? $p['receiver'];
            if ($isReversal) {
                if ($key) {
                    $runningByReceiver[$key] = bcsub($runningByReceiver[$key] ?? '0.00', $p['amount'], 2);
                }
                $story[] = ['kind' => 'reversed', 'title' => "{$label} reversed", 'reference' => $p['reference'],
                    'detail' => "{$name} — {$kes($p['amount'])} reversed: {$p['reversal']['reason']}",
                    'actor' => $p['reversal']['by'], 'at' => $at];

                continue;
            }
            $outstanding = null;
            if ($key) {
                $runningByReceiver[$key] = bcadd($runningByReceiver[$key] ?? '0.00', $p['amount'], 2);
                $outstanding = bcsub($approvedByReceiver[$key] ?? '0.00', $runningByReceiver[$key], 2);
            }
            $part = $outstanding !== null && bccomp($outstanding, '0.00', 2) > 0;
            $story[] = ['kind' => 'paid', 'title' => $label, 'reference' => $p['reference'],
                'detail' => "{$name} — {$kes($p['amount'])} ".($part ? 'part payment' : 'paid')
                    .($part ? ". {$name} outstanding: {$kes($outstanding)}" : ''),
                'actor' => $p['recorded_by'], 'at' => $at];
        }

        // Report 75R-B: what happened after payment, from the records themselves —
        // confirmations, surrenders, releases and closure — in the order it happened.
        $fixed = array_slice($story, 0, count(array_filter([true, $r->verified_at, $r->approved_at])));
        $timed = array_slice($story, count($fixed));
        $users = \App\Models\User::query()->whereIn('id', collect([$r->closed_by])
            ->merge($r->receiptConfirmations->pluck('confirmed_by'))->merge($r->balanceReleases->pluck('released_by'))
            ->merge($r->receiverSurrenders->flatMap(fn ($x) => [$x->submitted_by, $x->reconciled_by, $x->returned_by, $x->reversed_by]))
            ->filter()->unique())->pluck('name', 'id');
        $receiverName = fn (string $type, string $identity) => $names[$type.':'.$identity] ?? 'Receiver';

        foreach ($r->receiptConfirmations->groupBy(fn ($c) => $c->receiver_type.':'.$c->receiver_identity.'|'.$c->confirmed_at?->toIso8601String()) as $group) {
            $first = $group->first();
            $amount = $group->reduce(fn (string $sum, $c) => bcadd($sum, (string) $c->amount, 2), '0.00');
            $name = $receiverName($first->receiver_type, $first->receiver_identity);
            $timed[] = ['kind' => 'confirmed', 'title' => 'Receipt confirmed',
                'detail' => "{$name} confirmed {$kes($amount)}".($first->basis === 'on_behalf' ? ' (confirmed on their behalf)' : '')
                    .($group->contains(fn ($c) => $c->invalidated_at) ? ' — no longer stands: the payment was reversed' : ''),
                'actor' => $users[$first->confirmed_by] ?? null, 'at' => $first->confirmed_at?->toIso8601String()];
        }
        foreach ($r->receiverSurrenders as $surrender) {
            $name = $surrender->receiver_name;
            $ref = substr($surrender->reference, strrpos($surrender->reference, '-') + 1);
            $timed[] = ['kind' => 'accountability_submitted', 'title' => "{$ref} submitted", 'reference' => $surrender->reference,
                'detail' => "{$name} accounted for {$kes((string) $surrender->spent_amount)} spent"
                    .(bccomp((string) $surrender->returned_amount, '0.00', 2) > 0 ? " and {$kes((string) $surrender->returned_amount)} returned" : '')
                    .(bccomp((string) $surrender->overspend_amount, '0.00', 2) > 0 ? " — overspend of {$kes((string) $surrender->overspend_amount)} requires resolution" : ''),
                'actor' => $users[$surrender->submitted_by] ?? null, 'at' => $surrender->submitted_at?->toIso8601String()];
            if ($surrender->returned_at) {
                $timed[] = ['kind' => 'accountability_returned', 'title' => "{$ref} returned for correction", 'reference' => $surrender->reference,
                    'detail' => "{$name} — {$surrender->return_reason}", 'actor' => $users[$surrender->returned_by] ?? null, 'at' => $surrender->returned_at->toIso8601String()];
            }
            if ($surrender->reconciled_at) {
                $timed[] = ['kind' => 'accountability_reconciled', 'title' => "{$ref} reconciled", 'reference' => $surrender->reference,
                    'detail' => "{$name} accounted {$kes((string) $surrender->spent_amount)}"
                        .(bccomp((string) $surrender->returned_amount, '0.00', 2) > 0 ? "; returned {$kes((string) $surrender->returned_amount)}" : ''),
                    'actor' => $users[$surrender->reconciled_by] ?? null, 'at' => $surrender->reconciled_at->toIso8601String()];
            }
            if ($surrender->reversed_at) {
                $timed[] = ['kind' => 'accountability_reversed', 'title' => "{$ref} reversed", 'reference' => $surrender->reference,
                    'detail' => "{$name} — {$surrender->reversal_reason}", 'actor' => $users[$surrender->reversed_by] ?? null, 'at' => $surrender->reversed_at->toIso8601String()];
            }
        }
        foreach ($r->balanceReleases->groupBy('request_key') as $group) {
            $first = $group->first();
            $amount = $group->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->amount, 2), '0.00');
            $timed[] = ['kind' => 'released', 'title' => 'Unused balance released',
                'detail' => $receiverName($first->receiver_type, $first->receiver_identity)." — {$kes($amount)} will not be paid: {$first->reason}",
                'actor' => $users[$first->released_by] ?? null, 'at' => $first->released_at?->toIso8601String()];
        }
        if ($r->closed_at) {
            $timed[] = ['kind' => 'closed', 'title' => 'Closed', 'detail' => 'Reconciled in full', 'actor' => $users[$r->closed_by] ?? null,
                'at' => $r->closed_at->toIso8601String()];
        }
        // Stable: entries that share a moment keep the order they were built in.
        $order = array_keys($timed);
        usort($order, fn ($x, $y) => [$timed[$x]['at'], $x] <=> [$timed[$y]['at'], $y]);
        $story = array_merge($fixed, array_map(fn ($index) => $timed[$index], $order));

        if ($r->approved_at) {
            $stateText = [
                'awaiting_payment' => 'awaiting payment', 'released' => 'released, nothing paid',
                'awaiting_receipt_confirmation' => 'awaiting receipt confirmation',
                'overspend_requires_resolution' => 'overspend requires resolution',
                'accountability_review' => 'surrender with Finance for review',
                'accountability_returned' => 'surrender returned for correction',
                'awaiting_accountability' => 'still to account', 'partially_accounted' => 'still to account',
                'balance_to_pay_or_release' => 'balance to pay or release', 'complete' => 'complete',
                'receiver_not_identified' => 'receiver not identified',
            ];
            foreach ($receivers as $receiver) {
                if ($receiver['paid'] === null) {
                    continue;
                }
                $state = $receiver['accountability_state'];
                $amount = match ($state) {
                    'awaiting_payment', 'balance_to_pay_or_release' => $receiver['to_disburse'],
                    'awaiting_receipt_confirmation' => $receiver['awaiting_confirmation'],
                    'overspend_requires_resolution' => $receiver['overspend'],
                    'accountability_review' => $receiver['under_review'],
                    'awaiting_accountability', 'partially_accounted', 'accountability_returned' => $receiver['to_account'],
                    default => null,
                };
                $story[] = ['kind' => $state === 'awaiting_payment' ? 'awaiting' : 'receiver_position',
                    'title' => $state === 'awaiting_payment' ? 'Awaiting payment' : $receiver['name'],
                    'detail' => $state === 'awaiting_payment' ? "{$receiver['name']} — {$kes($receiver['outstanding'])}"
                        : ucfirst($stateText[$state] ?? str_replace('_', ' ', $state)).($amount !== null ? ' — '.$kes($amount) : ''),
                    'actor' => null, 'at' => null];
            }
            $paidOnly = $position['mode'] !== RequisitionPosition::BY_RECEIVER || bccomp(bcadd(bcadd($position['confirmed'], $position['released'], 2), $position['under_review'], 2), '0.00', 2) === 0;
            $story[] = ['kind' => 'overall', 'title' => 'Overall',
                'detail' => $paidOnly
                    ? $kes($paid).' / '.number_format((float) $approved, 2).' '.strtolower(str_replace('_', ' ', $status))
                    : implode('; ', array_filter([
                        $kes($approved).' approved', $kes($paid).' disbursed',
                        bccomp($position['released'], '0.00', 2) > 0 ? $kes($position['released']).' released' : null,
                        $kes($position['accepted']).' accounted', $kes($position['returned']).' returned',
                        $kes($position['to_account']).' awaiting accountability',
                    ])),
                'actor' => null, 'at' => null];
        }

        return $story;
    }

    private function paymentBlock(PettyCashRequisition $r, bool $current, bool $legacyUnallocated, ?string $problem): ?string
    {
        return match (true) {
            ! $r->approved_at => 'Awaiting Finance approval.',
            ! $current => 'The current details must be verified again before payment.',
            (bool) $r->bill_id => 'Paid through supplier settlement.',
            $legacyUnallocated => 'Already paid as one payment that is not allocated to receivers.',
            $problem !== null => $problem,
            ! in_array($r->status, ['approved', 'disbursed'], true) => 'Receipt or accountability has started; no further payment can be added here.',
            default => null,
        };
    }

    public static function disbursementStatus(string $approved, string $paid): string
    {
        if (bccomp($paid, $approved, 2) > 0) {
            return 'over_disbursement_exception';
        }
        if (bccomp($paid, '0.00', 2) === 0) {
            return 'not_disbursed';
        }

        return bccomp($paid, $approved, 2) < 0 ? 'partially_disbursed' : 'fully_disbursed';
    }
}
