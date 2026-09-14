<?php

namespace App\Modules\Finance\Services;

use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\FinanceWorkAssignment;
use App\Modules\Finance\Models\FinanceWorkAssignmentEvent;
use App\Modules\Finance\PettyCash\Models\DirectDisbursementRequest;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A read-only projection of work Finance can perform in existing workflows.
 * Source records remain authoritative; this service never approves anything.
 */
class FinanceWorkQueueService
{
    public function countsForUser(User $user): array
    {
        $byType = [];
        if ($user->can(Permissions::FINANCE_COSTS_VERIFY)) $byType['cost_verification'] = CostLine::query()->where('nature', '!=', CostLine::NATURE_PLANNED)->where('status', CostLine::STATUS_SUBMITTED)->count();
        if ($user->can(Permissions::PROCUREMENT_REQUISITIONS_APPROVE)) $byType['purchase_requisition'] = Requisition::query()->where('status', 'pending_approval')->count();
        if ($user->can(Permissions::PROCUREMENT_ORDERS_APPROVE)) $byType['purchase_order'] = PurchaseOrder::query()->where('status', 'pending_approval')->count();
        if ($this->canVerifySupplierBills($user)) $byType['supplier_invoice'] = Bill::query()->whereNull('verified_at')->whereNotIn('status', ['paid', 'cancelled'])->count();
        if ($user->can(Permissions::FINANCE_PETTY_CASH_UPDATE)) $byType['fund_requisition'] = PettyCashRequisition::query()->where('status', 'pending')->count();
        if ($user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE)) $byType['direct_disbursement'] = DirectDisbursementRequest::query()->where('status', 'pending_approval')->count();
        if ($user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE)) $byType['spend_voucher'] = SpendVoucher::query()->where('status', 'pending_approval')->count();
        if ($user->can(Permissions::FINANCE_RECEIVABLES_VERIFY)) $byType['client_receipt'] = EnquiryPayment::query()->where('status', 'pending')->whereNull('reversed_at')->count();

        return ['total' => array_sum($byType), 'by_type' => $byType];
    }

    public function forUser(User $user, array $filters = []): array
    {
        $items = collect();

        if ($user->can(Permissions::FINANCE_COSTS_VERIFY)) {
            $this->costs($items);
        }
        if ($user->can(Permissions::PROCUREMENT_REQUISITIONS_APPROVE)) {
            $this->purchaseRequisitions($items);
        }
        if ($user->can(Permissions::PROCUREMENT_ORDERS_APPROVE)) {
            $this->purchaseOrders($items);
        }
        if ($this->canVerifySupplierBills($user)) {
            $this->supplierBills($items);
        }
        if ($user->can(Permissions::FINANCE_PETTY_CASH_UPDATE)) {
            $this->fundRequisitions($items);
        }
        if ($user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE)) {
            $this->directDisbursements($items);
        }
        if ($user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE)) {
            $this->spendVouchers($items);
        }
        if ($user->can(Permissions::FINANCE_RECEIVABLES_VERIFY)) {
            $this->clientReceipts($items);
        }

        $assignments = FinanceWorkAssignment::query()->with('assignee:id,name')
            ->whereIn('work_type', $items->pluck('work_type')->unique())
            ->whereIn('source_id', $items->pluck('source_id')->unique())->get()
            ->keyBy(fn (FinanceWorkAssignment $assignment) => "{$assignment->work_type}:{$assignment->source_id}");

        $items = $items->map(function (array $item) use ($assignments) {
            $assignment = $assignments->get($item['key']);
            $item['assignment'] = $assignment ? [
                'assigned_to' => $assignment->assigned_to,
                'assignee_name' => $assignment->assignee?->name,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            ] : null;
            return $item;
        });

        $sorted = $items->sortBy([
            ['priority_rank', 'asc'],
            ['submitted_at', 'asc'],
        ])->values();

        $filtered = $sorted
            ->when(! empty($filters['work_type']), fn (Collection $rows) => $rows->where('work_type', $filters['work_type']))
            ->when(! empty($filters['priority']), function (Collection $rows) use ($filters) {
                return $filters['priority'] === 'exception'
                    ? $rows->whereIn('priority', ['watch', 'overdue'])
                    : $rows->where('priority', $filters['priority']);
            })
            ->when(! empty($filters['search']), function (Collection $rows) use ($filters) {
                $needle = mb_strtolower(trim($filters['search']));
                return $rows->filter(fn (array $item) => str_contains(mb_strtolower(implode(' ', [
                    $item['type_label'], $item['reference'], $item['counterparty'], $item['context'] ?? '',
                    $item['required_action'],
                ])), $needle));
            })
            ->when(($filters['assignment'] ?? 'all') === 'mine', fn (Collection $rows) => $rows->filter(
                fn (array $item) => ($item['assignment']['assigned_to'] ?? null) === ($filters['user_id'] ?? null)
            ))
            ->when(($filters['assignment'] ?? 'all') === 'unassigned', fn (Collection $rows) => $rows->whereNull('assignment'))
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        $pageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pageItems->map(fn (array $item) => collect($item)->except('priority_rank')->all())->all(),
            'summary' => [
                'total' => $sorted->count(),
                'overdue' => $sorted->where('priority', 'overdue')->count(),
                'exceptions' => $sorted->whereIn('priority', ['watch', 'overdue'])->count(),
                'by_type' => $sorted->countBy('work_type')->all(),
            ],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($filtered->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $filtered->count(),
                'from' => $filtered->isEmpty() ? null : (($page - 1) * $perPage) + 1,
                'to' => $filtered->isEmpty() ? null : min($page * $perPage, $filtered->count()),
            ],
        ];
    }

    public function claim(User $user, string $workType, int $sourceId): array
    {
        abort_unless($this->mayAccessType($user, $workType), 403);

        return DB::transaction(function () use ($user, $workType, $sourceId) {
            $existing = FinanceWorkAssignment::query()->where([
                'work_type' => $workType, 'source_id' => $sourceId,
            ])->lockForUpdate()->first();
            abort_if($existing && $existing->assigned_to !== $user->id, 409, 'This item has already been claimed.');

            $assignment = $existing ?: FinanceWorkAssignment::create([
                'work_type' => $workType, 'source_id' => $sourceId,
                'assigned_to' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now(),
            ]);

            if (! $existing) {
                FinanceWorkAssignmentEvent::create([
                    'work_type' => $workType, 'source_id' => $sourceId, 'event' => 'claimed',
                    'to_user_id' => $user->id, 'actor_id' => $user->id,
                ]);
            }

            return $assignment->load('assignee:id,name')->toArray();
        });
    }

    public function release(User $user, string $workType, int $sourceId): void
    {
        $assignment = FinanceWorkAssignment::query()->where([
            'work_type' => $workType, 'source_id' => $sourceId,
        ])->firstOrFail();
        abort_unless($assignment->assigned_to === $user->id || $user->hasAnyRole(['Super Admin', 'Admin']), 403);
        DB::transaction(function () use ($assignment, $user, $workType, $sourceId): void {
            FinanceWorkAssignmentEvent::create([
                'work_type' => $workType, 'source_id' => $sourceId, 'event' => 'released',
                'from_user_id' => $assignment->assigned_to, 'actor_id' => $user->id,
            ]);
            $assignment->delete();
        });
    }

    public function reassign(User $actor, string $workType, int $sourceId, int $assigneeId, ?string $note): array
    {
        abort_unless($actor->hasAnyRole(['Super Admin', 'Admin']), 403);
        $assignee = User::query()->where('is_active', true)->findOrFail($assigneeId);
        abort_unless($this->mayAccessType($assignee, $workType), 422, 'The selected officer is not eligible for this work type.');

        return DB::transaction(function () use ($actor, $assignee, $workType, $sourceId, $note) {
            $assignment = FinanceWorkAssignment::query()->where([
                'work_type' => $workType, 'source_id' => $sourceId,
            ])->lockForUpdate()->first();
            $from = $assignment?->assigned_to;
            $assignment ??= new FinanceWorkAssignment(['work_type' => $workType, 'source_id' => $sourceId]);
            $assignment->fill([
                'assigned_to' => $assignee->id, 'assigned_by' => $actor->id, 'assigned_at' => now(),
            ])->save();

            FinanceWorkAssignmentEvent::create([
                'work_type' => $workType, 'source_id' => $sourceId,
                'event' => $from ? 'reassigned' : 'claimed', 'from_user_id' => $from,
                'to_user_id' => $assignee->id, 'actor_id' => $actor->id, 'note' => $note,
            ]);

            return $assignment->load('assignee:id,name')->toArray();
        });
    }

    public function history(User $user, string $workType, int $sourceId): array
    {
        abort_unless($this->mayAccessType($user, $workType) || $user->hasAnyRole(['Super Admin', 'Admin']), 403);

        return FinanceWorkAssignmentEvent::query()
            ->with(['fromUser:id,name', 'toUser:id,name', 'actor:id,name'])
            ->where(['work_type' => $workType, 'source_id' => $sourceId])
            ->oldest()->get()->toArray();
    }

    private function mayAccessType(User $user, string $type): bool
    {
        return match ($type) {
            'cost_verification' => $user->can(Permissions::FINANCE_COSTS_VERIFY),
            'purchase_requisition' => $user->can(Permissions::PROCUREMENT_REQUISITIONS_APPROVE),
            'purchase_order' => $user->can(Permissions::PROCUREMENT_ORDERS_APPROVE),
            'supplier_invoice' => $this->canVerifySupplierBills($user),
            'fund_requisition' => $user->can(Permissions::FINANCE_PETTY_CASH_UPDATE),
            'direct_disbursement' => $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE),
            'spend_voucher' => $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE),
            'client_receipt' => $user->can(Permissions::FINANCE_RECEIVABLES_VERIFY),
            default => false,
        };
    }

    private function costs(Collection $items): void
    {
        CostLine::query()->where('nature', '!=', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_SUBMITTED)->oldest('incurred_at')->limit(100)
            ->get()->each(fn (CostLine $cost) => $items->push($this->item(
                'cost_verification', 'Cost verification', $cost->id, $cost->ref ?: "COST-{$cost->id}",
                $cost->payee_name ?: 'Unspecified payee', $cost->net_amount, $cost->currency ?: 'KES',
                $cost->incurred_at ?? $cost->created_at, 'Verify cost', "/finance/costs/verification?cost={$cost->ref}",
                $cost->job_number,
            )));
    }

    private function purchaseRequisitions(Collection $items): void
    {
        Requisition::query()->where('status', 'pending_approval')->oldest('submitted_at')->limit(100)
            ->get()->each(fn (Requisition $request) => $items->push($this->item(
                'purchase_requisition', 'Purchase requisition', $request->id, $request->requisition_number,
                'Internal request', $request->total_amount, 'KES', $request->submitted_at ?? $request->created_at,
                'Approve purchase', "/procurement/requisition/{$request->id}", $request->job_number,
            )));
    }

    private function purchaseOrders(Collection $items): void
    {
        PurchaseOrder::query()->with('supplier')->where('status', 'pending_approval')->oldest()->limit(100)
            ->get()->each(fn (PurchaseOrder $order) => $items->push($this->item(
                'purchase_order', 'Purchase order exception', $order->id, $order->po_number ?? "PO-{$order->id}",
                $order->supplier?->name ?? 'Supplier', $order->total_amount ?? 0, 'KES', $order->created_at,
                'Approve order', "/procurement/purchase-order/{$order->id}", null,
            )));
    }

    private function supplierBills(Collection $items): void
    {
        Bill::query()->whereNull('verified_at')->whereNotIn('status', ['paid', 'cancelled'])
            ->oldest('bill_date')->limit(100)->get()
            ->each(fn (Bill $bill) => $items->push($this->item(
                'supplier_invoice', 'Supplier invoice', $bill->id, $bill->bill_number,
                'Supplier', $bill->amount, 'KES', $bill->bill_date ?? $bill->created_at,
                'Run three-way check', "/procurement/billing/{$bill->id}", null,
            )));
    }

    private function fundRequisitions(Collection $items): void
    {
        PettyCashRequisition::query()->where('status', 'pending')->oldest()->limit(100)->get()
            ->each(fn (PettyCashRequisition $request) => $items->push($this->item(
                'fund_requisition', 'Fund requisition', $request->id, $request->requisition_number,
                $request->payee_name ?: $request->requester_name ?: 'Internal requester', $request->total_amount,
                'KES', $request->created_at, 'Review request', "/finance/petty-cash/requisitions/{$request->id}",
                $request->project_name,
            )));
    }

    private function directDisbursements(Collection $items): void
    {
        DirectDisbursementRequest::query()->where('status', 'pending_approval')->oldest()->limit(100)->get()
            ->each(function (DirectDisbursementRequest $request) use ($items): void {
                $payload = $request->payload ?? [];
                $items->push($this->item(
                    'direct_disbursement', 'Direct disbursement', $request->id,
                    $payload['payment_no'] ?? "DIRECT-{$request->id}", $payload['payee_name'] ?? 'Payee',
                    $payload['amount'] ?? 0, $payload['currency'] ?? 'KES', $request->created_at,
                    'Approve disbursement', "/finance/petty-cash?direct_request={$request->id}#direct-request-{$request->id}", $payload['project_name'] ?? null,
                ));
            });
    }

    private function spendVouchers(Collection $items): void
    {
        SpendVoucher::query()->where('status', 'pending_approval')->oldest()->limit(100)->get()
            ->each(fn (SpendVoucher $voucher) => $items->push($this->item(
                'spend_voucher', 'Spend voucher', $voucher->id, $voucher->voucher_no,
                $voucher->payee_name ?: 'Payee', $voucher->total_amount, $voucher->currency ?: 'KES',
                $voucher->created_at, 'Approve voucher', "/finance/spend-vouchers?voucher={$voucher->voucher_no}", null,
            )));
    }

    private function clientReceipts(Collection $items): void
    {
        EnquiryPayment::query()->where('status', 'pending')->whereNull('reversed_at')
            ->oldest('payment_date')->limit(100)->get()
            ->each(fn (EnquiryPayment $receipt) => $items->push($this->item(
                'client_receipt', 'Client receipt', $receipt->id,
                $receipt->transaction_reference ?: "RECEIPT-{$receipt->id}", 'Client', $receipt->amount,
                'KES', $receipt->payment_date ?? $receipt->created_at, 'Verify receipt',
                "/finance/project-receivables?enquiry={$receipt->project_enquiry_id}", null,
            )));
    }

    private function item(string $workType, string $label, int $id, ?string $reference, string $counterparty,
        mixed $amount, string $currency, mixed $submittedAt, string $action, string $targetUrl, ?string $context): array
    {
        $date = $submittedAt ? \Illuminate\Support\Carbon::parse($submittedAt) : now();
        $age = (int) $date->diffInDays(now());

        return [
            'key' => "{$workType}:{$id}", 'work_type' => $workType, 'type_label' => $label,
            'source_id' => $id, 'reference' => $reference ?: "#{$id}", 'counterparty' => $counterparty,
            'amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency,
            'submitted_at' => $date->toIso8601String(), 'age_days' => $age,
            'priority' => $age > 30 ? 'overdue' : ($age > 7 ? 'watch' : 'normal'),
            'priority_rank' => $age > 30 ? 0 : ($age > 7 ? 1 : 2),
            'required_action' => $action, 'target_url' => $targetUrl, 'context' => $context,
        ];
    }

    private function canVerifySupplierBills(User $user): bool
    {
        return $user->roles()->whereIn('name', ['Super Admin', 'Admin', 'Accounts'])->exists();
    }
}
