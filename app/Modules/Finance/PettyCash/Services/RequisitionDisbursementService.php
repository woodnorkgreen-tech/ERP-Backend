<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\RequisitionPaymentAllocation;
use App\Modules\Finance\Services\PaymentSettlementService;
use App\Modules\Finance\Support\DocumentNumber;
use App\Support\SelfApproval;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 75R-A: pays one receiver of an approved requisition, in full or in part.
 *
 * One call is one real transfer: one Payment, for one receiver, bound to the
 * parent requisition, with one allocation row per requisition line it funds.
 * A requisition is paid by as many of these as it has receivers and instalments.
 *
 * Everything that decides whether money may leave is recalculated here, under a
 * lock on the parent requisition — never taken from the screen. The parent lock
 * is the serialisation point for payment, child-reference numbering and
 * reversal, so two people paying the same balance at the same moment are
 * handled one after the other and the second is refused.
 *
 * Cash movement is delegated to {@see PaymentSettlementService} and the advance
 * journal to {@see PettyCashAdvancePoster}; nothing here posts to the ledger or
 * to project cost.
 */
final class RequisitionDisbursementService
{
    public function __construct(
        private readonly PaymentSettlementService $settlement,
        private readonly RequisitionReceiverIdentity $identities,
        private readonly RequisitionVerificationService $verification,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  item_ids, amount, idempotency_key, payment_source_id,
     *                                      payment_method, date_disbursed, expense_code_id,
     *                                      and optionally transaction_cost, external_reference
     */
    public function pay(int $requisitionId, User $actor, array $data): Payment
    {
        // Payment authority is its own permission. Creating, verifying or
        // approving the requisition confers none of it.
        if (! $actor->is_active || ! $actor->can('create', Payment::class)) {
            throw new AuthorizationException('You are not authorized to pay requisitions.');
        }

        $instructions = $this->normalise($data);
        if ($instructions['idempotency_key'] === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'A request key is required to pay safely.']);
        }
        $fingerprint = hash('sha256', json_encode([$requisitionId, $actor->id, $instructions], JSON_THROW_ON_ERROR));

        [$payment, $created] = DB::transaction(
            fn () => $this->settle($requisitionId, $actor, $instructions, $fingerprint),
            3,
        );

        // The cash movement has committed. The journal is attempted afterwards
        // and its outcome is recorded on this Payment, so a posting failure can
        // never undo or hide money that has already left. A replay posts nothing.
        if ($created) {
            app(PettyCashAdvancePoster::class)->attemptPayment($payment);

            // Report 75R-B: paying does not touch the project commitment. Money out
            // as an advance is still a promise, not a cost; the commitment follows
            // accountability (see PettyCashCostProducer::syncReceiverCommitment()).
        }

        return $payment->fresh(['requisitionAllocations', 'paymentSource']);
    }

    /** @return array{0: Payment, 1: bool, 2: bool} the Payment, whether this call created it, and whether it completed the requisition */
    private function settle(int $requisitionId, User $actor, array $data, string $fingerprint): array
    {
        $requisition = PettyCashRequisition::query()->lockForUpdate()->findOrFail($requisitionId);

        // A replay of a request that already succeeded returns that Payment.
        // The same key carrying different instructions is refused, not obeyed.
        $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            if ((int) $existing->requisition_id !== $requisition->id
                || ! hash_equals((string) $existing->requisition_request_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This request key was already used for different payment instructions.',
                ]);
            }

            return [$existing, false, false];
        }

        $this->assertPayable($requisition, $actor);

        $lines = $requisition->items()->orderBy('id')->lockForUpdate()->get();
        $selected = $lines->whereIn('id', $data['item_ids'])->values();
        if ($selected->isEmpty() || $selected->count() !== count($data['item_ids'])) {
            throw ValidationException::withMessages(['item_ids' => 'Select lines that belong to this requisition.']);
        }

        // Every line must resolve, not only the selected ones: an approved total
        // that contains an unidentified receiver is not ready to be paid in part.
        $identities = $this->identities->forRequisition($requisition, $lines);
        $receiver = $identities[$selected->first()->id];
        foreach ($selected as $line) {
            if ($identities[$line->id]['key'] !== $receiver['key']) {
                throw ValidationException::withMessages([
                    'item_ids' => 'One Payment pays one receiver. Pay each receiver separately.',
                ]);
            }
        }

        $lineTotal = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->amount, 2), '0.00');
        if (bccomp($lineTotal, (string) $requisition->total_amount, 2) !== 0) {
            throw ValidationException::withMessages([
                'items' => 'The requisition lines no longer add up to the approved total.',
            ]);
        }

        $payments = $requisition->disbursements()->lockForUpdate()->get();
        $active = $payments->where('status', 'active');
        if ($active->contains(fn (Payment $payment) => ! $payment->requisition_child_reference)) {
            // A whole-requisition payment made before receiver allocation existed
            // says nothing about who it was for. It is never apportioned by guess.
            throw ValidationException::withMessages([
                'requisition_id' => 'This requisition already carries a payment that is not allocated to a receiver. It cannot also be paid by receiver.',
            ]);
        }

        $allocations = RequisitionPaymentAllocation::query()
            ->where('requisition_id', $requisition->id)
            ->whereIn('payment_id', $active->pluck('id'))
            ->lockForUpdate()
            ->get();

        $released = [];
        foreach ($requisition->balanceReleases()->lockForUpdate()->get() as $release) {
            $released[$release->requisition_item_id] = bcadd($released[$release->requisition_item_id] ?? '0.00', (string) $release->amount, 2);
        }

        $remaining = [];
        $available = '0.00';
        foreach ($selected as $line) {
            $paid = $allocations->where('requisition_item_id', $line->id)
                ->reduce(fn (string $sum, $allocation) => bcadd($sum, (string) $allocation->allocated_amount, 2), '0.00');
            // Report 75R-B: approved money already released as unused is no longer payable.
            $remaining[$line->id] = bcsub(bcsub((string) $line->amount, $paid, 2), $released[$line->id] ?? '0.00', 2);
            if (bccomp($remaining[$line->id], '0.00', 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'A selected line is already paid above its approved amount. Finance must resolve that exception first.',
                ]);
            }
            $available = bcadd($available, $remaining[$line->id], 2);
        }

        $parentPaid = $active->reduce(fn (string $sum, Payment $payment) => bcadd($sum, (string) $payment->amount, 2), '0.00');
        $amount = $data['amount'];
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount to pay.']);
        }
        if (bccomp($amount, $available, 2) > 0
            || bccomp(bcadd($parentPaid, $amount, 2), (string) $requisition->total_amount, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => "KES {$amount} is more than the KES {$available} still outstanding for {$receiver['name']}.",
            ]);
        }

        $code = $this->expenseCode($requisition, $data);
        $sequence = (int) $requisition->disbursement_sequence + 1;
        $childReference = $requisition->requisition_number.'-D'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
        $hasProject = (bool) ($requisition->project_id || $requisition->enquiry_id);

        $payment = $this->settlement->settle([
            'payment_no' => DocumentNumber::next(DocumentNumber::PAYMENT),
            'requisition_child_reference' => $childReference,
            'requisition_request_fingerprint' => $fingerprint,
            'idempotency_key' => $data['idempotency_key'],
            'requisition_id' => $requisition->id,
            'source_document_type' => PettyCashRequisition::class,
            'source_document_id' => $requisition->id,
            'payee_type' => $receiver['type'],
            'payee_id' => $receiver['id'],
            'payee_name' => $receiver['name'],
            'amount' => $amount,
            'transaction_cost' => $data['transaction_cost'],
            'payment_source_id' => $data['payment_source_id'],
            'payment_method' => $data['payment_method'],
            'date_disbursed' => $data['date_disbursed'],
            'external_reference' => $data['external_reference'],
            'expense_code_id' => $code->id,
            'account' => $code->expense_type,
            'classification' => $hasProject ? 'operations' : 'admin',
            'description' => $requisition->purpose,
            'project_id' => $requisition->project_id,
            'project_enquiry_id' => $requisition->enquiry_id,
            'project_name' => $requisition->project_name,
            'created_by' => $actor->id,
            'receipt_type' => 'none',
            'tax_amount' => '0.00',
        ]);

        // The amount fills the receiver's lines in line order, so each purpose
        // keeps its own share of the transfer.
        $left = $amount;
        $funded = [];
        foreach ($selected as $line) {
            $slice = bccomp($left, $remaining[$line->id], 2) < 0 ? $left : $remaining[$line->id];
            if (bccomp($slice, '0.00', 2) <= 0) {
                continue;
            }
            RequisitionPaymentAllocation::query()->create([
                'requisition_id' => $requisition->id,
                'requisition_item_id' => $line->id,
                'payment_id' => $payment->id,
                'receiver_type' => $receiver['type'],
                'receiver_identity' => $receiver['identity'],
                'allocated_amount' => $slice,
                'created_by' => $actor->id,
            ]);
            $funded[] = ['item_id' => $line->id, 'purpose' => $line->description, 'amount' => $slice];
            $left = bcsub($left, $slice, 2);
        }

        // `status` stays the workflow stage older screens read. How much has
        // actually been paid is always derived from the active Payments.
        $requisition->forceFill([
            'disbursement_sequence' => $sequence,
            'status' => 'disbursed',
            'surrender_due_at' => $requisition->surrender_due_at ?: $this->surrenderDueAt($data['date_disbursed']),
        ])->save();

        $receiverLines = $lines->filter(fn ($line) => $identities[$line->id]['key'] === $receiver['key'])->pluck('id');
        $receiverReleased = $receiverLines->reduce(fn (string $sum, $id) => bcadd($sum, $released[$id] ?? '0.00', 2), '0.00');
        $receiverOutstanding = bcsub(bcsub($this->identities->approvedFor($lines, $identities, $receiver['key']),
            bcadd($this->paidTo($allocations, $lines, $identities, $receiver['key']), $amount, 2), 2), $receiverReleased, 2);

        GovernanceAuditLog::query()->create([
            'project_enquiry_id' => $requisition->enquiry_id,
            'user_id' => $actor->id,
            'gate_type' => 'requisition_receiver_payment',
            'action_status' => 'recorded',
            'model_type' => PettyCashRequisition::class,
            'model_id' => $requisition->id,
            'message' => "{$childReference}: {$receiver['name']} paid KES {$amount} ({$payment->payment_no})",
            'context' => [
                'payment_id' => $payment->id,
                'payment_no' => $payment->payment_no,
                'child_reference' => $childReference,
                'receiver' => ['type' => $receiver['type'], 'id' => $receiver['id'], 'name' => $receiver['name']],
                'amount' => $amount,
                'lines' => $funded,
                'receiver_outstanding_after' => $receiverOutstanding,
                'part_payment' => bccomp($receiverOutstanding, '0.00', 2) > 0,
            ],
        ]);

        return [$payment, true, bccomp(bcadd($parentPaid, $amount, 2), (string) $requisition->total_amount, 2) === 0];
    }

    private function assertPayable(PettyCashRequisition $requisition, User $actor): void
    {
        if (! $requisition->approved_at || $requisition->closed_at || ! in_array($requisition->status, ['approved', 'disbursed'], true)) {
            throw ValidationException::withMessages([
                'requisition_id' => 'Only an approved requisition that has not yet been received or accounted for can be paid.',
            ]);
        }
        if ((int) $requisition->user_id === (int) $actor->id && ! SelfApproval::allowedFor($actor)) {
            throw new AuthorizationException('You raised this requisition, so someone else has to pay it out.');
        }
        self::assertNotApprover($requisition, $actor);
        $this->verification->assertCurrent($requisition);
        if ($requisition->bill_id) {
            throw ValidationException::withMessages([
                'requisition_id' => 'This requisition settles a supplier bill and is paid through supplier settlement.',
            ]);
        }
    }

    public const APPROVER_IS_PAYER = 'This requisition must be paid by a different authorised Finance user from the person who approved it.';

    /**
     * WNG's decision (Report 75R-C): the approver must not be the payer. It holds
     * whatever permissions the person has, and there is no override. The payer
     * may go on to reconcile and close; those are not separated from paying.
     */
    public static function assertNotApprover(PettyCashRequisition $requisition, User $actor): void
    {
        if ($requisition->approved_by !== null && (int) $requisition->approved_by === (int) $actor->id) {
            throw new AuthorizationException(self::APPROVER_IS_PAYER);
        }
    }

    private function expenseCode(PettyCashRequisition $requisition, array $data): ExpenseCode
    {
        $code = $requisition->requisitionType?->defaultExpenseCode
            ?: ExpenseCode::active()->find($data['expense_code_id']);
        if (! $code?->is_active) {
            throw ValidationException::withMessages(['expense_code_id' => 'Select an active expense type.']);
        }

        $hasProject = (bool) ($requisition->project_id || $requisition->enquiry_id);
        if (($code->requiresJobId() && ! $hasProject) || ($code->forbidsJobId() && $hasProject)) {
            throw ValidationException::withMessages([
                'expense_code_id' => $hasProject
                    ? 'This expense type is for office spend, but the requisition is for a project.'
                    : 'This expense type needs a project, and the requisition has none.',
            ]);
        }

        return $code;
    }

    /** What this receiver had been paid, by active Payments, before this one. */
    private function paidTo($allocations, $lines, array $identities, string $receiverKey): string
    {
        $lineIds = $lines->filter(fn ($line) => $identities[$line->id]['key'] === $receiverKey)->pluck('id');

        return $allocations->whereIn('requisition_item_id', $lineIds)
            ->reduce(fn (string $sum, $allocation) => bcadd($sum, (string) $allocation->allocated_amount, 2), '0.00');
    }

    private function surrenderDueAt(string $paidOn): ?string
    {
        $days = FinanceSetting::approvedValue('petty_cash_surrender_due_days');

        return is_numeric($days) ? Carbon::parse($paidOn)->addDays((int) $days)->toDateString() : null;
    }

    /** One canonical form, so a retry of the same instructions fingerprints identically. */
    private function normalise(array $data): array
    {
        $itemIds = array_values(array_unique(array_map('intval', (array) ($data['item_ids'] ?? []))));
        sort($itemIds);

        return [
            'item_ids' => $itemIds,
            'amount' => bcadd((string) ($data['amount'] ?? '0'), '0', 2),
            'transaction_cost' => bcadd((string) ($data['transaction_cost'] ?? '0'), '0', 2),
            'idempotency_key' => (string) ($data['idempotency_key'] ?? ''),
            'payment_source_id' => (int) ($data['payment_source_id'] ?? 0),
            'payment_method' => (string) ($data['payment_method'] ?? ''),
            'date_disbursed' => (string) ($data['date_disbursed'] ?? ''),
            'expense_code_id' => isset($data['expense_code_id']) ? (int) $data['expense_code_id'] : null,
            'external_reference' => filled($data['external_reference'] ?? null) ? (string) $data['external_reference'] : null,
        ];
    }
}
