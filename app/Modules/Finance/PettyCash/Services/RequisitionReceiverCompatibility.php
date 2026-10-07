<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Validation\ValidationException;

/**
 * Report 75R-B: which open requisitions can be paid as they stand.
 *
 * Requisitions raised before receivers had identities may name several people
 * by typed name only. They can no longer be paid as one payment, and cannot be
 * paid by receiver until each receiver is identified. This reads them and says
 * what each one needs. It changes nothing, and it never turns a similar-looking
 * name into an identity.
 */
final class RequisitionReceiverCompatibility
{
    public const READY = 'READY FOR LEGACY SINGLE PAYMENT';
    public const READY_BY_RECEIVER = 'READY FOR PAYMENT BY RECEIVER';
    public const NEEDS_IDENTIFICATION = 'REQUIRES RECEIVER IDENTIFICATION';
    public const NEEDS_VERIFICATION = 'REQUIRES RE-VERIFICATION';
    public const NEEDS_APPROVAL = 'REQUIRES RE-APPROVAL';
    public const MANUAL_REVIEW = 'MANUAL REVIEW';

    public function __construct(
        private readonly RequisitionReceiverIdentity $identities,
        private readonly RequisitionVerificationService $verification,
    ) {
    }

    /**
     * Open requisitions that have not been paid, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        return PettyCashRequisition::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereDoesntHave('disbursements', fn ($q) => $q->where('status', 'active'))
            ->with(['items', 'requester:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (PettyCashRequisition $r) => $this->classify($r))
            ->all();
    }

    /** @return array<string, mixed> */
    public function classify(PettyCashRequisition $r): array
    {
        $lines = $r->items;
        $row = fn (string $class, string $why, array $more = []) => [
            'id' => $r->id, 'reference' => $r->requisition_number, 'status' => $r->status,
            'requester' => $r->requester?->name ?? $r->requester_name, 'amount' => (string) $r->total_amount,
            'lines' => $lines->count(), 'classification' => $class, 'reason' => $why,
        ] + $more;

        if ($r->bill_id) {
            return $row(self::MANUAL_REVIEW, 'Settles a supplier bill; it is paid through supplier settlement, not as an advance.');
        }
        $lineTotal = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->amount, 2), '0.00');
        if ($lines->isEmpty() || bccomp($lineTotal, (string) $r->total_amount, 2) !== 0) {
            return $row(self::MANUAL_REVIEW, $lines->isEmpty()
                ? 'Has no lines, so there is nobody to pay.'
                : "Its lines add up to KES {$lineTotal}, not the KES {$r->total_amount} on the requisition.");
        }

        $single = $this->identities->isSinglePayment($r);
        if (! $single) {
            $typed = [];
            try {
                $this->identities->forRequisition($r, $lines);
            } catch (ValidationException $e) {
                foreach ($lines as $line) {
                    try {
                        $this->identities->forLine($r, $line);
                    } catch (ValidationException) {
                        $typed[] = trim((string) ($line->payee_name ?: $r->payee_name)) ?: '(no name)';
                    }
                }

                return $row(self::NEEDS_IDENTIFICATION,
                    collect($e->errors())->flatten()->first().' Each receiver must be identified by the creator; it then needs verifying and approving again.',
                    ['receivers_named_only' => array_values(array_unique($typed))]);
            }
        }

        if (! $this->verification->isCurrent($r)) {
            return $row(self::NEEDS_VERIFICATION, $r->verification_status
                ? 'Its current details are not verified. Verification, then approval, is needed before payment.'
                : 'It was raised before verification existed and has never been verified.');
        }
        if (! $r->approved_at || $r->status !== 'approved') {
            return $row(self::NEEDS_APPROVAL, 'Verified, and waiting for Finance approval.');
        }

        return $row($single ? self::READY : self::READY_BY_RECEIVER, $single
            ? 'One receiver, verified and approved: payable as a single payment.'
            : 'Every receiver is identified, and it is verified and approved: payable receiver by receiver.');
    }
}
