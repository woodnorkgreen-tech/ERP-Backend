<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\EnquiryPayment;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Support\InvoiceState;
use App\Modules\Finance\Support\ReceivablesActions;
use App\Modules\Projects\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * W1 read projections for the Finance Sales & receivables workspace
 * (Report 58 §5). Read-only: every change still goes through the existing
 * per-project routes on EnquiryController, which stay authoritative.
 *
 * Amounts are the model scopes' own definitions (verified, unreversed
 * allocations; net of non-void credit notes), the same ones the per-project
 * list, the allocation cap and receivables ageing use. People appear as
 * `{id, name}` only; no User or Employee model is serialised.
 */
class ReceivablesController extends Controller
{
    public function __construct(private FinanceService $finance)
    {
    }

    /** GET api/finance/invoices */
    public function invoices(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'client_id' => ['nullable', 'integer'],
            'enquiry_id' => ['nullable', 'integer'],
            'project_officer_id' => ['nullable', 'integer'],
            'state' => ['nullable', 'in:'.implode(',', InvoiceState::STATES)],
            'overdue' => ['nullable', 'boolean'],
            'invoice_date_from' => ['nullable', 'date'],
            'invoice_date_to' => ['nullable', 'date'],
            'due_date_from' => ['nullable', 'date'],
            'due_date_to' => ['nullable', 'date'],
            'include_credit_notes' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = ProjectInvoice::query()->select('project_invoices.*')
            ->withVerifiedPaidAmount()->withNetTotal()
            ->withExists('payments as has_allocations')
            ->withExists(['creditNotes as has_credit_notes' => fn ($q) => $q->where('status', '!=', 'void')])
            ->with(['enquiry:id,job_number,title,client_id,project_officer_id,assigned_po', 'enquiry.client:id,full_name,company_name'])
            ->when(! ($filters['include_credit_notes'] ?? false), fn (Builder $q) => $q->whereNull('credits_invoice_id'))
            ->when($filters['client_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('enquiry', fn (Builder $e) => $e->where('client_id', $id)))
            ->when($filters['enquiry_id'] ?? null, fn (Builder $q, $id) => $q->where('project_enquiry_id', $id))
            ->when($filters['project_officer_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('enquiry',
                fn (Builder $e) => $e->where('project_officer_id', $id)->orWhere('assigned_po', $id)))
            ->when($filters['state'] ?? null, fn (Builder $q, $state) => InvoiceState::whereState($q, $state))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => InvoiceState::whereState(
                $q->whereIn('status', ['issued', 'paid'])->whereDate('due_date', '<', now()->toDateString()), 'owed'))
            ->when($filters['invoice_date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['invoice_date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($filters['due_date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($filters['due_date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('invoice_number', 'like', $like)
                    ->orWhereHas('enquiry', fn (Builder $e) => $e->where('job_number', 'like', $like)->orWhere('title', 'like', $like)
                        ->orWhereHas('client', fn (Builder $c) => $c->where('full_name', 'like', $like)->orWhere('company_name', 'like', $like))));
            })
            ->orderByDesc('invoice_date')->orderByDesc('id');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));
        $invoices = collect($page->items());
        $names = $this->names($invoices->flatMap(fn (ProjectInvoice $i) => [
            $i->created_by, $i->checked_by, $i->issued_by, $i->returned_by, $i->voided_by,
            $i->enquiry?->project_officer_id, $i->enquiry?->assigned_po,
        ]));
        $allocatable = $this->enquiriesWithApplicableMoney($invoices->pluck('project_enquiry_id'));

        return response()->json([
            'data' => $invoices->map(fn (ProjectInvoice $i) => $this->invoiceRow($request->user(), $i, $names, $allocatable))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }

    /** GET api/finance/invoices/{invoice} */
    public function invoice(Request $request, int $invoice): JsonResponse
    {
        $this->authoriseRead($request);
        $model = ProjectInvoice::query()->select('project_invoices.*')
            ->withVerifiedPaidAmount()->withNetTotal()
            ->withExists('payments as has_allocations')
            ->withExists(['creditNotes as has_credit_notes' => fn ($q) => $q->where('status', '!=', 'void')])
            ->with([
                'enquiry:id,job_number,title,client_id,project_officer_id,assigned_po,status',
                'enquiry.client:id,full_name,company_name',
                'lines.vatTreatment:id,code,name,rate_percent',
                'paymentTerm:id,name,days',
                'creditedInvoice:id,invoice_number',
            ])
            ->findOrFail($invoice);

        $creditNotes = ProjectInvoice::query()->select('project_invoices.*')->withVerifiedPaidAmount()->withNetTotal()
            ->withExists('payments as has_allocations')->withExists(['creditNotes as has_credit_notes' => fn ($q) => $q->where('status', '!=', 'void')])
            ->where('credits_invoice_id', $model->id)->orderBy('id')->get();

        $allocations = DB::table('project_invoice_allocations as a')
            ->join('enquiry_payments as p', 'p.id', '=', 'a.enquiry_payment_id')
            ->where('a.project_invoice_id', $model->id)->orderBy('a.id')
            ->get(['a.id', 'a.amount', 'a.allocated_by', 'a.created_at', 'p.id as receipt_id', 'p.transaction_reference',
                'p.payment_date', 'p.payment_method', 'p.status as receipt_status', 'p.reversed_at']);

        $applicable = $this->applicableReceipts($model->project_enquiry_id);
        $audit = GovernanceAuditLog::query()->where('model_type', ProjectInvoice::class)
            ->whereIn('model_id', $creditNotes->pluck('id')->push($model->id))->orderBy('created_at')->orderBy('id')
            ->get(['id', 'model_id', 'user_id', 'gate_type', 'message', 'context', 'created_at']);

        $names = $this->names(collect([$model, ...$creditNotes])->flatMap(fn (ProjectInvoice $i) => [
            $i->created_by, $i->checked_by, $i->issued_by, $i->returned_by, $i->voided_by,
            $i->no_quote_exception_requested_by, $i->no_quote_exception_approved_by,
        ])->merge([$model->enquiry?->project_officer_id, $model->enquiry?->assigned_po])
            ->merge($allocations->pluck('allocated_by'))->merge($audit->pluck('user_id')));

        $progress = $model->enquiry ? $this->finance->getPaymentProgress($model->enquiry) : null;
        $row = $this->invoiceRow($request->user(), $model, $names, $applicable->isNotEmpty() ? collect([$model->project_enquiry_id]) : collect());

        return response()->json(['data' => array_merge($row, [
            'notes' => $model->notes,
            'payment_term' => $model->paymentTerm?->only(['id', 'name', 'days']),
            'credits_invoice' => $model->creditedInvoice?->only(['id', 'invoice_number']),
            'subtotal' => $this->money($model->subtotal),
            'tax_amount' => $this->money($model->tax_amount),
            'lines' => $model->lines->map(fn ($line) => [
                'id' => $line->id, 'description' => $line->description, 'quantity' => (float) $line->quantity,
                'unit_price' => $this->money($line->unit_price), 'discount_amount' => $this->money($line->discount_amount),
                'net_amount' => $this->money($line->net_amount), 'tax_amount' => $this->money($line->tax_amount),
                'total_amount' => $this->money($line->total_amount),
                'vat_treatment' => $line->vatTreatment?->only(['id', 'code', 'name', 'rate_percent']),
            ])->values(),
            'no_quote_exception' => $model->no_quote_exception_reason ? [
                'reason' => $model->no_quote_exception_reason,
                'requested_by' => $this->person($names, $model->no_quote_exception_requested_by),
                'approved_by' => $this->person($names, $model->no_quote_exception_approved_by),
                'approved_at' => $model->no_quote_exception_approved_at?->toIso8601String(),
                'evidence_reference' => $model->no_quote_exception_evidence_reference,
            ] : null,
            'commercial_basis' => $progress ? [
                'quote_amount' => $this->money($progress['total_quote']),
                'source_label' => $progress['quote_source_label'] ?? null,
                'is_client_approved' => (bool) ($progress['is_client_approved_basis'] ?? false),
                'waived' => (bool) ($progress['quote_requirement_waived'] ?? false),
            ] : null,
            // Raised but not yet issued: shown as pending, never netted (Report 59 §12).
            'pending_credit_amount' => $this->money(abs((float) $creditNotes->where('status', 'draft')->sum('total_amount'))),
            'credit_notes' => $creditNotes->map(fn (ProjectInvoice $note) => $this->invoiceRow($request->user(), $note, $names, collect()))->values(),
            'allocations' => $allocations->map(fn ($a) => [
                'id' => $a->id, 'amount' => $this->money($a->amount), 'allocated_at' => $a->created_at,
                'allocated_by' => $this->person($names, $a->allocated_by),
                'receipt' => [
                    'id' => $a->receipt_id, 'reference' => $a->transaction_reference, 'payment_date' => $a->payment_date,
                    'payment_method' => $a->payment_method, 'status' => $a->reversed_at ? 'reversed' : $a->receipt_status,
                ],
            ])->values(),
            'applicable_receipts' => $applicable->values(),
            'audit' => $audit->map(fn (GovernanceAuditLog $log) => [
                'id' => $log->id, 'event' => $log->gate_type, 'message' => $log->message,
                'reason' => is_array($log->context) ? ($log->context['reason'] ?? null) : null,
                'by' => $this->person($names, $log->user_id), 'at' => $log->created_at?->toIso8601String(),
                'document_id' => $log->model_id,
            ])->values(),
        ])]);
    }

    /** GET api/finance/receipts */
    public function receipts(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:pending,verified,reversed'],
            'application' => ['nullable', 'in:unapplied,partly_applied,fully_applied'],
            'client_id' => ['nullable', 'integer'],
            'enquiry_id' => ['nullable', 'integer'],
            'receipt_id' => ['nullable', 'integer'],
            'payment_source_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $applied = '(select coalesce(sum(a.amount),0) from project_invoice_allocations a where a.enquiry_payment_id = enquiry_payments.id)';

        $base = fn () => EnquiryPayment::query()
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $status === 'reversed'
                ? $q->where(fn (Builder $r) => $r->whereNotNull('reversed_at')->orWhere('status', 'reversed'))
                : $q->where('status', $status)->whereNull('reversed_at'))
            ->when($filters['application'] ?? null, fn (Builder $q, $state) => $q->where('status', 'verified')->whereNull('reversed_at')->whereRaw(match ($state) {
                'unapplied' => "$applied = 0",
                'partly_applied' => "$applied > 0 and $applied < enquiry_payments.amount",
                default => "$applied >= enquiry_payments.amount",
            }))
            ->when($filters['client_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('enquiry', fn (Builder $e) => $e->where('client_id', $id)))
            ->when($filters['enquiry_id'] ?? null, fn (Builder $q, $id) => $q->where('project_enquiry_id', $id))
            ->when($filters['receipt_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['payment_source_id'] ?? null, fn (Builder $q, $id) => $q->where('payment_source_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('transaction_reference', 'like', $like)
                    ->orWhereHas('enquiry', fn (Builder $e) => $e->where('job_number', 'like', $like)->orWhere('title', 'like', $like)
                        ->orWhereHas('client', fn (Builder $c) => $c->where('full_name', 'like', $like)->orWhere('company_name', 'like', $like))));
            });

        $page = $base()->select('enquiry_payments.*')->selectRaw("$applied as applied_amount")
            ->with(['enquiry:id,job_number,title,client_id', 'enquiry.client:id,full_name,company_name', 'paymentSource:id,code,name,type'])
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $receipts = collect($page->items());

        $allocations = DB::table('project_invoice_allocations as a')->join('project_invoices as i', 'i.id', '=', 'a.project_invoice_id')
            ->whereIn('a.enquiry_payment_id', $receipts->pluck('id'))
            ->get(['a.enquiry_payment_id', 'a.amount', 'i.id as invoice_id', 'i.invoice_number'])->groupBy('enquiry_payment_id');
        $names = $this->names($receipts->flatMap(fn (EnquiryPayment $p) => [$p->recorded_by, $p->verified_by, $p->reversed_by]));

        // Headline figures over the whole book, independent of filters and paging.
        // One definition, shared with the Finance Overview (Report 65).
        $totals = \App\Modules\Finance\Support\FinancePositions::receipts();

        return response()->json([
            'data' => $receipts->map(function (EnquiryPayment $p) use ($request, $allocations, $names) {
                $applied = (float) $p->applied_amount;
                $reversed = (bool) ($p->reversed_at || $p->status === 'reversed');

                return [
                    'id' => $p->id,
                    'reference' => $p->transaction_reference,
                    'client_receipt_id' => $p->client_receipt_id,
                    'payment_date' => $p->payment_date?->toDateString(),
                    'amount' => $this->money($p->amount),
                    'currency' => 'KES',
                    'payment_method' => $p->payment_method,
                    'payment_source' => $p->paymentSource?->only(['id', 'code', 'name', 'type']),
                    'status' => $reversed ? 'reversed' : $p->status,
                    'applied_amount' => $this->money($applied),
                    // Verified money not yet applied to an invoice is held for the client
                    // (client deposits) — never revenue. Pending money is not yet held.
                    'unapplied_amount' => $this->money(! $reversed && $p->status === 'verified' ? max(0, (float) $p->amount - $applied) : 0),
                    'enquiry' => $this->enquiry($p->enquiry),
                    'client' => $this->client($p->enquiry),
                    'recorded_by' => $this->person($names, $p->recorded_by),
                    'recorded_at' => $p->created_at?->toIso8601String(),
                    'verified_by' => $this->person($names, $p->verified_by),
                    'verified_at' => $p->verified_at?->toIso8601String(),
                    'reversed_by' => $this->person($names, $p->reversed_by),
                    'reversed_at' => $p->reversed_at?->toIso8601String(),
                    'reversal_reason' => $p->reversal_reason,
                    'notes' => $p->notes,
                    'evidence_path' => $p->evidence_path,
                    'allocations' => ($allocations[$p->id] ?? collect())->map(fn ($a) => [
                        'invoice_id' => $a->invoice_id, 'invoice_number' => $a->invoice_number, 'amount' => $this->money($a->amount),
                    ])->values(),
                    'actions' => ReceivablesActions::forReceipt($request->user(), $p, ['allocations' => $applied > 0]),
                ];
            })->values(),
            'summary' => [
                'pending_verification' => $totals['pending_verification'],
                'unapplied_client_money' => $totals['unapplied_client_money'],
            ],
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }

    private function invoiceRow(User $user, ProjectInvoice $invoice, Collection $names, Collection $allocatableEnquiries): array
    {
        $paid = (float) ($invoice->paid_amount ?? 0);
        $net = (float) ($invoice->net_total_amount ?? $invoice->total_amount);
        $balance = $invoice->status === 'void' || $invoice->isCreditNote() ? 0.0 : max(0, $net - $paid);
        $actions = ReceivablesActions::forInvoice($user, $invoice, [
            'paid' => $paid, 'net_total' => $net,
            'allocations' => (bool) $invoice->has_allocations,
            'credit_notes' => (bool) $invoice->has_credit_notes,
            'allocatable_receipts' => $allocatableEnquiries->contains($invoice->project_enquiry_id),
        ]);
        $next = collect(['check', 'edit', 'issue', 'allocate'])->first(fn (string $key) => $actions[$key]['allowed']
            && ($key !== 'edit' || InvoiceState::awaitingCorrection($invoice)));

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'is_credit_note' => $invoice->isCreditNote(),
            'credits_invoice_id' => $invoice->credits_invoice_id,
            'enquiry' => $this->enquiry($invoice->enquiry),
            'client' => $this->client($invoice->enquiry),
            'project_officer' => $this->person($names, $invoice->enquiry?->project_officer_id ?: $invoice->enquiry?->assigned_po),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => 'KES',
            'total_amount' => $this->money($invoice->total_amount),
            'net_total_amount' => $this->money($net),
            'paid_amount' => $this->money($paid),
            'balance' => $this->money($balance),
            'status' => $invoice->status,
            'document_state' => InvoiceState::documentState($invoice),
            'payment_state' => InvoiceState::paymentState($invoice, $paid, $net),
            'review_state' => InvoiceState::reviewState($invoice, $paid, $net),
            'days_overdue' => InvoiceState::daysOverdue($invoice, $balance),
            'prepared_by' => $this->person($names, $invoice->created_by),
            'prepared_at' => $invoice->created_at?->toIso8601String(),
            'checked_by' => $this->person($names, $invoice->checked_by),
            'checked_at' => $invoice->checked_at?->toIso8601String(),
            'returned_by' => $this->person($names, $invoice->returned_by),
            'returned_at' => $invoice->returned_at?->toIso8601String(),
            'return_reason' => $invoice->return_reason,
            'resubmitted_at' => $invoice->resubmitted_at?->toIso8601String(),
            'issued_by' => $this->person($names, $invoice->issued_by),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'voided_by' => $this->person($names, $invoice->voided_by),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'actions' => $actions,
            'next_action' => $next,
        ];
    }

    /** Verified, unreversed receipts on a project with money not yet applied to an invoice. */
    private function applicableReceipts(int $enquiryId): Collection
    {
        return DB::table('enquiry_payments as p')
            ->leftJoin('project_invoice_allocations as a', 'a.enquiry_payment_id', '=', 'p.id')
            ->where('p.project_enquiry_id', $enquiryId)->where('p.status', 'verified')->whereNull('p.reversed_at')
            ->groupBy('p.id', 'p.transaction_reference', 'p.payment_date', 'p.amount')
            ->havingRaw('p.amount - coalesce(sum(a.amount),0) > 0')
            ->orderBy('p.payment_date')
            ->get(['p.id', 'p.transaction_reference as reference', 'p.payment_date', 'p.amount', DB::raw('p.amount - coalesce(sum(a.amount),0) as available')])
            ->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'payment_date' => $r->payment_date,
                'amount' => $this->money($r->amount), 'available' => $this->money($r->available)]);
    }

    private function enquiriesWithApplicableMoney(Collection $enquiryIds): Collection
    {
        if ($enquiryIds->isEmpty()) {
            return collect();
        }

        return DB::table('enquiry_payments as p')
            ->leftJoin('project_invoice_allocations as a', 'a.enquiry_payment_id', '=', 'p.id')
            ->whereIn('p.project_enquiry_id', $enquiryIds->unique())->where('p.status', 'verified')->whereNull('p.reversed_at')
            ->groupBy('p.id', 'p.project_enquiry_id', 'p.amount')
            ->havingRaw('p.amount - coalesce(sum(a.amount),0) > 0')
            ->pluck('p.project_enquiry_id')->unique()->values();
    }

    /** id => name for the given user ids; nothing else about the user is read. */
    private function names(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function person(Collection $names, mixed $id): ?array
    {
        return $id && $names->has((int) $id) ? ['id' => (int) $id, 'name' => $names[(int) $id]] : null;
    }

    private function enquiry($enquiry): ?array
    {
        return $enquiry ? ['id' => $enquiry->id, 'job_number' => $enquiry->job_number, 'title' => $enquiry->title] : null;
    }

    private function client($enquiry): ?array
    {
        $client = $enquiry?->client;

        return $client ? ['id' => $client->id, 'name' => $client->company_name ?: $client->full_name] : null;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function authoriseRead(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_RECEIVABLES_READ), 403, 'You do not have access to client invoices and receipts.');
    }
}
