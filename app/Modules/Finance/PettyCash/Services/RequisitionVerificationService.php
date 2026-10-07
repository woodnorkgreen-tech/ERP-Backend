<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Certification of the request, independent of approval, cash custody and expenditure acceptance. */
final class RequisitionVerificationService
{
    public const PERMISSION = Permissions::FINANCE_REQUISITIONS_VERIFY;

    public function eligibleVerifiers()
    {
        return User::query()->assignable()->permission(self::PERMISSION)->orderBy('name');
    }

    public function validateVerifier(int $id, ?int $creatorId): void
    {
        if ($id === $creatorId) {
            throw ValidationException::withMessages(['responsible_verifier_id' => 'Choose someone other than the creator to verify this requisition.']);
        }
        if (! $this->eligibleVerifiers()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['responsible_verifier_id' => 'Choose an active user with requisition verification permission.']);
        }
    }

    /** The exact material envelope certified by the verifier. Child row IDs are not business details. */
    public function fingerprint(PettyCashRequisition $r): string
    {
        $fields = PettyCashRequisition::VERIFICATION_FIELDS;
        $envelope = $r->only($fields);
        $envelope['items'] = $r->items()->orderBy('id')->get()->map(fn ($item) => $item->only([
            'description', 'remarks', 'details', 'amount', 'payee_id', 'payee_name', 'payee_phone', 'supplier_id', 'other_recipient_reference',
        ]))->all();

        return hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    public function isCurrent(PettyCashRequisition $r): bool
    {
        return $r->verification_status === 'verified'
            && filled($r->verified_by) && filled($r->verified_at) && filled($r->verification_fingerprint)
            && hash_equals($r->verification_fingerprint, $this->fingerprint($r));
    }

    public function assertCurrent(PettyCashRequisition $r): void
    {
        if (! $this->isCurrent($r)) {
            throw ValidationException::withMessages(['verification' => 'This requisition requires verification of its current details before approval or payment.']);
        }
    }

    /** Called inside the creator's transaction, after all lines have been written. */
    public function submit(PettyCashRequisition $r, ?int $actorId): void
    {
        $this->validateVerifier((int) $r->responsible_verifier_id, $r->user_id);
        $previous = $r->verification_status;
        if ($previous === 'verified') {
            $this->audit($r, $actorId, 'verification_invalidated', 'Edited requisition requires a new verification.', [
                'previous_fingerprint' => $r->verification_fingerprint, 'verified_by' => $r->verified_by,
                'verified_at' => $r->verified_at?->toIso8601String(),
            ]);
        }
        $r->forceFill(['verification_status' => 'pending_verification', 'verified_by' => null,
            'verified_at' => null, 'verification_fingerprint' => null, 'verification_comment' => null])->save();
        $this->audit($r, $actorId, 'submitted_for_verification', 'Submitted for independent verification.', ['previous_status' => $previous]);
    }

    public function review(int $id, User $actor, string $decision, ?string $comment): PettyCashRequisition
    {
        // Direct permission check intentionally ignores the global Super Admin bypass.
        abort_unless($actor->is_active && $actor->hasPermissionTo(self::PERMISSION, 'web'), 403, 'Requisition verification permission is required.');

        return DB::transaction(function () use ($id, $actor, $decision, $comment) {
            $r = PettyCashRequisition::query()->lockForUpdate()->findOrFail($id);
            abort_unless((int) $r->responsible_verifier_id === (int) $actor->id, 403, 'Only the assigned verifier can review this requisition.');
            $this->validateVerifier((int) $actor->id, $r->user_id);
            abort_unless($r->status === 'pending' && $r->verification_status === 'pending_verification', 409, 'Only a submitted requisition awaiting verification can be reviewed.');
            $items = $r->items()->lockForUpdate()->get();
            $total = '0.00';
            foreach ($items as $item) {
                if (bccomp((string) $item->amount, '0.00', 2) <= 0 || !($item->payee_id || $item->supplier_id || filled($item->payee_name) || $r->payee_id || filled($r->payee_name))) {
                    throw ValidationException::withMessages(['items' => 'Every requisition line needs a receiver and a positive amount before verification.']);
                }
                $total = bcadd($total, (string) $item->amount, 2);
            }
            if ($items->isEmpty() || bccomp($total, (string) $r->total_amount, 2) !== 0) {
                throw ValidationException::withMessages(['items' => 'Requisition lines must match the parent total.']);
            }
            // Report 75R-A: a verifier certifies who is to be paid. Where the lines
            // name suppliers or other recipients, each one must be a single,
            // unambiguous identity before the requisition can be verified.
            $receivers = app(RequisitionReceiverIdentity::class);
            if ($decision === 'verified' && $receivers->usesAllocatedReceivers($items)) {
                $receivers->forRequisition($r, $items);
            }
            if ($decision === 'returned_for_correction' && blank(trim((string) $comment))) {
                throw ValidationException::withMessages(['comment' => 'Explain what the creator must correct.']);
            }
            $verified = $decision === 'verified';
            $r->forceFill(['verification_status' => $decision, 'verification_comment' => $comment,
                'verified_by' => $verified ? $actor->id : null, 'verified_at' => $verified ? now() : null,
                'verification_fingerprint' => $verified ? $this->fingerprint($r) : null])->save();
            $this->audit($r, $actor->id, $decision, $verified ? 'Requisition details, receivers and amounts verified.' : 'Returned to the creator for correction.', ['comment' => $comment, 'amount' => $total]);

            return $r;
        });
    }

    public function audit(PettyCashRequisition $r, ?int $actorId, string $event, string $message, array $context = []): void
    {
        GovernanceAuditLog::query()->create(['project_enquiry_id' => $r->enquiry_id, 'user_id' => $actorId,
            'gate_type' => 'requisition_'.$event, 'action_status' => 'recorded',
            'model_type' => PettyCashRequisition::class, 'model_id' => $r->id, 'message' => $message, 'context' => $context]);
    }
}
