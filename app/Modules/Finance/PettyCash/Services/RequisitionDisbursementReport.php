<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report 75R-A: the questions Finance asks across requisitions, answered from
 * the same records the payment path writes — Payments bound to their parent and
 * their line allocations. No figure here is stored or estimated.
 */
final class RequisitionDisbursementReport
{
    /** SQL for "what active Payments have paid against this requisition". */
    private const PAID = "(SELECT COALESCE(SUM(p.amount), 0) FROM payments p
        WHERE p.requisition_id = petty_cash_requisitions.id AND p.status = 'active')";

    private const APPROVED = '(CASE WHEN petty_cash_requisitions.approved_at IS NULL THEN 0 ELSE petty_cash_requisitions.total_amount END)';

    /**
     * Narrow a requisition query to one derived disbursement status.
     * The status is computed from active Payments, never read from a column.
     */
    public function whereDisbursementStatus(Builder $query, string $status): Builder
    {
        [$paid, $approved] = [self::PAID, self::APPROVED];

        return match ($status) {
            'not_disbursed' => $query->whereRaw("$paid = 0"),
            'partially_disbursed' => $query->whereRaw("$paid > 0 AND $paid < $approved"),
            'fully_disbursed' => $query->whereRaw("$paid > 0 AND $paid = $approved"),
            'over_disbursement_exception' => $query->whereRaw("$paid > $approved"),
            default => $query,
        };
    }

    /**
     * Every Payment made against a requisition: by parent, receiver, child
     * reference, PAY reference, project, requester or verifier; reversed ones
     * and instalments on request.
     *
     * @param  array<string, mixed>  $filters
     */
    public function payments(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Payment::query()
            ->whereNotNull('payments.requisition_id')
            ->with(['requisition:id,requisition_number,purpose,total_amount,approved_at,user_id,responsible_verifier_id,project_id,enquiry_id,project_name',
                'requisition.requester:id,name', 'requisition.responsibleVerifier:id,name',
                'requisitionAllocations.item:id,description,amount', 'paymentSource:id,name,type', 'voidedBy:id,name', 'creator:id,name'])
            // Report 75R-B: whether it was confirmed as received, and how much of it is accounted for.
            ->with(['receiptConfirmation.confirmedBy:id,name'])
            ->withSum(['surrenderAllocations as accounted_amount' => fn ($q) => $q->whereHas('surrender', fn ($s) => $s->where('status', 'reconciled'))], 'amount')
            ->withSum(['surrenderAllocations as returned_amount' => fn ($q) => $q->where('kind', 'return')->whereHas('surrender', fn ($s) => $s->where('status', 'reconciled'))], 'amount');

        $requisition = fn (callable $where) => $query->whereHas('requisition', $where);

        if (filled($filters['requisition_id'] ?? null)) {
            $query->where('payments.requisition_id', (int) $filters['requisition_id']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('payments.requisition_child_reference', 'like', $term)
                ->orWhere('payments.payment_no', 'like', $term)
                ->orWhere('payments.payee_name', 'like', $term)
                ->orWhere('payments.external_reference', 'like', $term)
                ->orWhereHas('requisition', fn (Builder $r) => $r->where('requisition_number', 'like', $term)));
        }
        if (filled($filters['receiver_type'] ?? null)) {
            $query->where('payments.payee_type', $filters['receiver_type']);
        }
        if (filled($filters['receiver_id'] ?? null)) {
            $query->where('payments.payee_id', (int) $filters['receiver_id']);
        }
        if (filled($filters['status'] ?? null)) {
            // "reversed" is the business word for a voided Payment.
            $query->where('payments.status', $filters['status'] === 'reversed' ? 'voided' : $filters['status']);
        }
        if (filled($filters['receipt'] ?? null)) {
            $filters['receipt'] === 'confirmed' ? $query->whereHas('receiptConfirmation') : $query->whereDoesntHave('receiptConfirmation')->where('payments.status', 'active');
        }
        if (! empty($filters['instalments'])) {
            // More than one Payment to the same receiver of the same requisition.
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('requisition_payment_allocations as mine')
                ->join('requisition_payment_allocations as other', fn ($join) => $join
                    ->on('other.requisition_id', '=', 'mine.requisition_id')
                    ->on('other.receiver_type', '=', 'mine.receiver_type')
                    ->on('other.receiver_identity', '=', 'mine.receiver_identity')
                    ->on('other.payment_id', '<>', 'mine.payment_id'))
                ->whereColumn('mine.payment_id', 'payments.id'));
        }
        foreach (['project_id' => 'project_id', 'enquiry_id' => 'enquiry_id', 'requester_id' => 'user_id',
            'verifier_id' => 'responsible_verifier_id'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $requisition(fn (Builder $r) => $r->where($column, (int) $filters[$filter]));
            }
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('payments.date_disbursed', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('payments.date_disbursed', '<=', $filters['to']);
        }

        return $query->orderByDesc('payments.id')->paginate($perPage)->through(fn (Payment $p) => [
            'id' => $p->id,
            'reference' => $p->payment_no,
            'child_reference' => $p->requisition_child_reference,
            'requisition' => $p->requisition?->only(['id', 'requisition_number', 'purpose', 'project_name']),
            'requester' => $p->requisition?->requester?->name,
            'verifier' => $p->requisition?->responsibleVerifier?->name,
            'receiver' => ['type' => $p->payee_type, 'id' => $p->payee_id, 'name' => $p->payee_name],
            'amount' => (string) $p->amount,
            'status' => $p->status === 'voided' ? 'reversed' : $p->status,
            'date' => $p->date_disbursed?->toDateString(),
            'method' => $p->payment_method,
            'source' => $p->paymentSource?->only(['id', 'name']),
            'external_reference' => $p->external_reference,
            'paid_by' => $p->creator?->name,
            'receipt' => $p->receiptConfirmation ? [
                'confirmed_at' => $p->receiptConfirmation->confirmed_at?->toIso8601String(),
                'confirmed_by' => $p->receiptConfirmation->confirmedBy?->name, 'basis' => $p->receiptConfirmation->basis,
                'evidence_reference' => $p->receiptConfirmation->evidence_reference,
            ] : null,
            'accounted' => $p->status === 'active' ? number_format((float) $p->accounted_amount, 2, '.', '') : null,
            'returned' => $p->status === 'active' ? number_format((float) $p->returned_amount, 2, '.', '') : null,
            'to_account' => $p->status === 'active' ? number_format((float) $p->amount - (float) $p->accounted_amount, 2, '.', '') : null,
            'lines' => $p->requisitionAllocations->map(fn ($a) => [
                'item_id' => $a->requisition_item_id, 'purpose' => $a->item?->description, 'amount' => (string) $a->allocated_amount,
            ])->values(),
            'reversal' => $p->status === 'voided' ? [
                'at' => $p->voided_at?->toIso8601String(), 'by' => $p->voidedBy?->name, 'reason' => $p->void_reason,
            ] : null,
        ]);
    }

    /**
     * Approved against disbursed, requisition by requisition, with each
     * receiver's outstanding balance. Only requisitions with money still to pay
     * unless a status is asked for.
     *
     * @param  array<string, mixed>  $filters
     */
    public function receiverBalances(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = PettyCashRequisition::query()->whereNotNull('approved_at');
        $this->whereDisbursementStatus($query, (string) ($filters['disbursement_status'] ?? ''));
        if (blank($filters['disbursement_status'] ?? null)) {
            // Report 75R-B: everything still open — to pay, to confirm, to account for or to close.
            empty($filters['include_closed'])
                ? $query->whereNull('closed_at')->whereIn('status', ['approved', 'disbursed'])
                : $query->whereIn('status', ['approved', 'disbursed', 'surrendered']);
        }
        foreach (['project_id' => 'project_id', 'enquiry_id' => 'enquiry_id', 'requester_id' => 'user_id',
            'verifier_id' => 'responsible_verifier_id'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($column, (int) $filters[$filter]);
            }
        }

        $projection = app(RequisitionControlProjection::class);

        return $query->orderByDesc('id')->paginate($perPage)->through(function (PettyCashRequisition $r) use ($projection) {
            $controls = $projection->forRequisition($r);

            return [
                'id' => $r->id,
                'reference' => $r->requisition_number,
                'purpose' => $r->purpose,
                'project' => $r->project_name,
                'requester' => $r->requester?->name,
                'verifier' => $r->responsibleVerifier?->name,
                'approved' => $controls['approved'],
                'disbursed' => $controls['disbursed'],
                'outstanding' => $controls['outstanding'],
                'disbursement_status' => $controls['disbursement_status'],
                // Report 75R-B: the rest of the life, and what is holding up closure.
                'confirmed_received' => $controls['confirmed_received'], 'accounted' => $controls['accounted'],
                'returned' => $controls['returned'], 'released_unused' => $controls['released_unused'],
                'to_account' => $controls['to_account'], 'control_state' => $controls['control_state'],
                'closure_blockers' => $controls['closure_blockers'],
                'approved_by' => $r->approver?->name, 'verified_by' => $r->verifiedBy?->name,
                'closed' => $controls['closed'],
                'receivers' => collect($controls['receivers'])->map(fn (array $receiver) => [
                    'type' => $receiver['receiver_type'], 'id' => $receiver['receiver_id'], 'name' => $receiver['name'],
                    'approved' => $receiver['approved'], 'paid' => $receiver['paid'], 'outstanding' => $receiver['outstanding'],
                    'status' => $receiver['payment_allocation_status'],
                    'confirmed' => $receiver['confirmed'], 'accounted' => $receiver['accepted'], 'returned' => $receiver['returned'],
                    'released' => $receiver['released'], 'to_account' => $receiver['to_account'],
                    'accountability_state' => $receiver['accountability_state'],
                ])->values(),
            ];
        });
    }
}
