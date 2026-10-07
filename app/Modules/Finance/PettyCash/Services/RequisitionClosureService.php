<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\RequisitionBalanceRelease;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 75R-B: the end of a requisition paid by receiver — giving up approved
 * money that will not be paid, and closing once everything reconciles.
 *
 * Closing is a conclusion, not a switch. `blockers()` lists every reason the
 * requisition does not yet reconcile; `close()` succeeds only when that list is
 * empty. There is no way to mark a requisition closed around it.
 */
final class RequisitionClosureService
{
    public function __construct(
        private readonly RequisitionPosition $position,
        private readonly RequisitionAccountabilityService $accountability,
    ) {
    }

    /**
     * Record that part of a receiver's approved money will not be paid.
     *
     * The approved amount is not changed. The release stands beside it, so the
     * record keeps saying what was approved, what was paid and what was given up.
     *
     * @param  array<string, mixed>  $data  receiver_key, amount, reason, idempotency_key
     * @return list<RequisitionBalanceRelease>
     */
    public function release(int $requisitionId, User $actor, array $data): array
    {
        // Held directly, not through the administrator bypass: nobody releases
        // approved money merely by being an administrator.
        if (! $actor->is_active || ! $actor->hasPermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, 'web')) {
            throw new AuthorizationException('You are not authorized to release an unused approved balance.');
        }
        $requestKey = (string) ($data['idempotency_key'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));
        $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 2);
        if ($requestKey === '' || mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Give the reason this money will not be paid (at least 10 characters).']);
        }

        return DB::transaction(function () use ($requisitionId, $actor, $data, $requestKey, $reason, $amount) {
            $requisition = PettyCashRequisition::query()->lockForUpdate()->findOrFail($requisitionId);

            $existing = RequisitionBalanceRelease::query()->where('request_key', $requestKey)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn ($row) => (int) $row->requisition_id !== $requisition->id)
                    || bccomp($existing->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->amount, 2), '0.00'), $amount, 2) !== 0) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different release.']);
                }

                return $existing->all();
            }

            if (! $requisition->approved_at || $requisition->closed_at || ! in_array($requisition->status, ['approved', 'disbursed'], true)) {
                throw ValidationException::withMessages(['requisition' => 'Only an approved requisition that is still open has a balance to release.']);
            }
            if ((int) $requisition->user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['requisition' => 'The requester cannot release the balance of their own requisition.']);
            }

            $before = $this->position->for($requisition);
            if ($before['legacy_single_payment']) {
                throw ValidationException::withMessages(['requisition' => 'This requisition was paid as a single payment and has no receiver balance to release.']);
            }
            $receiver = $this->position->receiver($requisition, (string) ($data['receiver_key'] ?? ''));
            if (bccomp($amount, '0.00', 2) <= 0 || bccomp($amount, $receiver['to_disburse'], 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => "KES {$amount} cannot be released: {$receiver['name']} has KES {$receiver['to_disburse']} approved and not yet paid.",
                ]);
            }

            // Taken from the receiver's lines in order, so each purpose keeps its own figures.
            $left = $amount;
            $rows = [];
            foreach ($receiver['lines'] as $line) {
                $part = bccomp($left, $line['outstanding'], 2) < 0 ? $left : $line['outstanding'];
                if (bccomp($part, '0.00', 2) <= 0) {
                    continue;
                }
                $rows[] = RequisitionBalanceRelease::query()->create([
                    'requisition_id' => $requisition->id, 'requisition_item_id' => $line['id'],
                    'receiver_type' => $receiver['receiver_type'], 'receiver_identity' => $receiver['receiver_identity'],
                    'amount' => $part, 'reason' => $reason, 'request_key' => $requestKey,
                    'released_by' => $actor->id, 'released_at' => now(),
                ]);
                $left = bcsub($left, $part, 2);
            }

            $this->accountability->syncCommitment($requisition);
            $after = $this->position->for($requisition->unsetRelations());
            $this->audit($requisition, $actor, 'requisition_unused_balance_released',
                "KES {$amount} of {$receiver['name']}'s approved allocation released as unused: {$reason}",
                ['receiver' => ['type' => $receiver['receiver_type'], 'id' => $receiver['receiver_id'], 'name' => $receiver['name']],
                    'amount' => $amount, 'reason' => $reason, 'lines' => collect($rows)->map->only(['requisition_item_id', 'amount'])->all(),
                    'before' => ['approved' => $before['approved'], 'disbursed' => $before['disbursed'], 'released' => $before['released'], 'to_disburse' => $before['to_disburse']],
                    'after' => ['approved' => $after['approved'], 'disbursed' => $after['disbursed'], 'released' => $after['released'], 'to_disburse' => $after['to_disburse']]]);

            return $rows;
        });
    }

    /**
     * Every reason this requisition cannot be closed yet. Empty means it reconciles.
     *
     * @return list<array{code: string, message: string, receiver?: string}>
     */
    public function blockers(PettyCashRequisition $requisition, ?array $position = null): array
    {
        $position ??= $this->position->for($requisition);
        $blockers = [];
        $add = function (string $code, string $message, ?string $receiver = null) use (&$blockers) {
            $blockers[] = array_filter(['code' => $code, 'message' => $message, 'receiver' => $receiver]);
        };
        $positive = fn (string $amount) => bccomp($amount, '0.00', 2) > 0;

        if (! $requisition->approved_at) {
            $add('not_approved', 'The requisition has not been approved.');
        }
        if ($position['legacy_single_payment']) {
            $add('single_payment', 'This requisition was paid as a single payment and is closed by reconciling its surrender.');

            return $blockers;
        }
        if ($position['mode'] === RequisitionPosition::NOT_PAID && ! $positive($position['released'])) {
            $add('nothing_paid', 'Nothing has been paid or released on this requisition.');
        }
        if (bccomp($position['disbursed'], bcsub($position['approved'], $position['released'], 2), 2) > 0) {
            $add('over_disbursed', "KES {$position['disbursed']} has been paid against KES {$position['approved']} approved.");
        }

        foreach ($position['receivers'] as $receiver) {
            $name = $receiver['name'];
            if ($receiver['receiver_problem']) {
                $add('receiver_not_identified', "{$name}: {$receiver['receiver_problem']}", $name);

                continue;
            }
            if ($positive($receiver['to_disburse'])) {
                $add('undisbursed_balance', "{$name} has KES {$receiver['to_disburse']} approved that is neither paid nor released.", $name);
            }
            if ($positive($receiver['awaiting_confirmation'])) {
                $add('receipt_unconfirmed', "{$name} has not confirmed receipt of KES {$receiver['awaiting_confirmation']}.", $name);
            }
            if ($positive($receiver['overspend'])) {
                $add('overspend_unresolved', "{$name} has claimed KES {$receiver['overspend']} more than they hold; the overspend is unresolved.", $name);
            }
            if ($positive($receiver['under_review'])) {
                $add('accountability_under_review', "{$name}'s surrender of KES {$receiver['under_review']} is awaiting Finance reconciliation.", $name);
            }
            if ($receiver['open_surrender_id'] && ! $positive($receiver['under_review']) && ! $positive($receiver['overspend'])) {
                $add('accountability_returned', "{$name}'s surrender was returned for correction and has not been resubmitted.", $name);
            }
            $unsupported = bcsub($receiver['to_account'], $receiver['under_review'], 2);
            if ($positive($unsupported)) {
                $add('accountability_outstanding', "{$name} still has KES {$unsupported} to account for.", $name);
            }
        }

        $failed = $requisition->disbursements->where('status', 'active')
            ->filter(fn (Payment $p) => $p->advance_gl_posting_failed_at !== null || ! $p->advance_journal_entry_id);
        foreach ($failed as $payment) {
            $add('posting_failed', "{$payment->requisition_child_reference} has not reached the ledger.");
        }

        return $blockers;
    }

    /** Close the requisition. Refused, with the reasons, unless it reconciles. */
    public function close(int $requisitionId, User $actor): PettyCashRequisition
    {
        // Closing is the end of reconciliation, so it is Finance's reconciliation authority.
        if (! $actor->is_active || ! $actor->can('create', Payment::class)) {
            throw new AuthorizationException('You are not authorized to close requisitions.');
        }

        return DB::transaction(function () use ($requisitionId, $actor) {
            $requisition = PettyCashRequisition::query()->lockForUpdate()->findOrFail($requisitionId);
            if ($requisition->closed_at) {
                return $requisition;   // already closed: nothing further happens
            }
            if ((int) $requisition->user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['requisition' => 'The requester cannot close their own requisition.']);
            }

            $position = $this->position->for($requisition);
            $blockers = $this->blockers($requisition, $position);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['requisition' => array_column($blockers, 'message')]);
            }

            // `surrendered` is the stage older screens and the advance lists already
            // read as accounted for; the closure itself is closed_at.
            $requisition->forceFill(['closed_at' => now(), 'closed_by' => $actor->id, 'status' => 'surrendered',
                'surrender_reconciled_at' => now(), 'surrender_reconciled_by' => $actor->id])->save();
            $this->accountability->syncCommitment($requisition);

            $this->audit($requisition, $actor, 'requisition_closed',
                "{$requisition->requisition_number} closed: KES {$position['approved']} approved = KES {$position['disbursed']} disbursed + KES {$position['released']} released; "
                    ."KES {$position['disbursed']} disbursed = KES {$position['accepted']} accepted + KES {$position['returned']} returned",
                ['approved' => $position['approved'], 'disbursed' => $position['disbursed'], 'released' => $position['released'],
                    'accepted' => $position['accepted'], 'returned' => $position['returned'], 'before' => ['status' => 'disbursed']]);

            return $requisition->fresh();
        });
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
