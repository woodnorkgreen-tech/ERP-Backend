<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrender;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderAllocation;
use App\Modules\Finance\PettyCash\Models\RequisitionReceiptConfirmation;
use App\Modules\Finance\Services\CashMovementService;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\HR\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Report 75R-B: what a receiver does with money received under a requisition —
 * confirm it arrived, account for it, and have Finance reconcile that account.
 *
 * This is the existing surrender, made to understand receivers and Payments. It
 * writes the same surrender items, checks receipts with the same duplicate
 * rule, raises project cost through the same collector call and posts through
 * the same journal funnel. What is new is the unit: a surrender now belongs to
 * one receiver, may be one of several stages, and clears named slices of named
 * Payments, so it is always known which advance — and which paying account —
 * an accepted amount or a return relates to.
 *
 * Every write takes the parent requisition's row lock first, the same lock
 * payment and payment reversal take, so none of them can interleave.
 */
final class RequisitionAccountabilityService
{
    public function __construct(
        private readonly RequisitionPosition $position,
        private readonly SurrenderReceiptGuard $receipts,
        private readonly SurrenderCostPoster $costs,
        private readonly JournalPostingService $journals,
        private readonly CashMovementService $cash,
        private readonly PettyCashCostProducer $commitments,
    ) {
    }

    /**
     * WNG's decision (Report 75R-C): overspend is never reimbursed automatically.
     * More money is a new request, approved and paid like any other.
     */
    public const OVERSPEND_INSTRUCTION = 'Additional expenditure above the amount advanced requires approval before Finance can disburse the additional amount.';

    // ── Receipt confirmation ─────────────────────────────────────────────────

    /**
     * Confirm that one receiver's Payments reached them.
     *
     * Confirmation is per Payment, so it can never exceed what was actually
     * paid, and a receiver paid in instalments confirms each as it arrives.
     * Other receivers are not waited for.
     *
     * @param  array<string, mixed>  $data  receiver_key, and optionally payment_ids, evidence_reference, note
     * @return list<RequisitionReceiptConfirmation>
     */
    public function confirmReceipt(int $requisitionId, User $actor, array $data): array
    {
        return DB::transaction(function () use ($requisitionId, $actor, $data) {
            $requisition = $this->lockOpen($requisitionId);
            $receiver = $this->position->receiver($requisition, (string) ($data['receiver_key'] ?? ''));
            $basis = $this->confirmationBasis($requisition, $receiver, $actor);

            // Someone confirming for a receiver who cannot log in states what they rely on.
            $evidence = trim((string) ($data['evidence_reference'] ?? ''));
            $note = trim((string) ($data['note'] ?? ''));
            if ($basis === RequisitionReceiptConfirmation::ON_BEHALF && $evidence === '' && mb_strlen($note) < 10) {
                throw ValidationException::withMessages([
                    'evidence_reference' => "You are confirming on behalf of {$receiver['name']}. Give the evidence you rely on: a reference (for example the M-Pesa or bank confirmation code), or a note of at least 10 characters.",
                ]);
            }

            $payable = $this->receiverPayments($requisition, $receiver['key'])->keyBy('id');
            $confirmed = $requisition->receiptConfirmations()->whereNull('invalidated_at')->pluck('payment_id')->flip();
            $wanted = filled($data['payment_ids'] ?? null)
                ? collect((array) $data['payment_ids'])->map(fn ($id) => (int) $id)->unique()->values()
                : $payable->keys()->reject(fn ($id) => isset($confirmed[$id]))->values();

            if ($wanted->isEmpty()) {
                throw ValidationException::withMessages(['payment_ids' => "{$receiver['name']} has no payment awaiting receipt confirmation."]);
            }

            $created = [];
            foreach ($wanted as $paymentId) {
                $payment = $payable->get($paymentId);
                if (! $payment) {
                    throw ValidationException::withMessages(['payment_ids' => "That payment is not an active payment to {$receiver['name']}."]);
                }
                if (isset($confirmed[$paymentId])) {
                    throw ValidationException::withMessages(['payment_ids' => "{$payment->requisition_child_reference} is already confirmed as received."]);
                }
                $created[] = RequisitionReceiptConfirmation::query()->create([
                    'requisition_id' => $requisition->id, 'payment_id' => $payment->id,
                    'receiver_type' => $receiver['receiver_type'], 'receiver_identity' => $receiver['receiver_identity'],
                    'amount' => $payment->amount, 'basis' => $basis,
                    'represented_name' => $basis === RequisitionReceiptConfirmation::ON_BEHALF ? $receiver['name'] : null,
                    'evidence_reference' => $evidence ?: null, 'note' => $note ?: null,
                    'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                ]);
            }

            $amount = collect($created)->reduce(fn (string $sum, $c) => bcadd($sum, (string) $c->amount, 2), '0.00');
            $references = collect($created)->map(fn ($c) => $payable[$c->payment_id]->requisition_child_reference)->all();
            $this->audit($requisition, $actor, 'requisition_receipt_confirmed',
                "{$receiver['name']} confirmed receipt of KES {$amount} (".implode(', ', $references).')'
                    .($basis === RequisitionReceiptConfirmation::ON_BEHALF ? " — confirmed on their behalf by {$actor->name}" : ''),
                ['receiver' => $this->receiverRef($receiver), 'amount' => $amount, 'child_references' => $references,
                    'basis' => $basis, 'evidence_reference' => $evidence ?: null, 'note' => $note ?: null,
                    'confirmed_before' => $receiver['confirmed'], 'confirmed_after' => bcadd($receiver['confirmed'], $amount, 2)]);

            return $created;
        });
    }

    // ── Accountability (surrender) ───────────────────────────────────────────

    /**
     * Submit, or resubmit after a return, one stage of a receiver's surrender.
     *
     * @param  array<string, mixed>  $data  receiver_key, items[], returns[], notes, idempotency_key
     */
    public function submit(int $requisitionId, User $actor, array $data): PettyCashSurrender
    {
        return DB::transaction(function () use ($requisitionId, $actor, $data) {
            $requisition = $this->lockOpen($requisitionId);

            $key = filled($data['idempotency_key'] ?? null) ? (string) $data['idempotency_key'] : null;
            if ($key && ($existing = PettyCashSurrender::query()->where('idempotency_key', $key)->first())) {
                if ((int) $existing->requisition_id !== $requisition->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key belongs to another requisition.']);
                }

                return $existing;
            }

            $receiver = $this->position->receiver($requisition, (string) ($data['receiver_key'] ?? ''));
            if (! $this->maySubmit($requisition, $receiver, $actor)) {
                throw new AuthorizationException("You are not authorized to account for {$receiver['name']}'s money.");
            }

            $items = array_values((array) ($data['items'] ?? []));
            $returns = array_values((array) ($data['returns'] ?? []));
            if ($items === [] && $returns === []) {
                throw ValidationException::withMessages(['items' => 'Enter what was spent, what is being returned, or both.']);
            }

            $surrender = PettyCashSurrender::query()->where('requisition_id', $requisition->id)
                ->where('receiver_type', $receiver['receiver_type'])->where('receiver_identity', $receiver['receiver_identity'])
                ->whereIn('status', [PettyCashSurrender::SUBMITTED, PettyCashSurrender::RETURNED])->lockForUpdate()->first();
            if ($surrender?->status === PettyCashSurrender::SUBMITTED) {
                throw ValidationException::withMessages([
                    'accountability' => "{$surrender->reference} is with Finance for review. It must be reconciled or returned before {$receiver['name']} accounts for more.",
                ]);
            }

            $resubmission = (bool) $surrender;
            if ($resubmission) {
                // The returned submission was snapshotted when Finance returned it; what
                // replaces it is never-posted working data.
                $surrender->allocations()->delete();
                $surrender->allItems()->whereNull('cost_line_id')->delete();
                $requisition->unsetRelations();
                $receiver = $this->position->receiver($requisition, $receiver['key']);
            }

            $lines = collect($receiver['lines'])->keyBy('id');
            $plan = $this->planAllocations($receiver, $lines, $items, $returns);

            if (! $resubmission) {
                $sequence = (int) $requisition->accountability_sequence + 1;
                $requisition->forceFill(['accountability_sequence' => $sequence])->save();
                $surrender = new PettyCashSurrender([
                    'requisition_id' => $requisition->id,
                    'reference' => $requisition->requisition_number.'-A'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                    'receiver_type' => $receiver['receiver_type'], 'receiver_identity' => $receiver['receiver_identity'],
                ]);
            }
            $surrender->fill([
                'receiver_name' => $receiver['name'], 'status' => PettyCashSurrender::SUBMITTED,
                'spent_amount' => $plan['spent'], 'returned_amount' => $plan['returned'], 'overspend_amount' => $plan['overspend'],
                'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                'idempotency_key' => $key ?? $surrender->idempotency_key,
                'submitted_by' => $actor->id, 'submitted_at' => now(),
            ])->save();

            $seen = [];
            foreach ($items as $index => $itemData) {
                $gross = bcadd((string) $itemData['amount'], '0', 2);
                $tax = bcadd((string) ($itemData['tax_amount'] ?? '0'), '0', 2);
                $item = $requisition->surrenderItems()->create([
                    ...$this->receipts->fields($itemData, $gross, $requisition, $seen, $actor),
                    'surrender_id' => $surrender->id, 'requisition_item_id' => (int) $itemData['requisition_item_id'],
                    'expense_code_id' => $itemData['expense_code_id'], 'amount' => $gross,
                    'net_amount' => bcsub($gross, $tax, 2), 'tax_amount' => $tax,
                    'receipt_type' => $itemData['receipt_type'] ?? 'none',
                    'receipt_number' => $itemData['receipt_number'] ?? null,
                    'supplier_kra_pin' => $itemData['supplier_kra_pin'] ?? null,
                    'supplier_name' => $itemData['supplier_name'] ?? null,
                    'description' => $itemData['description'],
                    'receipt_path' => $itemData['receipt_path'] ?? null,
                ]);
                unset($item);
            }
            foreach ($plan['allocations'] as $allocation) {
                PettyCashSurrenderAllocation::query()->create($allocation + ['surrender_id' => $surrender->id, 'requisition_id' => $requisition->id]);
            }

            if ($resubmission) {
                DB::table('petty_cash_surrender_reviews')->insert([
                    'petty_cash_requisition_id' => $requisition->id, 'action' => 'resubmitted', 'actor_user_id' => $actor->id,
                    'reason' => null, 'snapshot' => json_encode(['surrender' => $surrender->reference, 'items' => $surrender->items()->get()->toArray()]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->audit($requisition, $actor, $resubmission ? 'requisition_accountability_resubmitted' : 'requisition_accountability_submitted',
                "{$surrender->reference}: {$receiver['name']} accounted for KES {$plan['spent']} spent and KES {$plan['returned']} returned"
                    .(bccomp($plan['overspend'], '0.00', 2) > 0 ? " — KES {$plan['overspend']} is more than they hold and needs resolution" : ''),
                ['receiver' => $this->receiverRef($receiver), 'surrender_id' => $surrender->id, 'reference' => $surrender->reference,
                    'spent' => $plan['spent'], 'returned' => $plan['returned'], 'overspend' => $plan['overspend'],
                    'child_references' => $plan['child_references'],
                    'to_account_before' => $receiver['to_account']]);

            return $surrender->fresh(['items', 'allocations']);
        });
    }

    /** Finance sends a submitted surrender back to be corrected. Nothing is posted. */
    public function returnForCorrection(int $surrenderId, User $actor, string $reason): PettyCashSurrender
    {
        return DB::transaction(function () use ($surrenderId, $actor, $reason) {
            [$requisition, $surrender] = $this->lockSurrender($surrenderId);
            $this->assertReviewer($requisition, $surrender, $actor);
            if ($surrender->status !== PettyCashSurrender::SUBMITTED) {
                throw ValidationException::withMessages(['accountability' => 'Only a surrender awaiting reconciliation can be returned.']);
            }

            DB::table('petty_cash_surrender_reviews')->insert([
                'petty_cash_requisition_id' => $requisition->id, 'action' => 'returned', 'actor_user_id' => $actor->id, 'reason' => $reason,
                'snapshot' => json_encode(['surrender' => $surrender->toArray(), 'items' => $surrender->items()->get()->toArray(),
                    'allocations' => $surrender->allocations()->get()->toArray()]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $surrender->forceFill(['status' => PettyCashSurrender::RETURNED, 'returned_by' => $actor->id,
                'returned_at' => now(), 'return_reason' => $reason])->save();

            $this->audit($requisition, $actor, 'requisition_accountability_returned',
                "{$surrender->reference} returned to {$surrender->receiver_name} for correction: {$reason}",
                ['surrender_id' => $surrender->id, 'reference' => $surrender->reference, 'reason' => $reason,
                    'receiver' => ['type' => $surrender->receiver_type, 'name' => $surrender->receiver_name]]);

            return $surrender;
        });
    }

    /**
     * Finance accepts a surrender: the spend becomes project cost and expense
     * once, returned money comes back into the account it left, and the advances
     * it accounts for are cleared.
     */
    public function reconcile(int $surrenderId, User $actor): PettyCashSurrender
    {
        return DB::transaction(function () use ($surrenderId, $actor) {
            [$requisition, $surrender] = $this->lockSurrender($surrenderId);
            $this->assertReviewer($requisition, $surrender, $actor);
            if ($surrender->status !== PettyCashSurrender::SUBMITTED) {
                throw ValidationException::withMessages(['accountability' => "{$surrender->reference} is not awaiting reconciliation."]);
            }
            if (bccomp((string) $surrender->overspend_amount, '0.00', 2) > 0) {
                // No reimbursement, expense or new advance is created for the excess.
                throw ValidationException::withMessages([
                    'accountability' => "Overspend requires resolution: {$surrender->reference} claims KES {$surrender->overspend_amount} more than {$surrender->receiver_name} holds. ".self::OVERSPEND_INSTRUCTION.' Return this for correction within the amount advanced.',
                ]);
            }

            $allocations = $surrender->allocations()->with('payment.paymentSource')->get();
            $advanceAccounts = $this->advanceAccounts($allocations->pluck('payment')->unique('id'));

            $surrender->forceFill(['status' => PettyCashSurrender::RECONCILED, 'reconciled_by' => $actor->id, 'reconciled_at' => now()])->save();
            $surrender->load(['items.expenseCode', 'requisition']);

            try {
                // 1. Accepted spend reaches the project once, keyed to each item.
                $this->costs->post($requisition, $surrender->items,
                    (string) ($allocations->pluck('payment')->max('date_disbursed')?->toDateString() ?? now()->toDateString()),
                    $surrender->receiver_name);

                // 2. Each return is a money movement of its own, into the account it left.
                foreach ($allocations->where('kind', PettyCashSurrenderAllocation::RETURN) as $return) {
                    $this->receiveReturn($surrender, $return, $advanceAccounts[$return->payment_id], $actor);
                }

                // 3. One clearing entry: expense against the advance each Payment created.
                $cleared = [];
                foreach ($allocations->where('kind', PettyCashSurrenderAllocation::SPEND) as $spend) {
                    $account = $advanceAccounts[$spend->payment_id];
                    $cleared[$account] = bcadd($cleared[$account] ?? '0.00', (string) $spend->amount, 2);
                }
                if ($cleared !== []) {
                    $entry = $this->journals->postReceiverSurrender($surrender, $cleared);
                    $surrender->forceFill(['journal_entry_id' => $entry->id])->save();
                }
            } catch (InvalidArgumentException $failure) {
                // A closed period or an unmapped account: nothing is half-posted.
                throw ValidationException::withMessages(['accountability' => $failure->getMessage()]);
            }

            $this->syncCommitment($requisition);

            $after = $this->position->receiver($requisition->unsetRelations(), $surrender->receiver_type.':'.$surrender->receiver_identity);
            $this->audit($requisition, $actor, 'requisition_accountability_reconciled',
                "{$surrender->reference} reconciled: KES {$surrender->spent_amount} accepted and KES {$surrender->returned_amount} returned for {$surrender->receiver_name}; KES {$after['to_account']} still to account",
                ['surrender_id' => $surrender->id, 'reference' => $surrender->reference,
                    'receiver' => ['type' => $surrender->receiver_type, 'name' => $surrender->receiver_name],
                    'accepted' => (string) $surrender->spent_amount, 'returned' => (string) $surrender->returned_amount,
                    'journal_entry_id' => $surrender->journal_entry_id,
                    'child_references' => $allocations->pluck('payment.requisition_child_reference')->unique()->values()->all(),
                    'to_account_after' => $after['to_account']]);

            return $surrender->fresh(['items', 'allocations']);
        });
    }

    /**
     * Undo a reconciled surrender with compensating entries. Nothing is deleted:
     * the journal and each return's cash movement are reversed, the cost lines
     * are retired, and the receiver's balance to account opens again.
     */
    public function reverse(int $surrenderId, User $actor, string $reason): PettyCashSurrender
    {
        if (! $actor->can(Permissions::FINANCE_JOURNALS_REVERSE)) {
            throw new AuthorizationException('You are not authorized to reverse a reconciled surrender.');
        }

        return DB::transaction(function () use ($surrenderId, $actor, $reason) {
            [$requisition, $surrender] = $this->lockSurrender($surrenderId, allowClosed: true);
            if ($surrender->status !== PettyCashSurrender::RECONCILED) {
                throw ValidationException::withMessages(['accountability' => 'Only a reconciled surrender can be reversed.']);
            }
            if ((int) $requisition->user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['accountability' => 'The requester cannot reverse a surrender on their own requisition.']);
            }

            $surrender->load(['allItems', 'allocations.cashMovement', 'allocations.paymentSource']);
            try {
                if ($surrender->journal_entry_id && ($entry = JournalEntry::find($surrender->journal_entry_id))) {
                    $this->journals->reverseEntry($entry, $actor->id, "Surrender {$surrender->reference} reversed: {$reason}");
                }
                foreach ($surrender->allocations->where('kind', PettyCashSurrenderAllocation::RETURN) as $return) {
                    if ($return->cashMovement?->status === 'posted') {
                        $this->cash->void($return->cashMovement, $actor->id, "Surrender {$surrender->reference} reversed: {$reason}");
                    }
                    if ($return->paymentSource?->type === 'petty_cash') {
                        // The float was credited when the cash came back; take it out again.
                        $entry = LedgerEntry::custom("RET-{$surrender->reference}-{$return->id}-REV", 'debit', (string) $return->amount, [
                            'reason' => 'Reversal of cash returned on surrender', 'requisition_id' => $requisition->id,
                            'surrender' => $surrender->reference, 'reversal_reason' => $reason, 'reversed_by' => $actor->id,
                        ]);
                        $entry->sourceType = 'top_up';
                        $entry->sourceId = $requisition->id;
                        app(LedgerService::class)->post($entry);
                    }
                }
            } catch (InvalidArgumentException $failure) {
                throw ValidationException::withMessages(['accountability' => $failure->getMessage()]);
            }

            $costLines = [];
            foreach ($surrender->allItems as $item) {
                $line = $item->cost_line_id ? CostLine::query()->lockForUpdate()->find($item->cost_line_id) : null;
                if ($line && $line->status !== CostLine::STATUS_REVERSED) {
                    $line->forceFill(['status' => CostLine::STATUS_REVERSED, 'query_note' => "Surrender {$surrender->reference} reversed: {$reason}"])->save();
                    $costLines[] = $line->id;
                }
            }

            DB::table('petty_cash_surrender_reviews')->insert([
                'petty_cash_requisition_id' => $requisition->id, 'action' => 'reversed', 'actor_user_id' => $actor->id, 'reason' => $reason,
                'snapshot' => json_encode(['surrender' => $surrender->toArray(), 'reversed_cost_line_ids' => $costLines]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Retired, not deleted: their reversed cost lines still point at them.
            $surrender->allItems()->update(['superseded_at' => now()]);
            $surrender->forceFill(['status' => PettyCashSurrender::REVERSED, 'reversed_by' => $actor->id,
                'reversed_at' => now(), 'reversal_reason' => $reason])->save();

            $reopened = false;
            if ($requisition->closed_at) {
                // A closed requisition whose account no longer reconciles is not closed.
                $before = ['closed_at' => $requisition->closed_at->toIso8601String(), 'closed_by' => $requisition->closed_by];
                $requisition->forceFill(['closed_at' => null, 'closed_by' => null, 'status' => 'disbursed',
                    'surrender_reconciled_at' => null, 'surrender_reconciled_by' => null])->save();
                $reopened = true;
                $this->audit($requisition, $actor, 'requisition_reopened',
                    "{$requisition->requisition_number} reopened because {$surrender->reference} was reversed",
                    ['reason' => $reason, 'before' => $before]);
            }

            $this->syncCommitment($requisition);
            $this->audit($requisition, $actor, 'requisition_accountability_reversed',
                "{$surrender->reference} reversed: KES {$surrender->spent_amount} accepted spend and KES {$surrender->returned_amount} returned no longer stand — {$reason}",
                ['surrender_id' => $surrender->id, 'reference' => $surrender->reference, 'reason' => $reason,
                    'receiver' => ['type' => $surrender->receiver_type, 'name' => $surrender->receiver_name],
                    'reversed_cost_line_ids' => $costLines, 'reopened_requisition' => $reopened]);

            return $surrender->fresh();
        });
    }

    /** Bring the project commitment in step with the requisition's position. */
    public function syncCommitment(PettyCashRequisition $requisition): string
    {
        $position = $this->position->for($requisition->unsetRelations());
        if ($position['mode'] === RequisitionPosition::SINGLE_PAYMENT) {
            return 'unchanged';   // a single-payment requisition keeps its existing lifecycle
        }

        return $this->commitments->syncReceiverCommitment($requisition, bcadd($position['to_disburse'], $position['to_account'], 2));
    }

    // ── Authority ────────────────────────────────────────────────────────────

    /** The receiver's own login, where the receiver is an employee who has one. */
    public function receiverUserId(array $receiver): ?int
    {
        return $receiver['receiver_type'] === RequisitionReceiverIdentity::EMPLOYEE && $receiver['receiver_id']
            ? Employee::query()->with('user:id,employee_id')->find($receiver['receiver_id'])?->user?->id
            : null;
    }

    /**
     * Who may confirm receipt, as the existing rule has it: the receiver, or the
     * requester who raised the requisition. Nobody is given a login to stand in
     * for a receiver; a requester confirming for one is recorded as doing so.
     *
     * @return ?string the basis the actor would confirm on, or null if they may not
     */
    public function confirmationBasisFor(PettyCashRequisition $requisition, array $receiver, User $actor): ?string
    {
        if (! $actor->is_active) {
            return null;
        }
        if ($this->receiverUserId($receiver) === (int) $actor->id) {
            return RequisitionReceiptConfirmation::BY_RECEIVER;
        }

        return (int) $requisition->user_id === (int) $actor->id ? RequisitionReceiptConfirmation::ON_BEHALF : null;
    }

    /** The receiver, the requester, or Finance — the people the existing surrender accepts. */
    public function maySubmit(PettyCashRequisition $requisition, array $receiver, User $actor): bool
    {
        return $actor->is_active && ($this->receiverUserId($receiver) === (int) $actor->id
            || (int) $requisition->user_id === (int) $actor->id
            || $actor->can('create', Payment::class));
    }

    /** Finance's existing reconciliation authority, never exercised over one's own money. */
    public function mayReview(PettyCashRequisition $requisition, PettyCashSurrender $surrender, User $actor): bool
    {
        return $actor->is_active && $actor->can('create', Payment::class)
            && (int) $requisition->user_id !== (int) $actor->id
            && $this->receiverUserId(['receiver_type' => $surrender->receiver_type, 'receiver_id' => (int) $surrender->receiver_identity]) !== (int) $actor->id;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function confirmationBasis(PettyCashRequisition $requisition, array $receiver, User $actor): string
    {
        return $this->confirmationBasisFor($requisition, $receiver, $actor)
            ?? throw new AuthorizationException("Only {$receiver['name']} or the requester can confirm this receipt.");
    }

    private function assertReviewer(PettyCashRequisition $requisition, PettyCashSurrender $surrender, User $actor): void
    {
        if (! $actor->is_active || ! $actor->can('create', Payment::class)) {
            throw new AuthorizationException('You are not authorized to reconcile surrenders.');
        }
        if (! $this->mayReview($requisition, $surrender, $actor)) {
            throw ValidationException::withMessages(['accountability' => 'The requester and the receiver cannot review their own surrender.']);
        }
    }

    /** Lock the parent of a requisition still open to confirmation and accountability. */
    private function lockOpen(int $requisitionId): PettyCashRequisition
    {
        $requisition = PettyCashRequisition::query()->lockForUpdate()->findOrFail($requisitionId);
        if ($requisition->closed_at || $requisition->status !== 'disbursed') {
            throw ValidationException::withMessages([
                'requisition' => $requisition->closed_at ? 'This requisition is closed.' : 'Nothing has been paid on this requisition by receiver yet.',
            ]);
        }

        return $requisition;
    }

    /** @return array{0: PettyCashRequisition, 1: PettyCashSurrender} parent first, then the surrender */
    private function lockSurrender(int $surrenderId, bool $allowClosed = false): array
    {
        $parentId = PettyCashSurrender::query()->whereKey($surrenderId)->value('requisition_id')
            ?? abort(404, 'Surrender not found.');
        $requisition = PettyCashRequisition::query()->lockForUpdate()->findOrFail($parentId);
        $surrender = PettyCashSurrender::query()->lockForUpdate()->findOrFail($surrenderId);
        if ($requisition->closed_at && ! $allowClosed) {
            throw ValidationException::withMessages(['requisition' => 'This requisition is closed.']);
        }

        return [$requisition, $surrender];
    }

    /** Active Payments made to one receiver, oldest first. */
    private function receiverPayments(PettyCashRequisition $requisition, string $receiverKey)
    {
        return $requisition->disbursements()->where('status', 'active')->whereNotNull('requisition_child_reference')
            ->with('requisitionAllocations')->orderBy('id')->get()
            ->filter(fn (Payment $p) => $p->requisitionAllocations
                ->contains(fn ($a) => $a->receiver_type.':'.$a->receiver_identity === $receiverKey))
            ->values();
    }

    /**
     * Decide which funded slices the submitted amounts clear.
     *
     * Spend fills the line's slices in the order they were paid. A return names
     * the Payment it relates to; where it does not, it is placed only if the
     * line's remaining slices all came from one paying account — otherwise the
     * person submitting must say, and nothing is guessed.
     *
     * @return array{allocations: list<array<string, mixed>>, spent: string, returned: string, overspend: string, child_references: list<string>}
     */
    private function planAllocations(array $receiver, $lines, array $items, array $returns): array
    {
        $available = [];
        foreach ($lines as $line) {
            foreach ($line['slices'] as $slice) {
                $available[$line['id']][$slice['id']] = $slice;
            }
        }
        $allocations = [];
        $references = [];
        $take = function (int $lineId, int $sliceId, string $amount, string $kind, ?string $reference = null) use (&$available, &$allocations, &$references) {
            $slice = &$available[$lineId][$sliceId];
            $slice['available'] = bcsub($slice['available'], $amount, 2);
            $allocations[] = ['requisition_payment_allocation_id' => $sliceId, 'payment_id' => $slice['payment_id'],
                'requisition_item_id' => $lineId, 'kind' => $kind, 'amount' => $amount,
                'payment_source_id' => $kind === PettyCashSurrenderAllocation::RETURN ? $slice['payment_source_id'] : null,
                'reference' => $reference];
            $references[$slice['child_reference']] = true;
        };
        $lineOf = function (array $row, string $field) use ($lines, $receiver): array {
            $line = $lines->get((int) ($row['requisition_item_id'] ?? 0));
            if (! $line) {
                throw ValidationException::withMessages([$field => "Each amount must name one of {$receiver['name']}'s requisition lines."]);
            }

            return $line;
        };

        // Returns that name their Payment are placed first: that choice is the submitter's.
        $returned = '0.00';
        $unplaced = [];
        foreach ($returns as $index => $return) {
            $line = $lineOf($return, "returns.{$index}.requisition_item_id");
            $amount = bcadd((string) ($return['amount'] ?? '0'), '0', 2);
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(["returns.{$index}.amount" => 'Enter the amount returned.']);
            }
            $returned = bcadd($returned, $amount, 2);
            if (blank($return['payment_id'] ?? null)) {
                $unplaced[] = [$index, $line, $amount, $return['reference'] ?? null];

                continue;
            }
            $slice = collect($available[$line['id']] ?? [])->firstWhere('payment_id', (int) $return['payment_id']);
            if (! $slice || bccomp($amount, $slice['available'], 2) > 0) {
                throw ValidationException::withMessages([
                    "returns.{$index}.amount" => $slice
                        ? "Only KES {$slice['available']} of {$slice['child_reference']} for \"{$line['purpose']}\" is held and unaccounted."
                        : "That payment did not fund \"{$line['purpose']}\".",
                ]);
            }
            $take($line['id'], $slice['id'], $amount, PettyCashSurrenderAllocation::RETURN, $return['reference'] ?? null);
        }

        $spent = '0.00';
        $overspend = '0.00';
        foreach ($items as $index => $item) {
            $line = $lineOf($item, "items.{$index}.requisition_item_id");
            $left = bcadd((string) ($item['amount'] ?? '0'), '0', 2);
            if (bccomp($left, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(["items.{$index}.amount" => 'Enter the amount spent.']);
            }
            $spent = bcadd($spent, $left, 2);
            foreach (array_keys($available[$line['id']] ?? []) as $sliceId) {
                $free = $available[$line['id']][$sliceId]['available'];
                $slice = bccomp($left, $free, 2) < 0 ? $left : $free;
                if (bccomp($slice, '0.00', 2) > 0) {
                    $take($line['id'], $sliceId, $slice, PettyCashSurrenderAllocation::SPEND);
                    $left = bcsub($left, $slice, 2);
                }
            }
            // Spend beyond what the receiver holds on this line is recorded and held, not placed.
            $overspend = bcadd($overspend, $left, 2);
        }

        foreach ($unplaced as [$index, $line, $amount, $reference]) {
            $candidates = collect($available[$line['id']] ?? [])->filter(fn ($slice) => bccomp($slice['available'], '0.00', 2) > 0);
            if (bccomp($amount, $candidates->reduce(fn (string $sum, $s) => bcadd($sum, $s['available'], 2), '0.00'), 2) > 0) {
                throw ValidationException::withMessages([
                    "returns.{$index}.amount" => "KES {$amount} is more than {$receiver['name']} still holds for \"{$line['purpose']}\".",
                ]);
            }
            if ($candidates->pluck('payment_source_id')->unique()->count() > 1) {
                throw ValidationException::withMessages([
                    "returns.{$index}.payment_id" => "\"{$line['purpose']}\" was paid from more than one account ("
                        .$candidates->map(fn ($s) => "{$s['child_reference']} from {$s['paying_account']}")->implode(', ')
                        .'). Choose which payment this return relates to.',
                ]);
            }
            foreach ($candidates as $sliceId => $slice) {
                $part = bccomp($amount, $slice['available'], 2) < 0 ? $amount : $slice['available'];
                if (bccomp($part, '0.00', 2) > 0) {
                    $take($line['id'], $sliceId, $part, PettyCashSurrenderAllocation::RETURN, $reference);
                    $amount = bcsub($amount, $part, 2);
                }
            }
        }

        return ['allocations' => $allocations, 'spent' => $spent, 'returned' => $returned, 'overspend' => $overspend,
            'child_references' => array_keys($references)];
    }

    /**
     * The account each Payment's advance sits in, read from its own advance
     * journal — so an advance is cleared from where it was actually posted,
     * whatever the configuration says today.
     *
     * @return array<int, int> payment id => account id
     */
    private function advanceAccounts($payments): array
    {
        $accounts = [];
        foreach ($payments as $payment) {
            $reference = $payment->requisition_child_reference ?: $payment->payment_no;
            if ($payment->status !== 'active') {
                throw ValidationException::withMessages(['accountability' => "{$reference} has been reversed and can no longer be accounted for."]);
            }
            $accountId = $payment->advance_journal_entry_id && ! $payment->advance_gl_posting_failed_at
                ? DB::table('journal_lines')->where('journal_entry_id', $payment->advance_journal_entry_id)
                    ->where('entry_type', 'debit')->orderBy('id')->value('account_id')
                : null;
            if (! $accountId) {
                throw ValidationException::withMessages([
                    'accountability' => "{$reference} has not reached the ledger yet. Retry its ledger posting before reconciling money accounted against it.",
                ]);
            }
            $accounts[$payment->id] = (int) $accountId;
        }

        return $accounts;
    }

    /** Money coming back: its own cash movement and journal, and the float's cashbook where it is float money. */
    private function receiveReturn(PettyCashSurrender $surrender, PettyCashSurrenderAllocation $return, int $advanceAccountId, User $actor): void
    {
        $payment = $return->payment;
        $movement = $this->cash->create([
            'payment_source_id' => $return->payment_source_id,
            'transaction_date' => now()->toDateString(),
            'direction' => 'in',
            'transaction_type' => 'requisition_return',
            'reference' => $return->reference ?: $surrender->reference,
            'description' => "Unused funds returned by {$surrender->receiver_name} against {$payment->requisition_child_reference}",
            'counterparty' => $surrender->receiver_name,
            'amount' => (string) $return->amount,
            'offset_account_id' => $advanceAccountId,
        ], $actor->id);
        $return->forceFill(['cash_movement_id' => $movement->id])->save();

        if ($payment->paymentSource?->type === 'petty_cash') {
            $entry = LedgerEntry::custom("RET-{$surrender->reference}-{$return->id}", 'credit', (string) $return->amount, [
                'reason' => 'Cash returned on surrender', 'requisition_id' => $surrender->requisition_id,
                'surrender' => $surrender->reference, 'child_reference' => $payment->requisition_child_reference,
                'payee_name' => $surrender->receiver_name, 'reconciled_by' => $actor->id,
            ]);
            $entry->sourceType = 'top_up';
            $entry->sourceId = $surrender->requisition_id;
            app(LedgerService::class)->post($entry);
        }
    }

    private function receiverRef(array $receiver): array
    {
        return ['type' => $receiver['receiver_type'], 'id' => $receiver['receiver_id'], 'name' => $receiver['name'], 'key' => $receiver['key']];
    }

    private function audit(PettyCashRequisition $requisition, User $actor, string $event, string $message, array $context): void
    {
        GovernanceAuditLog::query()->create([
            'project_enquiry_id' => $requisition->enquiry_id, 'user_id' => $actor->id, 'gate_type' => $event,
            'action_status' => 'recorded', 'model_type' => PettyCashRequisition::class, 'model_id' => $requisition->id,
            'message' => $message, 'context' => $context + ['requisition' => $requisition->requisition_number],
        ]);
    }
}
