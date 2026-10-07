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
use App\Modules\HR\Models\PayrollRun;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActual;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Services\ProjectFinancialAccess;
use App\Support\SelfApproval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A read-only projection of work a user can perform in existing workflows.
 * Source records remain authoritative; this service never approves anything.
 *
 * Each work type is defined once (definitions()): who may act, the query for
 * outstanding records, and how a record reads as a queue item. The count and
 * the list are both derived from that one query, so the badge and the rows
 * cannot disagree. A type's query applies the same maker/checker exclusions its
 * controller enforces, so the queue never offers work the backend would refuse
 * (Report 56 §12): "You prepared this invoice, so someone else has to check it"
 * is a reason not to list it, not an error to discover by clicking.
 */
class FinanceWorkQueueService
{
    /** Queue areas, in the order the Finance overview presents them. */
    public const AREAS = ['sales', 'purchasing', 'cash', 'project', 'payroll'];

    private const LIST_LIMIT = 100;

    public function __construct(private ProjectFinancialAccess $access)
    {
    }

    public function countsForUser(User $user): array
    {
        $byType = [];
        $typeAreas = [];
        $byArea = array_fill_keys(self::AREAS, 0);
        foreach ($this->definitions($user) as $type => $definition) {
            $count = $definition['count']();
            if ($count > 0) {
                $byType[$type] = $count;
                $typeAreas[$type] = $definition['area'];
                $byArea[$definition['area']] += $count;
            }
        }

        return [
            'total' => array_sum($byType),
            'by_type' => $byType,
            'by_area' => $byArea,
            'type_labels' => array_intersect_key(self::LABELS, $byType),
            'type_areas' => $typeAreas,
        ];
    }

    public function forUser(User $user, array $filters = []): array
    {
        $items = collect();
        foreach ($this->definitions($user) as $definition) {
            $items = $items->concat($definition['items']());
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
            ->when(! empty($filters['area']), fn (Collection $rows) => $rows->where('area', $filters['area']))
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
        // Who raised each record on this page: one lookup, names only.
        $names = User::query()->whereIn('id', $pageItems->pluck('originator_id')->filter()->unique())->pluck('name', 'id');
        $pageItems = $pageItems->map(fn (array $item) => $item + [
            'originator' => $item['originator_id'] ? ($names[$item['originator_id']] ?? null) : null,
        ]);

        return [
            'items' => $pageItems->map(fn (array $item) => collect($item)->except(['priority_rank', 'originator_id'])->all())->all(),
            'summary' => [
                'total' => $sorted->count(),
                'overdue' => $sorted->where('priority', 'overdue')->count(),
                'exceptions' => $sorted->whereIn('priority', ['watch', 'overdue'])->count(),
                'by_type' => $sorted->countBy('work_type')->all(),
                'by_area' => array_merge(array_fill_keys(self::AREAS, 0), $sorted->countBy('area')->all()),
                'type_labels' => $sorted->pluck('type_label', 'work_type')->all(),
                'type_areas' => $sorted->pluck('area', 'work_type')->all(),
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
        return array_key_exists($type, $this->definitions($user));
    }

    /**
     * Every work type the user may act on, keyed by type.
     *
     * @return array<string, array{area: string, count: \Closure(): int, items: \Closure(): Collection}>
     */
    private function definitions(User $user): array
    {
        $self = SelfApproval::allowedFor($user);
        $uid = (int) $user->id;
        $types = [];

        // Adds a type whose outstanding records are one query. The builder is
        // produced afresh for each use; `map` turns a record into a queue item.
        $add = function (string $type, string $area, bool $allowed, \Closure $query, \Closure $map) use (&$types): void {
            if (! $allowed) {
                return;
            }
            $types[$type] = [
                'area' => $area,
                'count' => fn () => $query()->count(),
                'items' => fn () => $query()->limit(self::LIST_LIMIT)->get()->map($map)->values(),
            ];
        };
        // Excludes the viewer's own records unless they hold the self-approval exception.
        $notMine = fn (Builder $query, string $column) => $self ? $query
            : $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '!=', $uid));

        // ── Sales & receivables (W1) ──────────────────────────────────────
        $invoices = fn () => ProjectInvoice::query()->with('enquiry.client:id,full_name,company_name')
            ->whereNull('credits_invoice_id')->where('status', 'draft');
        $awaitingCorrection = fn (Builder $q) => $q->whereNotNull('returned_at')
            ->where(fn (Builder $r) => $r->whereNull('resubmitted_at')->orWhereColumn('returned_at', '>', 'resubmitted_at'));
        $invoiceItem = fn (string $type, string $action) => fn (ProjectInvoice $invoice) => $this->item(
            $type, 'sales', $invoice->id, $invoice->invoice_number, $this->clientName($invoice->enquiry),
            $invoice->total_amount, 'KES', $invoice->resubmitted_at ?? $invoice->created_at, $action,
            "/finance/invoices/{$invoice->id}", $invoice->enquiry?->job_number, $invoice->created_by,
        );

        $add('invoice_check', 'sales', $user->can(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK),
            // The preparer never checks their own invoice; unlike the other
            // controls this has no self-approval exception (checkProjectInvoice).
            fn () => $invoices()->whereNull('checked_at')->whereNot($awaitingCorrection)
                ->where(fn (Builder $q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $uid))->oldest(),
            $invoiceItem('invoice_check', 'Check invoice'));
        $add('invoice_issue', 'sales', $user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS),
            fn () => $invoices()->whereNotNull('checked_at')->oldest('checked_at'),
            $invoiceItem('invoice_issue', 'Issue invoice'));
        $add('invoice_correction', 'sales', $user->can(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS),
            fn () => $awaitingCorrection($invoices()->where('created_by', $uid))->oldest('returned_at'),
            $invoiceItem('invoice_correction', 'Correct invoice'));

        $add('client_receipt', 'sales', $user->can(Permissions::FINANCE_RECEIVABLES_VERIFY),
            fn () => $notMine(EnquiryPayment::query()->with('enquiry.client:id,full_name,company_name')
                ->where('status', 'pending')->whereNull('reversed_at'), 'recorded_by')->oldest('payment_date'),
            fn (EnquiryPayment $receipt) => $this->item(
                'client_receipt', 'sales', $receipt->id, $receipt->transaction_reference ?: "RECEIPT-{$receipt->id}",
                $this->clientName($receipt->enquiry), $receipt->amount, 'KES', $receipt->payment_date ?? $receipt->created_at,
                'Verify receipt', "/finance/receipts?receipt_id={$receipt->id}", $receipt->enquiry?->job_number,
                $receipt->recorded_by,
            ));

        // ── Purchasing & payables (W2) ────────────────────────────────────
        $add('purchase_requisition', 'purchasing', $user->can(Permissions::PROCUREMENT_REQUISITIONS_APPROVE),
            // RequisitionController::approve refuses the person who raised it.
            fn () => $notMine(Requisition::query()->where('status', 'pending_approval'), 'user_id')->oldest('submitted_at'),
            fn (Requisition $request) => $this->item(
                'purchase_requisition', 'purchasing', $request->id, $request->requisition_number, 'Internal request',
                $request->total_amount, 'KES', $request->submitted_at ?? $request->created_at, 'Approve purchase',
                "/procurement/requisition/{$request->id}", $request->job_number, $request->user_id,
            ));
        $add('purchase_order', 'purchasing', $user->can(Permissions::PROCUREMENT_ORDERS_APPROVE),
            // PurchaseOrderController::approve refuses the person who raised it.
            fn () => $notMine(PurchaseOrder::query()->with('supplier:id,supplier_name')->where('status', 'pending_approval'), 'user_id')->oldest(),
            fn (PurchaseOrder $order) => $this->item(
                'purchase_order', 'purchasing', $order->id, $order->po_number ?? "PO-{$order->id}",
                $order->supplier?->supplier_name ?? 'Supplier', $order->total_amount ?? 0, 'KES', $order->created_at,
                'Approve order', "/procurement/purchase-order/{$order->id}", null, $order->user_id,
            ));
        // Report 60: supplier bills. A bill returned for correction is with its
        // preparer, not the verifier, until it is resubmitted.
        $billAwaitingCorrection = fn (Builder $q) => $q->whereNotNull('returned_at')
            ->where(fn (Builder $r) => $r->whereNull('resubmitted_at')->orWhereColumn('returned_at', '>', 'resubmitted_at'));
        $billItem = fn (string $type, string $action) => fn (Bill $bill) => $this->item(
            $type, 'purchasing', $bill->id, $bill->bill_number, $bill->supplier?->supplier_name ?? 'Supplier',
            $type === 'supplier_payment' ? $bill->balance : $bill->amount, 'KES',
            $type === 'supplier_invoice_correction' ? $bill->returned_at : ($bill->bill_date ?? $bill->created_at), $action,
            "/finance/payables/bills/{$bill->id}", $bill->job_number, $bill->user_id,
        );
        $add('supplier_invoice', 'purchasing', $user->can(Permissions::FINANCE_PAYABLES_VERIFY),
            fn () => $notMine(Bill::query()->with('supplier:id,supplier_name')->whereNull('verified_at')
                ->whereNotIn('status', ['paid', 'cancelled']), 'user_id')->whereNot($billAwaitingCorrection)->oldest('bill_date'),
            $billItem('supplier_invoice', 'Verify supplier invoice'));
        $add('supplier_invoice_correction', 'purchasing', true,
            fn () => $billAwaitingCorrection(Bill::query()->with('supplier:id,supplier_name')->where('user_id', $uid)
                ->whereNull('verified_at')->whereNotIn('status', ['paid', 'cancelled']))->oldest('returned_at'),
            $billItem('supplier_invoice_correction', 'Correct supplier invoice'));
        // Verified, not yet settled, and payable directly by this person (the
        // same permission and legacy exclusion BillController::directPaymentRefusal
        // applies; the payment gate re-checks the fingerprint when they pay).
        $add('supplier_payment', 'purchasing', $user->can(Permissions::FINANCE_PETTY_CASH_CREATE),
            fn () => Bill::query()->with('supplier:id,supplier_name')->whereNotNull('verified_at')
                ->where('verification_basis', '!=', 'legacy')->where('balance', '>', 0)
                ->whereNotIn('status', ['paid', 'cancelled'])->oldest('due_date'),
            $billItem('supplier_payment', 'Pay supplier invoice'));

        // ── Expenses & cash (W3/W4) ───────────────────────────────────────
        $fund = fn (string $type, string $action) => fn (PettyCashRequisition $request) => $this->item(
            $type, 'cash', $request->id, $request->requisition_number,
            $request->payee_name ?: $request->requester_name ?: 'Internal requester', $request->total_amount, 'KES',
            $request->updated_at ?? $request->created_at, $action, "/finance/petty-cash/requisitions/{$request->id}",
            $request->project_name, $request->user_id,
        );
        $add('fund_requisition', 'cash', $user->can(Permissions::FINANCE_PETTY_CASH_UPDATE),
            fn () => $notMine(PettyCashRequisition::query()->where('status', 'pending'), 'user_id')->oldest(),
            $fund('fund_requisition', 'Review request'));
        // Report 61: the disburser and the reviewer of a surrender are never the
        // requester (disburse and return refuse them), so their own requests are
        // not offered to them as work.
        $add('fund_disbursement', 'cash', $user->can(Permissions::FINANCE_PETTY_CASH_CREATE),
            // Report 75R-C: the approver is never the payer, whatever they hold
            // (RequisitionDisbursementService::assertNotApprover has no override).
            fn () => $notMine(PettyCashRequisition::query()->where('status', 'approved'), 'user_id')
                ->where(fn (Builder $q) => $q->whereNull('approved_by')->orWhere('approved_by', '!=', $uid))->oldest('approved_at'),
            $fund('fund_disbursement', 'Disburse'));
        $add('fund_surrender_review', 'cash', $user->can(Permissions::FINANCE_PETTY_CASH_CREATE),
            fn () => $notMine(PettyCashRequisition::query()->where('status', 'surrender_pending'), 'user_id')->oldest('surrendered_at'),
            $fund('fund_surrender_review', 'Review surrender'));
        // STAB-4: cash left but its journal did not post. The retry is a human
        // decision (the cause — a closed period, an unmapped account — must be
        // fixed first) and it is idempotent.
        $add('petty_cash_posting_failed', 'cash', $user->can(Permissions::FINANCE_PETTY_CASH_UPDATE),
            fn () => PettyCashRequisition::query()->whereNotNull('advance_gl_posting_failed_at')->oldest('advance_gl_posting_failed_at'),
            $fund('petty_cash_posting_failed', 'Retry ledger posting'));
        $add('fund_surrender', 'cash', true,
            fn () => PettyCashRequisition::query()->where('user_id', $uid)
                ->whereIn('status', ['disbursed', 'received', 'surrender_returned'])->oldest(),
            $fund('fund_surrender', 'Record surrender'));

        $add('direct_disbursement', 'cash', $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE),
            fn () => $notMine(DirectDisbursementRequest::query()->where('status', 'pending_approval'), 'requested_by')->oldest(),
            function (DirectDisbursementRequest $request) {
                $payload = $request->payload ?? [];

                return $this->item(
                    'direct_disbursement', 'cash', $request->id, $payload['payment_no'] ?? "DIRECT-{$request->id}",
                    $payload['payee_name'] ?? 'Payee', $payload['amount'] ?? 0, $payload['currency'] ?? 'KES',
                    $request->created_at, 'Approve disbursement',
                    "/finance/petty-cash?direct_request={$request->id}#direct-request-{$request->id}", $payload['project_name'] ?? null,
                    $request->requested_by,
                );
            });

        $voucher = fn (string $type, string $action) => fn (SpendVoucher $v) => $this->item(
            $type, 'cash', $v->id, $v->voucher_no, $v->payee_name ?: 'Payee', $v->total_amount, $v->currency ?: 'KES',
            $v->resubmitted_at ?? $v->approved_at ?? $v->created_at, $action, "/finance/payment-vouchers/{$v->id}", null,
            $v->requester_user_id,
        );
        $returned = ['returned_for_correction', 'corrected'];
        $add('spend_voucher', 'cash', $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE),
            fn () => $notMine(SpendVoucher::query()->where('status', 'pending_approval')
                ->where(fn (Builder $q) => $q->whereNull('review_state')->orWhereNotIn('review_state', $returned)), 'requester_user_id')->oldest(),
            $voucher('spend_voucher', 'Approve voucher'));
        $add('spend_voucher_correction', 'cash', $user->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE),
            fn () => SpendVoucher::query()->where('requester_user_id', $uid)->whereIn('review_state', $returned)->oldest('returned_at'),
            $voucher('spend_voucher_correction', 'Correct voucher'));
        $independent = fn (Builder $q) => $self ? $q : $q
            ->where(fn (Builder $r) => $r->whereNull('requester_user_id')->orWhere('requester_user_id', '!=', $uid))
            ->where(fn (Builder $r) => $r->whereNull('approved_by')->orWhere('approved_by', '!=', $uid));
        $add('spend_voucher_senior', 'cash', $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR),
            fn () => $independent(SpendVoucher::query()->where('status', 'approved')->where('review_state', 'awaiting_senior_approval'))->oldest('approved_at'),
            $voucher('spend_voucher_senior', 'Give senior approval'));
        $add('spend_voucher_post', 'cash', $user->can(Permissions::FINANCE_SPEND_VOUCHERS_POST),
            fn () => $independent(SpendVoucher::query()->where('status', 'approved')->whereNull('posted_at')
                ->where(fn (Builder $q) => $q->whereNull('review_state')->orWhere('review_state', '!=', 'awaiting_senior_approval')))->oldest('approved_at'),
            $voucher('spend_voucher_post', 'Post voucher'));

        // ── Project finance (W7) ──────────────────────────────────────────
        $add('cost_verification', 'project', $user->can(Permissions::FINANCE_COSTS_VERIFY),
            // CostVerificationService refuses the submitter unless they hold verifyOwn.
            fn () => $notMine(CostLine::query()->where('nature', '!=', CostLine::NATURE_PLANNED)
                ->where('status', CostLine::STATUS_SUBMITTED), 'submitted_by_user_id')->oldest('incurred_at'),
            fn (CostLine $cost) => $this->item(
                'cost_verification', 'project', $cost->id, $cost->ref ?: "COST-{$cost->id}", $cost->payee_name ?: 'Unspecified payee',
                $cost->net_amount, $cost->currency ?: 'KES', $cost->incurred_at ?? $cost->created_at, 'Verify cost',
                "/finance/costs/verification?cost={$cost->ref}", $cost->job_number, $cost->submitted_by_user_id,
            ));
        $labour = fn (string $type, string $action) => fn (ProjectLabourActual $actual) => $this->item(
            $type, 'project', $actual->id, $actual->enquiry?->job_number ? "{$actual->enquiry->job_number} · {$actual->labour_role}" : "LABOUR-{$actual->id}",
            trim(($actual->employee?->first_name ?? '').' '.($actual->employee?->last_name ?? '')) ?: 'Employee', $actual->calculated_cost, 'KES',
            $actual->po_verified_at ?? $actual->recorded_at ?? $actual->created_at, $action,
            "/finance/costs?tab=account&enquiry={$actual->project_enquiry_id}", $actual->enquiry?->title, $actual->recorded_by,
        );
        $add('labour_finance_verify', 'project', $this->access->canFinanceVerifyLabour($user),
            fn () => ProjectLabourActual::query()->with(['enquiry:id,job_number,title', 'employee:id,first_name,last_name'])
                ->where('status', ProjectLabourActual::STATUS_PO_VERIFIED)->oldest('po_verified_at'),
            $labour('labour_finance_verify', 'Finance-verify labour'));
        if ($user->can(Permissions::FINANCE_LABOUR_PO_VERIFY)) {
            // Project-scoped: the same rule the service enforces, applied per
            // project, so an officer sees only their own projects' labour.
            $eligible = function () use ($user) {
                return ProjectLabourActual::query()->with(['enquiry', 'employee:id,first_name,last_name'])
                    ->where('status', ProjectLabourActual::STATUS_RECORDED)->oldest('recorded_at')->limit(500)->get()
                    ->filter(fn (ProjectLabourActual $actual) => $actual->enquiry && $this->access->canPoVerifyLabour($user, $actual->enquiry))
                    ->values();
            };
            $types['labour_po_verify'] = [
                'area' => 'project',
                'count' => fn () => $eligible()->count(),
                'items' => fn () => $eligible()->take(self::LIST_LIMIT)->map($labour('labour_po_verify', 'PO-verify labour'))->values(),
            ];
        }

        // ── Payroll (W6) ──────────────────────────────────────────────────
        $run = fn (string $type, string $action, string $url) => fn (PayrollRun $run) => $this->item(
            $type, 'payroll', $run->id, "PAYROLL {$run->payroll_month}", 'Payroll run', $run->total_net, 'KES',
            $run->updated_at ?? $run->created_at, $action, $url, null, $run->created_by,
        );
        $manage = $user->can(Permissions::HR_MANAGE_PAYROLL);
        $add('payroll_lock', 'payroll', $manage,
            fn () => $notMine(PayrollRun::query()->where('status', 'processing'), 'created_by')->oldest(),
            $run('payroll_lock', 'Lock payroll', '/hr/payroll'));
        $add('payroll_payment', 'payroll', $manage || $user->can(Permissions::FINANCE_PAYROLL_PAY),
            fn () => $notMine(PayrollRun::query()->where('status', 'locked'), 'locked_by')->oldest(),
            $run('payroll_payment', 'Mark payroll paid', '/finance/payroll-disbursement'));

        return $types;
    }

    private function clientName(?ProjectEnquiry $enquiry): string
    {
        return $enquiry?->client?->company_name ?: $enquiry?->client?->full_name ?: 'Client';
    }

    private function item(string $workType, string $area, int $id, ?string $reference, string $counterparty,
        mixed $amount, string $currency, mixed $submittedAt, string $action, string $targetUrl, ?string $context,
        mixed $originatorId = null): array
    {
        $date = $submittedAt ? \Illuminate\Support\Carbon::parse($submittedAt) : now();
        $age = (int) $date->diffInDays(now());

        return [
            'key' => "{$workType}:{$id}", 'work_type' => $workType, 'type_label' => self::LABELS[$workType] ?? $workType,
            'area' => $area, 'source_id' => $id, 'reference' => $reference ?: "#{$id}", 'counterparty' => $counterparty,
            'amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency,
            'submitted_at' => $date->toIso8601String(), 'age_days' => $age,
            'priority' => $age > 30 ? 'overdue' : ($age > 7 ? 'watch' : 'normal'),
            'priority_rank' => $age > 30 ? 0 : ($age > 7 ? 1 : 2),
            'required_action' => $action, 'target_url' => $targetUrl, 'context' => $context,
            'originator_id' => $originatorId ? (int) $originatorId : null,
        ];
    }

    /** Plain-language name of each work type, shown as the item's kind. */
    public const LABELS = [
        'invoice_check' => 'Invoice to check',
        'invoice_issue' => 'Invoice to issue',
        'invoice_correction' => 'Invoice returned to you',
        'client_receipt' => 'Client receipt',
        'purchase_requisition' => 'Purchase requisition',
        'purchase_order' => 'Purchase order exception',
        'supplier_invoice' => 'Supplier invoice',
        'supplier_invoice_correction' => 'Supplier invoice returned to you',
        'supplier_payment' => 'Supplier invoice to pay',
        'fund_requisition' => 'Cash requisition',
        'fund_disbursement' => 'Cash to disburse',
        'fund_surrender_review' => 'Surrender to review',
        'fund_surrender' => 'Cash to account for',
        'petty_cash_posting_failed' => 'Petty cash posting failed',
        'direct_disbursement' => 'Direct disbursement',
        'spend_voucher' => 'Payment voucher',
        'spend_voucher_correction' => 'Voucher returned to you',
        'spend_voucher_senior' => 'Voucher for senior approval',
        'spend_voucher_post' => 'Voucher to post',
        'cost_verification' => 'Cost verification',
        'labour_po_verify' => 'Labour to PO-verify',
        'labour_finance_verify' => 'Labour to Finance-verify',
        'payroll_lock' => 'Payroll to lock',
        'payroll_payment' => 'Payroll to pay',
    ];
}
