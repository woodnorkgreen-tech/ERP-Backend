<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\GovernanceAuditLog;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\PayablesActions;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Services\PayablesAgeingService;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * W2 read projections for the Finance Purchasing & payables workspace
 * (Report 60). Read-only: every change still goes through the Procurement
 * bill routes (verify, return-for-correction, correct, record-payment), which
 * stay authoritative.
 *
 * Amounts are the Bill model's own (gross `amount`, `net_amount`, `vat_amount`,
 * `wht_amount`, `payableAmount()`, `paid_amount`, `balance`); the match, the
 * staged-billing position and payability are PurchaseOrderWorkflow::bill(),
 * the same answer the payment gate enforces. People appear as `{id, name}`.
 */
class PayablesController extends Controller
{
    public function __construct(private PurchaseOrderWorkflow $workflow)
    {
    }

    /** GET api/finance/payables/bills */
    public function bills(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer'],
            'enquiry_id' => ['nullable', 'integer'],
            'project_officer_id' => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'verification' => ['nullable', 'in:awaiting_verification,returned_for_correction,verified'],
            'payment' => ['nullable', 'in:unpaid,partially_paid,paid'],
            'wht' => ['nullable', 'in:with,without'],
            'overdue' => ['nullable', 'boolean'],
            'bill_date_from' => ['nullable', 'date'],
            'bill_date_to' => ['nullable', 'date'],
            'due_date_from' => ['nullable', 'date'],
            'due_date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = $this->filtered(Bill::query()->select('bills.*'), $filters)
            ->with($this->workflowRelations())
            ->orderByDesc('bill_date')->orderByDesc('id');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));
        $bills = collect($page->items());
        $projects = $this->projectsFor($bills);
        $names = $this->names($bills->flatMap(fn (Bill $b) => [$b->user_id, $b->verified_by, $b->returned_by])
            ->merge($projects->pluck('project_officer_id')));

        return response()->json([
            'data' => $bills->map(fn (Bill $bill) => $this->row($request->user(), $bill, $projects, $names))->values(),
            'summary' => $this->summary(),
            'meta' => $this->meta($page),
        ]);
    }

    /** GET api/finance/payables/bills/{bill} */
    public function bill(Request $request, int $bill): JsonResponse
    {
        $this->authoriseRead($request);
        $model = Bill::query()->with([
            ...$this->workflowRelations(),
            'purchaseOrder.requisition',
            'purchaseOrder.amendments',
            'purchaseOrder.goodsReceiptNotes.items.purchaseOrderItem.material',
            'purchaseOrder.goodsReceiptNotes.items.inspection',
            'vatTreatment:id,code,name,rate_percent,is_recoverable',
            'whtCategory',
            'expenseCode:id,code,simple_meaning',
            'payments.paymentSource:id,code,name,type',
            'payments.disbursement:id,status',
        ])->findOrFail($bill);

        $state = $this->workflow->bill($model);
        $order = $model->purchaseOrder;
        $projects = $this->projectsFor(collect([$model]));
        $audit = GovernanceAuditLog::query()->where('model_type', Bill::class)->where('model_id', $model->id)
            ->orderBy('created_at')->orderBy('id')->get(['id', 'user_id', 'gate_type', 'message', 'context', 'created_at']);
        $journal = JournalEntry::query()->where('source_type', Bill::class)->where('source_id', $model->id)
            ->orderBy('id')->get(['id', 'entry_no', 'status', 'posting_date', 'created_by', 'created_at']);
        $amendments = $order?->amendments ?? collect();
        $receipts = $order?->goodsReceiptNotes ?? collect();

        $names = $this->names(collect([
            $model->user_id, $model->verified_by, $model->returned_by, $model->duplicate_override_by,
            $order?->user_id, $order?->approved_by, $order?->senior_approved_by, $order?->returned_by,
            $order?->requisition?->approved_by, $order?->requisition?->user_id,
        ])->merge($projects->pluck('project_officer_id'))->merge($audit->pluck('user_id'))->merge($journal->pluck('created_by'))
            ->merge($model->payments->pluck('user_id'))->merge($model->payments->pluck('duplicate_override_by'))
            ->merge($amendments->flatMap(fn ($a) => [$a->requested_by, $a->approved_by, $a->rejected_by]))
            ->merge($receipts->pluck('received_by'))
            ->merge($receipts->flatMap(fn ($r) => $r->items->pluck('confirmed_by'))));

        $whtAccount = $this->whtLiabilityAccount($model->whtCategory);

        return response()->json(['data' => array_merge($this->row($request->user(), $model, $projects, $names, $state), [
            'notes' => $model->notes,
            'etims_invoice_no' => $model->etims_invoice_no,
            'supplier_pin' => $model->supplier_pin,
            'tax_point_date' => $model->tax_point_date?->toDateString(),
            'expense_code' => $model->expenseCode?->only(['id', 'code', 'simple_meaning']),
            'procurement' => $order ? [
                'requisition' => $order->requisition ? [
                    'id' => $order->requisition->id,
                    'number' => $order->requisition->requisition_number,
                    'status' => $order->requisition->status,
                    'total' => $this->money($order->requisition->total_amount),
                    'requested_by' => $this->person($names, $order->requisition->user_id),
                    'approved_by' => $this->person($names, $order->requisition->approved_by),
                    'approved_at' => $this->iso($order->requisition->approved_at),
                ] : null,
                'purchase_order' => [
                    'id' => $order->id,
                    'number' => $order->po_number,
                    'status' => $order->status,
                    'total' => $this->money($order->total_amount),
                    'date' => $order->date?->toDateString(),
                    'raised_by' => $this->person($names, $order->user_id),
                    'approved_by' => $this->person($names, $order->approved_by),
                    'approved_at' => $order->approved_at?->toIso8601String(),
                    'senior_approval_required' => (bool) $order->senior_approval_required,
                    // W2-1: the WNG-configured threshold, or null while it is unset
                    // (the senior tier then applies to nothing). Never a default.
                    'senior_approval_threshold' => ($threshold = \App\Modules\Finance\Models\FinanceSetting::approvedValue('purchase_order_senior_approval_threshold')) !== null
                        && is_numeric($threshold) ? $this->money($threshold) : null,
                    'senior_approved_by' => $this->person($names, $order->senior_approved_by),
                    'senior_approved_at' => $order->senior_approved_at?->toIso8601String(),
                    'returned_by' => $this->person($names, $order->returned_by),
                    'returned_at' => $order->returned_at?->toIso8601String(),
                    'return_reason' => $order->return_reason,
                    'pending_commercial_amendment' => $order->hasPendingCommercialAmendment(),
                ],
                // W2-4: the original terms are never overwritten; each amendment
                // carries its own before/after and decision.
                'amendments' => $amendments->sortBy('amendment_number')->map(fn ($a) => [
                    'number' => $a->amendment_number,
                    'status' => $a->status,
                    'is_commercial' => (bool) $a->is_commercial,
                    'reason' => $a->reason,
                    'changed_fields' => $a->changed_fields,
                    'original_total' => isset($a->original_snapshot['total_amount']) ? $this->money($a->original_snapshot['total_amount']) : null,
                    'proposed_total' => isset($a->proposed_snapshot['total_amount']) ? $this->money($a->proposed_snapshot['total_amount']) : null,
                    'requested_by' => $this->person($names, $a->requested_by),
                    'requested_at' => $this->iso($a->requested_at),
                    'approved_by' => $this->person($names, $a->approved_by),
                    'approved_at' => $this->iso($a->approved_at),
                    'rejected_by' => $this->person($names, $a->rejected_by),
                    'rejected_at' => $this->iso($a->rejected_at),
                    'rejection_reason' => $a->rejection_reason,
                ])->values(),
                'receipts' => $receipts->sortBy('id')->map(fn ($grn) => [
                    'id' => $grn->id,
                    'number' => $grn->grn_number,
                    'date' => $grn->date ? \Illuminate\Support\Carbon::parse($grn->date)->toDateString() : null,
                    'received_by' => $this->person($names, $grn->received_by),
                    'store_status' => $grn->store_status,
                    'items' => $grn->items->map(fn (GoodsReceiptNoteItem $item) => [
                        'name' => $item->purchaseOrderItem?->material?->material_name ?? $item->purchaseOrderItem?->custom_description ?? 'Item',
                        'ordered' => (string) $item->ordered_quantity,
                        'received' => (string) $item->received_quantity,
                        'accepted' => (bool) $item->accepted,
                        'accepted_quantity' => $item->inspection ? (string) $item->inspection->accepted_quantity : null,
                        'condition' => $item->condition,
                        'store_status' => $item->store_status,
                        'confirmed_by' => $this->person($names, $item->confirmed_by),
                        'confirmed_at' => $this->iso($item->confirmed_at),
                    ])->values(),
                ])->values(),
            ] : null,
            // No service-confirmation mechanism exists (W2-8, open policy):
            // services go through the same goods receipt as goods.
            'service_confirmation' => null,
            'match' => [
                'is_direct' => (bool) ($state['is_direct'] ?? $model->isDirect()),
                'checks' => $state['checks'] ?? [],
                'lines' => $state['items'] ?? [],
                'order_total' => $state['order_total'] ?? null,
                'accepted_value' => $state['accepted_value'] ?? null,
                'billing_position' => $state['billing_position'] ?? null,
                'eligible_for_verification' => (bool) ($state['eligible_for_verification'] ?? false),
                'verified' => (bool) ($state['verified'] ?? false),
                'verification_basis' => $state['verification_basis'] ?? null,
                'can_pay' => (bool) ($state['can_pay'] ?? false),
                'blockers' => $state['blockers'] ?? [],
                'stage' => $state['stage'] ?? null,
                'stages' => $state['stages'] ?? [],
                'owner' => $state['owner'] ?? null,
                'next_action_text' => $state['next_action'] ?? null,
            ],
            'tax' => [
                'gross' => $this->money($model->amount),
                'net' => $this->money($model->net_amount ?? $model->amount),
                'vat' => $this->money($model->vat_amount),
                'wht' => $this->money($model->wht_amount),
                'payable' => $model->payableAmount(),
                'vat_treatment' => $model->vatTreatment ? [
                    'name' => $model->vatTreatment->name, 'rate_percent' => (string) $model->vatTreatment->rate_percent,
                    'recoverable' => (bool) $model->vatTreatment->is_recoverable,
                ] : null,
                'wht_category' => $model->whtCategory ? [
                    'code' => $model->whtCategory->code, 'name' => $model->whtCategory->name,
                    'rate_percent' => (string) $model->whtCategory->rate_percent,
                ] : null,
                // Where the withheld amount is owed (to KRA) — never a discount.
                'wht_liability_account' => bccomp($this->money($model->wht_amount), '0.00', 2) > 0 ? $whtAccount : null,
            ],
            'controls' => [
                'prepared_by' => $this->person($names, $model->user_id),
                'prepared_at' => $this->iso($model->created_at),
                'returned_by' => $this->person($names, $model->returned_by),
                'returned_at' => $this->iso($model->returned_at),
                'return_reason' => $model->return_reason,
                'resubmitted_at' => $this->iso($model->resubmitted_at),
                'verified_by' => $this->person($names, $model->verified_by),
                'verified_at' => $this->iso($model->verified_at),
                'verification_notes' => $model->verification_notes,
                'duplicate_override' => $model->duplicate_of_bill_id ? [
                    'of_bill' => Bill::whereKey($model->duplicate_of_bill_id)->value('bill_number'),
                    'reason' => $model->duplicate_override_reason,
                    'by' => $this->person($names, $model->duplicate_override_by),
                    'at' => $this->iso($model->duplicate_override_at),
                ] : null,
                'postings' => $journal->map(fn (JournalEntry $entry) => [
                    'entry_no' => $entry->entry_no, 'status' => $entry->status,
                    'posting_date' => $this->iso($entry->posting_date), 'by' => $this->person($names, $entry->created_by),
                ])->values(),
            ],
            'payments' => $model->payments->sortBy('id')->map(fn (BillPayment $payment) => [
                'id' => $payment->id,
                'code' => $payment->payment_code,
                'date' => $payment->payment_date?->toDateString(),
                'amount' => $this->money($payment->amount_paid),
                'method' => $payment->payment_method,
                'source' => $payment->paymentSource?->only(['id', 'code', 'name', 'type']),
                'reference' => $payment->reference_number,
                'recorded_by' => $this->person($names, $payment->user_id),
                'recorded_at' => $this->iso($payment->created_at),
                'status' => $payment->disbursement_id && $payment->disbursement?->status !== 'active' ? 'reversed' : 'active',
                'duplicate_override' => $payment->duplicate_of_payment_id ? [
                    'reason' => $payment->duplicate_override_reason,
                    'by' => $this->person($names, $payment->duplicate_override_by),
                    'at' => $this->iso($payment->duplicate_override_at),
                ] : null,
            ])->values(),
            // The project cost this bill is behind — and the proof a payment
            // adds none: a direct bill's ACTUAL line, or the receipt accruals a
            // PO bill settled. Payments never appear here.
            'project_cost' => $this->costFor($model),
            'audit' => $audit->map(fn (GovernanceAuditLog $log) => [
                'id' => $log->id, 'event' => $log->gate_type, 'message' => $log->message,
                'reason' => is_array($log->context) ? ($log->context['reason'] ?? null) : null,
                'by' => $this->person($names, $log->user_id), 'at' => $this->iso($log->created_at),
            ])->values(),
        ])]);
    }

    /** GET api/finance/payables/payments */
    public function payments(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer'],
            'payment_source_id' => ['nullable', 'integer'],
            'bill_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $page = BillPayment::query()
            ->with(['bill:id,bill_number,supplier_invoice_number,supplier_id,amount,wht_amount', 'bill.supplier:id,supplier_name',
                'paymentSource:id,code,name,type', 'disbursement:id,status'])
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('bill', fn (Builder $b) => $b->where('supplier_id', $id)))
            ->when($filters['payment_source_id'] ?? null, fn (Builder $q, $id) => $q->where('payment_source_id', $id))
            ->when($filters['bill_id'] ?? null, fn (Builder $q, $id) => $q->where('bill_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('payment_code', 'like', $like)->orWhere('reference_number', 'like', $like)
                    ->orWhereHas('bill', fn (Builder $b) => $b->where('bill_number', 'like', $like)->orWhere('supplier_invoice_number', 'like', $like)
                        ->orWhereHas('supplier', fn (Builder $s) => $s->where('supplier_name', 'like', $like))));
            })
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $payments = collect($page->items());
        $names = $this->names($payments->flatMap(fn (BillPayment $p) => [$p->user_id, $p->duplicate_override_by]));

        return response()->json([
            'data' => $payments->map(fn (BillPayment $p) => [
                'id' => $p->id,
                'code' => $p->payment_code,
                'date' => $p->payment_date?->toDateString(),
                'amount' => $this->money($p->amount_paid),
                'method' => $p->payment_method,
                'source' => $p->paymentSource?->only(['id', 'code', 'name', 'type']),
                'reference' => $p->reference_number,
                'bill' => $p->bill ? ['id' => $p->bill->id, 'number' => $p->bill->bill_number, 'supplier_invoice_number' => $p->bill->supplier_invoice_number] : null,
                'supplier' => $p->bill?->supplier ? ['id' => $p->bill->supplier->id, 'name' => $p->bill->supplier->supplier_name] : null,
                'recorded_by' => $this->person($names, $p->user_id),
                'status' => $p->disbursement_id && $p->disbursement?->status !== 'active' ? 'reversed' : 'active',
                'duplicate_override' => $p->duplicate_of_payment_id ? [
                    'reason' => $p->duplicate_override_reason, 'by' => $this->person($names, $p->duplicate_override_by),
                    'at' => $this->iso($p->duplicate_override_at),
                ] : null,
            ])->values(),
            'meta' => $this->meta($page),
        ]);
    }

    /** GET api/finance/payables/position — what WNG owes suppliers, and how old it is. */
    public function position(Request $request, PayablesAgeingService $ageing): JsonResponse
    {
        $this->authoriseRead($request);
        $open = fn () => Bill::query()->whereNotIn('bills.status', ['paid', 'cancelled'])->where('bills.balance', '>', 0);
        $today = now()->toDateString();

        $suppliers = $open()->join('suppliers', 'suppliers.id', '=', 'bills.supplier_id')
            ->groupBy('bills.supplier_id', 'suppliers.supplier_name')
            ->selectRaw('bills.supplier_id, suppliers.supplier_name, count(*) as open_bills, sum(bills.balance) as outstanding,'
                ." sum(case when bills.due_date < ? then bills.balance else 0 end) as overdue,"
                .' sum(case when bills.verified_at is null then bills.balance else 0 end) as unverified', [$today])
            ->orderByDesc('outstanding')->limit(50)->get();
        $ageingSummary = $ageing->summary();

        return response()->json(['data' => [
            'summary' => $this->summary(),
            'ageing' => ['as_of' => $ageingSummary['as_of'], 'buckets' => $ageingSummary['buckets'], 'totals' => $ageingSummary['totals']],
            'suppliers' => $suppliers->map(fn ($s) => [
                'supplier' => ['id' => (int) $s->supplier_id, 'name' => $s->supplier_name],
                'open_bills' => (int) $s->open_bills,
                'outstanding' => $this->money($s->outstanding),
                'overdue' => $this->money($s->overdue),
                'unverified' => $this->money($s->unverified),
            ])->values(),
            'ledger' => [
                'account' => $this->accountFor(FinanceAccountFunctions::ACCOUNTS_PAYABLE),
                // The control account's balance, for reconciliation. It covers
                // only posted invoices; legacy bills predate the rail.
                'balance' => $this->creditBalance($this->accountIdFor(FinanceAccountFunctions::ACCOUNTS_PAYABLE)),
            ],
        ]]);
    }

    /** GET api/finance/payables/wht — withholding retained on supplier invoices, owed to KRA. */
    public function wht(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $base = fn () => Bill::query()->where('wht_amount', '>', 0)
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('bill_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('bill_date', '<=', $d));
        $page = $base()->with(['supplier:id,supplier_name', 'whtCategory'])->orderByDesc('bill_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $account = $this->whtLiabilityAccount(null);

        return response()->json([
            'data' => collect($page->items())->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'supplier_invoice_number' => $bill->supplier_invoice_number,
                'supplier' => $bill->supplier ? ['id' => $bill->supplier->id, 'name' => $bill->supplier->supplier_name] : null,
                'supplier_pin' => $bill->supplier_pin,
                'bill_date' => $bill->bill_date?->toDateString(),
                'gross' => $this->money($bill->amount),
                'wht' => $this->money($bill->wht_amount),
                'payable' => $bill->payableAmount(),
                'category' => $bill->whtCategory ? ['code' => $bill->whtCategory->code, 'name' => $bill->whtCategory->name,
                    'rate_percent' => (string) $bill->whtCategory->rate_percent] : null,
                // Posted only once the bill is verified; before that it is not yet a liability.
                'liability_recognised' => (bool) $bill->verified_at && in_array($bill->verification_basis, ['three_way_match', 'direct'], true),
            ])->values(),
            'summary' => [
                'withheld_on_verified_bills' => $this->money($base()->whereNotNull('verified_at')
                    ->whereIn('verification_basis', ['three_way_match', 'direct'])->sum('wht_amount')),
                'withheld_on_unverified_bills' => $this->money($base()->whereNull('verified_at')->sum('wht_amount')),
                'liability_account' => $account,
                'ledger_balance' => $account ? $this->creditBalance((int) $account['id']) : null,
            ],
            'meta' => $this->meta($page),
        ]);
    }

    // ── Rows and state ───────────────────────────────────────────────────

    private function row(User $user, Bill $bill, Collection $projects, Collection $names, ?array $state = null): array
    {
        $state ??= $this->workflow->bill($bill);
        $project = $projects->get($bill->id);
        $order = $bill->purchaseOrder;
        $actions = PayablesActions::forBill($user, $bill, $state);
        $next = collect(['correct', 'verify', 'pay'])->first(fn (string $key) => $actions[$key]['allowed']);
        $balance = (float) $bill->balance;
        $overdue = $bill->due_date && $balance > 0 && ! in_array($bill->status, ['paid', 'cancelled'], true)
            && $bill->due_date->lt(now()->startOfDay());

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'supplier_invoice_number' => $bill->supplier_invoice_number,
            'supplier' => $bill->supplier ? ['id' => $bill->supplier->id, 'name' => $bill->supplier->supplier_name] : null,
            'project' => $project ? ['id' => $project['id'], 'job_number' => $project['job_number'], 'title' => $project['title']] : null,
            'project_officer' => $project ? $this->person($names, $project['project_officer_id']) : null,
            'purchase_order' => $order ? ['id' => $order->id, 'number' => $order->po_number, 'status' => $order->status] : null,
            'receipts' => $order ? $order->goodsReceiptNotes->map(fn ($grn) => ['id' => $grn->id, 'number' => $grn->grn_number])->values() : [],
            'is_direct' => $bill->isDirect(),
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'currency' => 'KES',
            'gross' => $this->money($bill->amount),
            'vat' => $this->money($bill->vat_amount),
            'wht' => $this->money($bill->wht_amount),
            'payable' => $bill->payableAmount(),
            'paid' => $this->money($bill->paid_amount),
            'balance' => $this->money($bill->balance),
            'status' => $bill->status,
            'verification_state' => $this->verificationState($bill, $state),
            'payment_state' => $this->paymentState($bill),
            'days_overdue' => $overdue ? (int) $bill->due_date->diffInDays(now()->startOfDay()) : 0,
            'prepared_by' => $this->person($names, $bill->user_id),
            'verified_by' => $this->person($names, $bill->verified_by),
            'verified_at' => $this->iso($bill->verified_at),
            'returned_by' => $this->person($names, $bill->returned_by),
            'returned_at' => $this->iso($bill->returned_at),
            'return_reason' => $bill->return_reason,
            'resubmitted_at' => $this->iso($bill->resubmitted_at),
            'duplicate_override' => (bool) $bill->duplicate_of_bill_id,
            'blockers' => $state['blockers'] ?? [],
            'actions' => $actions,
            'next_action' => $next,
        ];
    }

    /** Review state, independent of payment state (§16). */
    private function verificationState(Bill $bill, array $state): string
    {
        return match (true) {
            $bill->awaitingCorrection() => 'returned_for_correction',
            ($state['verification_basis'] ?? null) === 'legacy' => 'legacy',
            (bool) ($state['verified'] ?? false) => 'verified',
            $bill->verified_at !== null => 'verification_withdrawn',
            $bill->resubmitted_at !== null => 'resubmitted',
            default => 'awaiting_verification',
        };
    }

    private function paymentState(Bill $bill): string
    {
        return match (true) {
            $bill->status === 'cancelled' => 'cancelled',
            bccomp($this->money($bill->balance), '0.00', 2) <= 0 => 'paid',
            bccomp($this->money($bill->paid_amount), '0.00', 2) > 0 => 'partially_paid',
            default => 'unpaid',
        };
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        $awaiting = fn (Builder $q) => $q->whereNotNull('returned_at')
            ->where(fn (Builder $r) => $r->whereNull('resubmitted_at')->orWhereColumn('returned_at', '>', 'resubmitted_at'));
        $enquiryIds = null;
        if ($filters['enquiry_id'] ?? null) {
            $enquiryIds = [(int) $filters['enquiry_id']];
        } elseif ($filters['project_officer_id'] ?? null) {
            $officer = (int) $filters['project_officer_id'];
            $enquiryIds = ProjectEnquiry::query()->where(fn (Builder $q) => $q->where('project_officer_id', $officer)->orWhere('assigned_po', $officer))
                ->pluck('id')->all();
        }

        return $query
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($filters['purchase_order_id'] ?? null, fn (Builder $q, $id) => $q->where('purchase_order_id', $id))
            ->when($enquiryIds !== null, fn (Builder $q) => $this->whereProject($q, $enquiryIds))
            ->when($filters['verification'] ?? null, fn (Builder $q, $v) => match ($v) {
                'returned_for_correction' => $awaiting($q),
                'verified' => $q->whereNotNull('verified_at'),
                default => $q->whereNull('verified_at')->whereNotIn('status', ['paid', 'cancelled'])->whereNot($awaiting),
            })
            ->when($filters['payment'] ?? null, fn (Builder $q, $p) => match ($p) {
                'paid' => $q->where(fn (Builder $w) => $w->where('status', 'paid')->orWhere('balance', '<=', 0)),
                'partially_paid' => $q->where('paid_amount', '>', 0)->where('balance', '>', 0)->where('status', '!=', 'cancelled'),
                default => $q->where('paid_amount', '<=', 0)->where('balance', '>', 0)->where('status', '!=', 'cancelled'),
            })
            ->when($filters['wht'] ?? null, fn (Builder $q, $w) => $w === 'with' ? $q->where('wht_amount', '>', 0) : $q->where(fn (Builder $x) => $x->whereNull('wht_amount')->orWhere('wht_amount', '<=', 0)))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->whereDate('due_date', '<', now()->toDateString())
                ->where('balance', '>', 0)->whereNotIn('status', ['paid', 'cancelled']))
            ->when($filters['bill_date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('bill_date', '>=', $d))
            ->when($filters['bill_date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('bill_date', '<=', $d))
            ->when($filters['due_date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($filters['due_date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('bill_number', 'like', $like)->orWhere('supplier_invoice_number', 'like', $like)
                    ->orWhereHas('purchaseOrder', fn (Builder $o) => $o->where('po_number', 'like', $like))
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('supplier_name', 'like', $like)));
            });
    }

    /**
     * A bill belongs to a project through its order's requisition (a Projects
     * row, resolved to its enquiry) or, for a direct bill, its own fields.
     */
    private function whereProject(Builder $query, array $enquiryIds): Builder
    {
        $projectIds = Project::query()->whereIn('enquiry_id', $enquiryIds ?: [0])->pluck('id')->all() ?: [0];

        return $query->where(fn (Builder $w) => $w->whereIn('project_enquiry_id', $enquiryIds ?: [0])
            ->orWhereIn('project_id', $projectIds)
            ->orWhereHas('purchaseOrder.requisition', fn (Builder $r) => $r->whereIn('project_id', $projectIds)));
    }

    /** bill id => {id, job_number, title, project_officer_id} of its project enquiry. */
    private function projectsFor(Collection $bills): Collection
    {
        $projectIdByBill = $bills->mapWithKeys(fn (Bill $b) => [$b->id => $b->purchaseOrder?->requisition?->project_id ?? $b->project_id]);
        $enquiryByProject = Project::query()->whereIn('id', $projectIdByBill->filter()->unique()->values() ?: [0])->pluck('enquiry_id', 'id');
        $enquiryByBill = $bills->mapWithKeys(fn (Bill $b) => [$b->id => $b->project_enquiry_id
            ?: ($projectIdByBill[$b->id] ? ($enquiryByProject[$projectIdByBill[$b->id]] ?? null) : null)]);
        $enquiries = ProjectEnquiry::query()->whereIn('id', $enquiryByBill->filter()->unique()->values() ?: [0])
            ->get(['id', 'job_number', 'title', 'project_officer_id', 'assigned_po'])->keyBy('id');

        return $enquiryByBill->map(fn ($id) => $id && $enquiries->has($id) ? [
            'id' => $enquiries[$id]->id, 'job_number' => $enquiries[$id]->job_number, 'title' => $enquiries[$id]->title,
            'project_officer_id' => $enquiries[$id]->project_officer_id ?: $enquiries[$id]->assigned_po,
        ] : null)->filter();
    }

    private function costFor(Bill $bill): array
    {
        $lines = CostLine::query()
            ->where(fn (Builder $q) => $q->where(fn (Builder $d) => $d->where('source_type', Bill::class)->where('source_id', $bill->id))
                ->orWhere('settled_by_bill_id', $bill->id))
            ->orderBy('id')
            ->get(['id', 'ref', 'nature', 'status', 'amount', 'job_number', 'description', 'settled_by_bill_id', 'source_type']);

        return [
            'lines' => $lines->map(fn (CostLine $line) => [
                'ref' => $line->ref, 'nature' => $line->nature, 'status' => $line->status,
                'amount' => $this->money($line->amount), 'job_number' => $line->job_number, 'description' => $line->description,
                'relation' => $line->source_type === Bill::class ? 'recognised_by_this_bill' : 'receipt_accrual_settled_by_this_bill',
            ])->values(),
            'basis' => $bill->isDirect()
                ? 'A direct bill has no goods receipt behind it: its cost is recognised as ACTUAL when it is verified.'
                : 'Costed when Stores accepted the goods (ACCRUED; ACTUAL when issued to a job). Verifying the bill moves that accrued liability to Accounts Payable; it adds no second cost.',
            'payments_add_cost' => false,
        ];
    }

    /** Headline counts over the whole book, independent of filters and paging. */
    private function summary(): array
    {
        $today = now()->toDateString();
        $awaiting = 'returned_at is not null and (resubmitted_at is null or returned_at > resubmitted_at)';
        $totals = Bill::query()->whereNotIn('status', ['cancelled'])
            ->selectRaw("sum(case when verified_at is null and status <> 'paid' and not ($awaiting) then 1 else 0 end) as awaiting_verification")
            ->selectRaw("sum(case when verified_at is null and ($awaiting) then 1 else 0 end) as returned_for_correction")
            ->selectRaw("sum(case when verified_at is not null and balance > 0 and status <> 'paid' then 1 else 0 end) as verified_unpaid")
            ->selectRaw("coalesce(sum(case when verified_at is not null and balance > 0 and status <> 'paid' then balance else 0 end),0) as verified_unpaid_amount")
            ->selectRaw("sum(case when due_date < ? and balance > 0 and status <> 'paid' then 1 else 0 end) as overdue", [$today])
            ->selectRaw("coalesce(sum(case when due_date < ? and balance > 0 and status <> 'paid' then balance else 0 end),0) as overdue_amount", [$today])
            ->selectRaw("coalesce(sum(case when balance > 0 and status <> 'paid' then balance else 0 end),0) as outstanding")
            ->first();

        return [
            'awaiting_verification' => (int) ($totals->awaiting_verification ?? 0),
            'returned_for_correction' => (int) ($totals->returned_for_correction ?? 0),
            'verified_unpaid' => (int) ($totals->verified_unpaid ?? 0),
            'verified_unpaid_amount' => $this->money($totals->verified_unpaid_amount ?? 0),
            'overdue' => (int) ($totals->overdue ?? 0),
            'overdue_amount' => $this->money($totals->overdue_amount ?? 0),
            'outstanding' => $this->money($totals->outstanding ?? 0),
        ];
    }

    private function workflowRelations(): array
    {
        return [
            'supplier:id,supplier_name',
            'purchaseOrder.requisition:id,requisition_number,project_id,status,total_amount,approved_at,approved_by,user_id',
            'purchaseOrder.items.material',
            'purchaseOrder.items.uom',
            'purchaseOrder.items.goodsReceiptNoteItems.inspection',
            'purchaseOrder.goodsReceiptNotes',
            'purchaseOrder.bills',
            'purchaseOrder.supplier',
            'verifiedBy:id,name',
        ];
    }

    // ── Ledger facts ─────────────────────────────────────────────────────

    /** The account withholding is owed on: the category's own, else the mapped WHT payable. */
    private function whtLiabilityAccount(?WhtCategory $category): ?array
    {
        $id = $category?->gl_account_id ?: $this->accountIdFor(FinanceAccountFunctions::WHT_PAYABLE);
        $account = $id ? ChartOfAccount::query()->whereKey($id)->first(['id', 'code', 'name']) : null;

        return $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] : null;
    }

    private function accountIdFor(string $function): ?int
    {
        $id = ChartOfAccount::query()->where('code', ChartAccountMap::local($function))->value('id');

        return $id ? (int) $id : null;
    }

    private function accountFor(string $function): ?array
    {
        $id = $this->accountIdFor($function);
        $account = $id ? ChartOfAccount::query()->whereKey($id)->first(['id', 'code', 'name']) : null;

        return $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] : null;
    }

    /** Credit-normal balance of a liability account over posted entries. */
    private function creditBalance(?int $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }
        $row = JournalLine::query()->where('account_id', $accountId)
            ->whereHas('journalEntry', fn (Builder $q) => $q->where('status', 'posted'))
            ->selectRaw("coalesce(sum(case when entry_type = 'credit' then amount else 0 end),0) - coalesce(sum(case when entry_type = 'debit' then amount else 0 end),0) as balance")
            ->first();

        return $this->money($row->balance ?? 0);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function names(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function person(Collection $names, mixed $id): ?array
    {
        return $id && $names->has((int) $id) ? ['id' => (int) $id, 'name' => $names[(int) $id]] : null;
    }

    private function iso(mixed $value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function meta($page): array
    {
        return [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ];
    }

    private function authoriseRead(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYABLES_READ), 403, 'You do not have access to supplier bills and payments.');
    }
}
