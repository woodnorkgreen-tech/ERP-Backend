<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashCashCount;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderItem;
use App\Modules\Finance\PettyCash\Support\PettyCashActions;
use App\Modules\Finance\Services\FinanceAttachmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * W3 petty-cash read projections for the Finance workspace (Report 61).
 *
 * Read-only except for evidence: every workflow change still goes through
 * PettyCashRequisitionController / PettyCashController, which stay
 * authoritative. Amounts and states are the models' own; eligibility is
 * PettyCashActions, the same rules the controllers enforce. People are
 * `{id, name}` only.
 */
class PettyCashWorkspaceController extends Controller
{
    /** GET api/finance/petty-cash/finance/overview */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->can('viewAllRequisitions', Payment::class), 403, 'You do not have access to the petty cash workspace.');

        $sum = fn (Builder $q) => $this->money((clone $q)->sum('total_amount'));
        $status = fn (string ...$s) => PettyCashRequisition::query()->whereIn('status', $s)
            ->when(in_array('pending', $s, true) || in_array('approved', $s, true), fn ($q) => $q->where('verification_status', 'verified'));

        // Report 75R-B: one definition of an outstanding advance — money actually
        // out and not yet accounted for — shared with every other advance figure.
        $outstanding = PettyCashRequisition::query()->outstandingAdvances()->get();
        $dueSoon = PettyCashRequisition::dueSoonDays();
        $states = $outstanding->map(fn (PettyCashRequisition $r) => $r->surrenderState($dueSoon));

        $advanceFailures = PettyCashRequisition::query()->whereNotNull('advance_gl_posting_failed_at')
            ->latest('advance_gl_posting_failed_at')->limit(20)
            ->get(['id', 'requisition_number', 'total_amount', 'advance_gl_posting_failed_at', 'advance_gl_posting_error']);
        $paymentAdvanceFailures = Payment::query()->whereNotNull('advance_gl_posting_failed_at')->where('status', 'active')
            ->latest('advance_gl_posting_failed_at')->limit(20)
            ->get(['id', 'requisition_id', 'requisition_child_reference', 'amount', 'advance_gl_posting_failed_at', 'advance_gl_posting_error']);
        $costFailures = Payment::query()->whereNotNull('cost_gl_posting_failed_at')->where('status', '!=', 'voided')
            ->latest('cost_gl_posting_failed_at')->limit(20)
            ->get(['id', 'payment_no', 'amount', 'cost_gl_posting_failed_at', 'cost_gl_posting_error', 'requisition_id']);

        $balance = PettyCashBalance::current();
        $count = PettyCashCashCount::query()->latest('id')->first();
        $names = $this->names(collect([$balance->held_by, $count?->counted_by, $count?->reviewed_by]));

        return response()->json(['data' => [
            // The float only for those who may see it.
            'float' => $user->can('viewBalance', Payment::class) ? [
                'balance' => $this->money($balance->current_balance),
                // Thresholds are WNG's to set; null means not configured
                // (no built-in default is presented as policy here).
                'low_threshold' => $this->setting('petty_cash_low_balance_threshold'),
                'critical_threshold' => $this->setting('petty_cash_critical_balance_threshold'),
            ] : null,
            'pending_approval' => ['count' => $status('pending')->count(), 'amount' => $sum($status('pending'))],
            'approved_awaiting_disbursement' => ['count' => $status('approved')->count(), 'amount' => $sum($status('approved'))],
            'outstanding_advances' => [
                'count' => $outstanding->count(),
                'amount' => $this->money($outstanding->sum(fn (PettyCashRequisition $r) => (float) $r->advance_exposure)),
                'by_state' => $states->countBy()->all(),
                'overdue' => $states->filter(fn ($s) => $s === 'overdue')->count(),
                // W5-8: overdue exists only where a due date was set from an
                // approved policy; with none configured nothing is overdue.
                'surrender_due_days' => is_numeric($d = FinanceSetting::approvedValue('petty_cash_surrender_due_days')) ? (int) $d : null,
                'due_soon_days' => $dueSoon,
            ],
            'surrenders_awaiting_review' => $status('surrender_pending')->count(),
            'surrenders_returned' => $status('surrender_returned')->count(),
            'gl_posting_failures' => [
                // Both retries (advance and cost) are the reviewRequisition ability.
                'can_retry' => $user->can('reviewRequisition', Payment::class),
                'advances' => $advanceFailures->map(fn (PettyCashRequisition $r) => [
                    'requisition_id' => $r->id, 'reference' => $r->requisition_number, 'amount' => $this->money($r->total_amount),
                    'failed_at' => $r->advance_gl_posting_failed_at?->toIso8601String(), 'error' => $this->safeError($r->advance_gl_posting_error),
                ])->concat($paymentAdvanceFailures->map(fn (Payment $p) => [
                    // Report 75R-A: a receiver payment carries its own posting state.
                    'requisition_id' => $p->requisition_id, 'payment_id' => $p->id, 'reference' => $p->requisition_child_reference,
                    'amount' => $this->money($p->amount),
                    'failed_at' => $p->advance_gl_posting_failed_at?->toIso8601String(), 'error' => $this->safeError($p->advance_gl_posting_error),
                ]))->values(),
                'costs' => $costFailures->map(fn (Payment $p) => [
                    'payment_id' => $p->id, 'reference' => $p->payment_no, 'amount' => $this->money($p->amount), 'requisition_id' => $p->requisition_id,
                    'failed_at' => $p->cost_gl_posting_failed_at?->toIso8601String(), 'error' => $this->safeError($p->cost_gl_posting_error),
                ])->values(),
            ],
            // R-2: the custodian is WNG's decision. Null means nobody holds it.
            'custody' => ['held_by' => $this->person($names, $balance->held_by), 'configured' => (bool) $balance->held_by],
            'latest_cash_count' => $count ? [
                'id' => $count->id, 'counted_at' => $count->created_at?->toIso8601String(),
                'system_balance' => $this->money($count->system_balance), 'physical_cash' => $this->money($count->physical_cash),
                'variance' => $this->money($count->variance), 'counted_by' => $this->person($names, $count->counted_by),
                'reviewed_by' => $this->person($names, $count->reviewed_by), 'reviewed_at' => $count->reviewed_at?->toIso8601String(),
                'explanation' => $count->explanation,
            ] : null,
        ]]);
    }

    /** GET api/finance/petty-cash/finance/requisitions */
    public function requisitions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->can('viewAllRequisitions', Payment::class), 403, 'You do not have access to all petty cash requisitions.');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:awaiting_approval,approved,disbursed,funds_received,surrender_submitted,returned_for_correction,reconciled,rejected,gl_posting_failed,outstanding'],
            'classification' => ['nullable', 'in:project,overhead'],
            'department_id' => ['nullable', 'integer'],
            'requester_id' => ['nullable', 'integer'],
            'enquiry_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $statusFor = [
            'awaiting_approval' => ['pending'], 'approved' => ['approved'], 'disbursed' => ['disbursed'], 'funds_received' => ['received'],
            'surrender_submitted' => ['surrender_pending'], 'returned_for_correction' => ['surrender_returned'],
            'reconciled' => ['surrendered'], 'rejected' => ['rejected'], 'outstanding' => PettyCashRequisition::OUTSTANDING_ADVANCE_STATUSES,
        ];
        $page = PettyCashRequisition::query()
            ->with(['requester:id,name', 'department:id,name', 'enquiry:id,job_number,title', 'disbursement:id,requisition_id,amount,date_disbursed,payment_source_id,status'])
            ->when($filters['state'] ?? null, fn (Builder $q, $s) => $s === 'gl_posting_failed'
                ? $q->whereNotNull('advance_gl_posting_failed_at') : $q->whereIn('status', $statusFor[$s]))
            ->when(in_array($filters['state'] ?? null, ['awaiting_approval', 'approved'], true), fn ($q) => $q->where('verification_status', 'verified'))
            ->when($filters['classification'] ?? null, fn (Builder $q, $c) => $c === 'project'
                ? $q->where(fn (Builder $w) => $w->whereNotNull('enquiry_id')->orWhereNotNull('project_id'))
                : $q->whereNull('enquiry_id')->whereNull('project_id'))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($filters['requester_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['enquiry_id'] ?? null, fn (Builder $q, $id) => $q->where('enquiry_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('requisition_number', 'like', $like)->orWhere('purpose', 'like', $like)
                    ->orWhere('payee_name', 'like', $like)->orWhere('requester_name', 'like', $like)->orWhere('category', 'like', $like)
                    ->orWhereHas('requester', fn (Builder $r) => $r->where('name', 'like', $like)));
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $dueSoon = PettyCashRequisition::dueSoonDays();

        return response()->json([
            'data' => collect($page->items())->map(fn (PettyCashRequisition $r) => $this->row($user, $r, $dueSoon))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }

    /** GET api/finance/petty-cash/finance/requisitions/{id} — the Finance position of one requisition. */
    public function requisition(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $r = PettyCashRequisition::query()->with([
            'requester:id,name', 'department:id,name', 'enquiry:id,job_number,title', 'approver:id,name',
            'disbursement.paymentSource:id,code,name,type',
            'allSurrenderItems.expenseCode:id,code,simple_meaning',
            'advanceJournalEntry:id,entry_no,status', 'surrenderJournalEntry:id,entry_no,status',
        ])->findOrFail($id);
        abort_unless((int) $r->user_id === (int) $user->id || $user->can('viewAllRequisitions', Payment::class)
            // Report 75R-B: the verifier and an employee named as a receiver may read it too.
            || (int) $r->responsible_verifier_id === (int) $user->id
            || $r->items()->whereHas('payee.user', fn ($q) => $q->whereKey($user->id))->exists(), 403, 'You may only view your own requisitions.');

        $items = $r->allSurrenderItems;
        $current = $items->whereNull('superseded_at');
        $log = GovernanceAuditLog::query()->where('model_type', PettyCashRequisition::class)->where('model_id', $r->id)
            ->orderBy('created_at')->get(['id', 'user_id', 'gate_type', 'message', 'context', 'created_at']);
        $names = $this->names(collect([
            $r->surrendered_by, $r->surrender_returned_by, $r->surrender_resubmitted_by, $r->surrender_reconciled_by,
            $r->surrender_reversed_by, $r->disbursement?->created_by,
        ])->merge($items->pluck('duplicate_overridden_by'))->merge($log->pluck('user_id')));

        $advance = (float) ($r->disbursement?->amount ?? $r->total_amount);
        $spent = (float) ($r->actual_spent_amount ?? $current->sum('amount'));
        $returned = (float) ($r->cash_returned_amount ?? 0);

        // Report 75R-B: a requisition paid by receiver has several Payments and its
        // accountability is per receiver. The single-payment fields below are then
        // filled from the whole position, never from whichever Payment came first.
        $controls = app(\App\Modules\Finance\PettyCash\Services\RequisitionControlProjection::class)->forRequisition($r);
        $byReceiver = $controls['payment_mode'] === 'by_receiver';
        if ($byReceiver) {
            $advance = (float) $controls['disbursed'];
            $spent = (float) $controls['accounted'];
            $returned = (float) $controls['returned'];
        }
        $activePayments = collect($controls['payments'])->where('status', 'active')->values();

        return response()->json(['data' => array_merge($this->row($user, $r, PettyCashRequisition::dueSoonDays()), [
            'controls' => $controls,
            'approval' => [
                'approved_by' => $r->approver ? ['id' => $r->approver->id, 'name' => $r->approver->name] : null,
                'approved_at' => $r->approved_at?->toIso8601String(),
                'rejection_reason' => $r->rejection_reason,
            ],
            'disbursements' => $byReceiver ? $activePayments->map(fn (array $p) => [
                'id' => $p['id'], 'reference' => $p['reference'], 'child_reference' => $p['child_reference'],
                'amount' => $p['amount'], 'date' => $p['date'], 'source' => $p['source'], 'method' => $p['method'],
                'recipient' => $p['receiver'], 'recorded_by' => $p['recorded_by'],
            ])->all() : null,
            'disbursement' => $byReceiver ? ($activePayments->isEmpty() ? null : [
                'id' => null,
                'reference' => $activePayments->count() === 1 ? $activePayments[0]['child_reference'] : $activePayments->count().' payments',
                'amount' => $this->money($controls['disbursed']),
                'date' => $activePayments->min('date'),
                'source' => ($sources = $activePayments->pluck('source')->filter()->unique('id'))->count() === 1
                    ? ['id' => $sources->first()['id'], 'code' => '', 'name' => $sources->first()['name'], 'type' => null]
                    : ['id' => null, 'code' => '', 'name' => $sources->count().' paying accounts', 'type' => null],
                'method' => $activePayments->pluck('method')->unique()->count() === 1 ? $activePayments[0]['method'] : 'several methods',
                'recipient' => $activePayments->pluck('receiver')->filter()->unique()->count() === 1 ? $activePayments[0]['receiver'] : collect($controls['receivers'])->where('paid', '!=', '0.00')->count().' receivers',
                'status' => 'active',
                'recorded_by' => null,
            ]) : ($r->disbursement ? [
                'id' => $r->disbursement->id,
                'reference' => $r->disbursement->payment_no,
                'amount' => $this->money($r->disbursement->amount),
                'date' => $r->disbursement->date_disbursed ? \Illuminate\Support\Carbon::parse($r->disbursement->date_disbursed)->toDateString() : null,
                'source' => $r->disbursement->paymentSource?->only(['id', 'code', 'name', 'type']),
                'method' => $r->disbursement->payment_method,
                'recipient' => $r->payee_name ?: $r->requester?->name,
                'status' => $r->disbursement->status,
                'recorded_by' => $this->person($names, $r->disbursement->created_by),
            ] : null),
            // STAB-4: a disbursement stays recorded when its journal fails;
            // the failure is shown until an idempotent retry clears it.
            'posting' => [
                'advance_entry' => $r->advanceJournalEntry?->only(['entry_no', 'status']),
                'advance_failed_at' => $r->advance_gl_posting_failed_at?->toIso8601String(),
                'advance_error' => $this->safeError($r->advance_gl_posting_error),
                'surrender_entry' => $r->surrenderJournalEntry?->only(['entry_no', 'status']),
            ],
            'surrender' => [
                'state' => $r->surrenderState(PettyCashRequisition::dueSoonDays()),
                'due_at' => $r->surrender_due_at?->toDateString(),
                'advance' => $this->money($advance),
                'spent' => $this->money($spent),
                'cash_returned' => $this->money($returned),
                // Backend figures: the advance less what was spent and returned.
                'unaccounted' => $this->money($advance - $spent - $returned),
                'notes' => $r->surrender_notes,
                'submitted_by' => $this->person($names, $r->surrendered_by), 'submitted_at' => $r->surrendered_at?->toIso8601String(),
                'returned_by' => $this->person($names, $r->surrender_returned_by), 'returned_at' => $r->surrender_returned_at?->toIso8601String(),
                'return_reason' => $r->surrender_return_reason,
                'resubmitted_by' => $this->person($names, $r->surrender_resubmitted_by), 'resubmitted_at' => $r->surrender_resubmitted_at?->toIso8601String(),
                'reconciled_by' => $this->person($names, $r->surrender_reconciled_by), 'reconciled_at' => $r->surrender_reconciled_at?->toIso8601String(),
                'reversed_by' => $this->person($names, $r->surrender_reversed_by), 'reversed_at' => $r->surrender_reversed_at?->toIso8601String(),
                'reversal_reason' => $r->surrender_reversal_reason,
                'items' => $items->map(fn (PettyCashSurrenderItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'amount' => $this->money($item->amount),
                    'net' => $this->money($item->net_amount),
                    'tax' => $this->money($item->tax_amount),
                    'expense_code' => $item->expenseCode?->only(['id', 'code', 'simple_meaning']),
                    'receipt_type' => $item->receipt_type,
                    'receipt_number' => $item->receipt_number,
                    'supplier_name' => $item->supplier_name,
                    'cost_line_id' => $item->cost_line_id,
                    'superseded' => (bool) $item->superseded_at,
                    'duplicate_override' => ($item->duplicate_of_surrender_item_id || $item->duplicate_of_payment_id) ? [
                        'reason' => $item->duplicate_override_reason,
                        'by' => $this->person($names, $item->duplicate_overridden_by),
                        'at' => $item->duplicate_overridden_at ? \Illuminate\Support\Carbon::parse($item->duplicate_overridden_at)->toIso8601String() : null,
                    ] : null,
                ])->values(),
            ],
            'evidence_policy' => [
                // W3-4: no monetary evidence threshold is configured by WNG.
                'threshold' => $this->setting('petty_cash_evidence_threshold'),
            ],
            'audit' => $log->map(fn (GovernanceAuditLog $l) => [
                'id' => $l->id, 'event' => $l->gate_type, 'message' => $l->message,
                'reason' => is_array($l->context) ? ($l->context['reason'] ?? null) : null,
                'by' => $this->person($names, $l->user_id), 'at' => $l->created_at?->toIso8601String(),
            ])->values(),
        ])]);
    }

    // ── Evidence (generic Finance attachments) ──────────────────────────

    /** GET …/requisitions/{id}/attachments */
    public function attachments(Request $request, int $id): JsonResponse
    {
        $r = $this->visible($request, $id);

        return response()->json(['data' => $r->attachments()->with('uploader:id,name')->latest()->get()->map(fn (FinanceAttachment $a) => $this->present($a))]);
    }

    /** POST …/requisitions/{id}/attachments — the requester or Finance may add evidence. */
    public function storeAttachment(Request $request, int $id, FinanceAttachmentService $service): JsonResponse
    {
        $r = $this->visible($request, $id);
        abort_unless((int) $r->user_id === (int) $request->user()->id || $request->user()->can('create', Payment::class), 403,
            'Only the requester or Finance can add evidence to this requisition.');
        $data = $request->validate([
            'evidence_type' => ['required', 'in:receipt,quotation,invoice,other'],
            'file' => ['nullable', 'file', 'max:10240', 'required_without:reference'],
            'reference' => ['nullable', 'string', 'max:255', 'required_without:file'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $attachment = $request->hasFile('file')
            ? $service->attachFile(PettyCashRequisition::class, $r->id, $request->file('file'), (int) $request->user()->id, $data['evidence_type'], $data['description'] ?? null)
            : $service->attachReference(PettyCashRequisition::class, $r->id, (int) $request->user()->id, $data['reference'] ?? null, $data['evidence_type'], $data['description'] ?? null);

        return response()->json(['message' => 'Evidence attached.', 'data' => $this->present($attachment->load('uploader:id,name'))], 201);
    }

    /** GET …/requisitions/{id}/attachments/{attachment}/download */
    public function downloadAttachment(Request $request, int $id, int $attachment): BinaryFileResponse
    {
        $r = $this->visible($request, $id);
        $file = FinanceAttachment::query()->where('source_type', PettyCashRequisition::class)->where('source_id', $r->id)->findOrFail($attachment);
        abort_unless(filled($file->file_path) && Storage::disk('local')->exists($file->file_path), 404);

        return response()->download(Storage::disk('local')->path($file->file_path), $file->original_filename,
            ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function row(User $user, PettyCashRequisition $r, ?int $dueSoon): array
    {
        $actions = PettyCashActions::forRequisition($user, $r);
        $next = collect(['retry_posting', 'approve', 'disburse', 'reconcile_surrender', 'confirm_receipt', 'submit_surrender'])
            ->first(fn (string $key) => $actions[$key]['allowed']);

        return [
            'id' => $r->id,
            'reference' => $r->requisition_number,
            'requester' => $r->requester ? ['id' => $r->requester->id, 'name' => $r->requester->name]
                : ($r->requester_name ? ['id' => null, 'name' => $r->requester_name] : null),
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'classification' => ($r->enquiry_id || $r->project_id) ? 'project' : 'overhead',
            'project' => $r->enquiry ? ['id' => $r->enquiry->id, 'job_number' => $r->enquiry->job_number, 'title' => $r->enquiry->title]
                : ($r->project_name ? ['id' => null, 'job_number' => null, 'title' => $r->project_name] : null),
            'type' => $r->category,
            'purpose' => $r->purpose,
            'payee' => $r->payee_name,
            'amount' => $this->money($r->total_amount),
            'status' => $r->status,
            'state' => PettyCashActions::state($r),
            'surrender_state' => $r->surrenderState($dueSoon),
            'created_at' => $r->created_at?->toIso8601String(),
            'disbursed' => $this->disbursedSummary($r),
            'gl_posting_failed' => (bool) $r->advance_gl_posting_failed_at,
            'actions' => $actions,
            'next_action' => $next,
        ];
    }

    /**
     * What a list row says was paid. Report 75R-B: for a requisition paid by
     * receiver this is every active Payment, not the first one.
     */
    private function disbursedSummary(PettyCashRequisition $r): ?array
    {
        $first = $r->disbursement;
        if (! $first) {
            return null;
        }
        if (! $first->requisition_child_reference) {
            return ['amount' => $this->money($first->amount),
                'date' => $first->date_disbursed ? \Illuminate\Support\Carbon::parse($first->date_disbursed)->toDateString() : null];
        }
        $active = $r->disbursements()->where('status', 'active')->get(['amount', 'date_disbursed']);
        if ($active->isEmpty()) {
            return null;
        }

        return ['amount' => $this->money($active->sum('amount')), 'payments' => $active->count(),
            'date' => \Illuminate\Support\Carbon::parse($active->min('date_disbursed'))->toDateString()];
    }

    private function visible(Request $request, int $id): PettyCashRequisition
    {
        $r = PettyCashRequisition::findOrFail($id);
        abort_unless((int) $r->user_id === (int) $request->user()->id || $request->user()->can('viewAllRequisitions', Payment::class), 403,
            'You may only view your own requisitions.');

        return $r;
    }

    private function present(FinanceAttachment $a): array
    {
        return [
            'id' => $a->id, 'evidence_type' => $a->evidence_type, 'reference' => $a->reference, 'description' => $a->description,
            'original_filename' => $a->original_filename, 'uploader' => $a->uploader ? ['id' => $a->uploader->id, 'name' => $a->uploader->name] : null,
            'created_at' => $a->created_at?->toIso8601String(), 'downloadable' => filled($a->file_path),
        ];
    }

    /** A posting error is shown as a sentence, never as an internal exception. */
    private function safeError(?string $error): ?string
    {
        if (! $error) {
            return null;
        }

        return preg_match('/SQLSTATE|Exception|Stack trace|\.php|Undefined|Call to/i', $error)
            ? 'The journal could not be posted. Retry, or ask Finance support to inspect the posting log.'
            : mb_substr($error, 0, 300);
    }

    private function setting(string $key): ?string
    {
        $value = FinanceSetting::approvedValue($key);

        return is_numeric($value) ? $this->money($value) : null;
    }

    private function names(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function person(Collection $names, mixed $id): ?array
    {
        return $id && $names->has((int) $id) ? ['id' => (int) $id, 'name' => $names[(int) $id]] : null;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * Report 75R-A: every Payment made against a requisition — by parent,
     * receiver, child reference, PAY reference, project, requester or verifier.
     */
    public function requisitionPayments(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAllRequisitions', Payment::class), 403);
        $filters = $request->validate([
            'requisition_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'],
            'receiver_type' => ['nullable', 'in:employee,supplier,other'], 'receiver_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,reversed'], 'instalments' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'in:confirmed,unconfirmed'],
            'project_id' => ['nullable', 'integer'], 'enquiry_id' => ['nullable', 'integer'],
            'requester_id' => ['nullable', 'integer'], 'verifier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = app(\App\Modules\Finance\PettyCash\Services\RequisitionDisbursementReport::class)
            ->payments($filters, (int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** Report 75R-A: approved against disbursed, with each receiver's outstanding balance. */
    public function receiverBalances(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAllRequisitions', Payment::class), 403);
        $filters = $request->validate([
            'disbursement_status' => ['nullable', 'in:not_disbursed,partially_disbursed,fully_disbursed,over_disbursement_exception'],
            'project_id' => ['nullable', 'integer'], 'enquiry_id' => ['nullable', 'integer'],
            'requester_id' => ['nullable', 'integer'], 'verifier_id' => ['nullable', 'integer'],
            'include_closed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = app(\App\Modules\Finance\PettyCash\Services\RequisitionDisbursementReport::class)
            ->receiverBalances($filters, (int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /**
     * Report 75R-B: read-only. Open, unpaid requisitions against the receiver
     * rules, and what each one needs before it can be paid.
     */
    public function receiverCompatibility(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAllRequisitions', Payment::class), 403);
        $rows = app(\App\Modules\Finance\PettyCash\Services\RequisitionReceiverCompatibility::class)->report();

        return response()->json(['data' => $rows, 'summary' => collect($rows)->countBy('classification')]);
    }
}
