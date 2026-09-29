<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\SpendVoucherReview;
use App\Modules\Finance\Services\FinanceAttachmentService;
use App\Modules\Finance\Support\SpendVoucherActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * W4 Payment vouchers workspace, read side (Report 63).
 *
 * Every workflow change stays on SpendVoucherController and
 * PaymentController::reverse. This adds what a person needs to decide: the
 * voucher's state kept apart from its approval, posting and payment, the
 * liabilities it pays with their project or overhead context, who did what and
 * when, and per voucher the actions the current user may take, with the
 * backend's own reason when they may not.
 *
 * Identities are {id, name}. Nothing here serialises a full user or employee.
 */
class SpendVoucherWorkspaceController extends Controller
{
    /** Filter groups: a state a person looks for, not a raw column value. */
    private const STATE_GROUPS = [
        'awaiting_approval', 'returned', 'awaiting_senior_approval', 'approved', 'posted', 'reversed', 'rejected', 'cancelled',
    ];

    /** GET api/finance/spend-vouchers/register */
    public function register(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:'.implode(',', self::STATE_GROUPS)],
            'requester_id' => ['nullable', 'integer'],
            'job_number' => ['nullable', 'string', 'max:60'],
            'classification' => ['nullable', 'in:project,overhead'],
            'cost_centre_id' => ['nullable', 'integer'],
            'payment_source_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SpendVoucher::query()->with(['paymentSource:id,code,name,type', 'allocations.costLine' => fn ($q) => $q
            ->select('id', 'ref', 'description', 'job_number', 'project_enquiry_id', 'cost_centre_id')]);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn (Builder $q) => $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('payee_name', 'like', "%{$search}%")
                ->orWhere('payment_reference', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%"));
        }
        if ($state = $filters['state'] ?? null) {
            $this->whereState($query, $state);
        }
        if ($requester = $filters['requester_id'] ?? null) {
            $query->where('requester_user_id', $requester);
        }
        if ($job = $filters['job_number'] ?? null) {
            $query->whereHas('allocations.costLine', fn (Builder $q) => $q->where('job_number', 'like', "%{$job}%"));
        }
        if (($filters['classification'] ?? null) === 'project') {
            $query->whereHas('allocations.costLine', fn (Builder $q) => $this->onProject($q));
        } elseif (($filters['classification'] ?? null) === 'overhead') {
            $query->whereDoesntHave('allocations.costLine', fn (Builder $q) => $this->onProject($q));
        }
        if ($centre = $filters['cost_centre_id'] ?? null) {
            $query->whereHas('allocations.costLine', fn (Builder $q) => $q->where('cost_centre_id', $centre));
        }
        if ($source = $filters['payment_source_id'] ?? null) {
            $query->where('payment_source_id', $source);
        }
        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        $vouchers = collect($page->items());
        $people = $this->people($vouchers->pluck('requester_user_id'));
        $payments = $this->payments($vouchers);

        return response()->json([
            'data' => $vouchers->map(fn (SpendVoucher $v) => $this->row($request->user(), $v, $people, $payments->get($v->id)))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'summary' => $this->summary(),
            'senior_policy' => SpendVoucherActions::seniorPolicy(),
        ]);
    }

    /** GET api/finance/spend-vouchers/{id}/detail */
    public function detail(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $voucher = SpendVoucher::query()->with(['paymentSource:id,code,name,type', 'reviews'])->findOrFail($id);
        $payment = $this->payments(collect([$voucher]))->get($voucher->id);
        $reviews = $voucher->reviews;
        $people = $this->people(collect([
            $voucher->requester_user_id, $voucher->approved_by, $voucher->senior_approved_by, $voucher->returned_by,
            $voucher->resubmitted_by, $voucher->rejected_by, $voucher->posted_by, $payment?->voided_by,
        ])->merge($reviews->pluck('actor_user_id')));
        $person = fn ($uid) => $uid ? ($people[(int) $uid] ?? ['id' => (int) $uid, 'name' => 'Former user']) : null;

        $lines = CostLine::query()->withReferenceNames()->with('expenseCode:id,code,expense_type,expense_family')
            ->whereIn('id', $voucher->allocations()->pluck('cost_line_id'))->get()->keyBy('id');
        $allocations = $voucher->allocations()->get()->map(function ($allocation) use ($lines) {
            $line = $lines->get($allocation->cost_line_id);

            return [
                'amount' => number_format((float) $allocation->amount, 2, '.', ''),
                'cost_line' => $line ? [
                    'id' => $line->id,
                    'ref' => $line->ref,
                    'description' => $line->description,
                    'classification' => $this->isProject($line) ? 'project' : 'overhead',
                    'job_number' => $line->job_number,
                    'cost_centre' => $line->cost_centre_name,
                    'expense_code' => $line->expenseCode ? [
                        'code' => $line->expenseCode->code, 'name' => $line->expenseCode->expense_type,
                        'family' => $line->expenseCode->expense_family,
                    ] : null,
                    'status' => $line->status,
                    'incurred_at' => $line->incurred_at?->toDateString(),
                    'payable' => bcsub(bcadd((string) ($line->net_amount ?? 0), (string) ($line->tax_amount ?? 0), 2), (string) ($line->wht_amount ?? 0), 2),
                    'recognised_by' => $line->journal_entry_no,
                ] : null,
            ];
        })->values();

        $entry = JournalEntry::query()->where('spend_voucher_id', $voucher->id)->whereNull('reversal_of_id')->first();
        $reversal = $entry ? JournalEntry::query()->where('reversal_of_id', $entry->id)->first() : null;

        $row = $this->row($request->user(), $voucher, $people, $payment);
        $senior = $row['senior'];

        return response()->json(['data' => array_merge($row, [
            'voucher' => [
                'type' => $voucher->type,
                'notes' => $voucher->notes,
                'supplier_invoice_no' => $voucher->supplier_invoice_no,
                'etims_invoice_no' => $voucher->etims_invoice_no,
                'payee_phone' => $voucher->payee_phone,
                'payment_method' => $voucher->payment_method,
                'payment_reference' => $voucher->payment_reference,
                'transaction_cost' => number_format((float) ($voucher->transaction_cost ?? 0), 2, '.', ''),
                'posting_date' => $voucher->posting_date?->toDateString(),
            ],
            'workflow' => [
                'requested_by' => $person($voucher->requester_user_id), 'requested_at' => $voucher->created_at?->toIso8601String(),
                'returned_by' => $person($voucher->returned_by), 'returned_at' => $voucher->returned_at?->toIso8601String(), 'return_reason' => $voucher->return_reason,
                'resubmitted_by' => $person($voucher->resubmitted_by), 'resubmitted_at' => $voucher->resubmitted_at?->toIso8601String(),
                'approved_by' => $person($voucher->approved_by), 'approved_at' => $voucher->approved_at?->toIso8601String(),
                'senior_approved_by' => $person($voucher->senior_approved_by), 'senior_approved_at' => $voucher->senior_approved_at?->toIso8601String(),
                'rejected_by' => $person($voucher->rejected_by), 'rejected_at' => $voucher->rejected_at?->toIso8601String(), 'rejection_reason' => $voucher->rejection_reason,
                'posted_by' => $person($voucher->posted_by), 'posted_at' => $voucher->posted_at?->toIso8601String(),
            ],
            'history' => $this->history($voucher, $reviews, $payment, $person),
            'allocations' => $allocations,
            'accounting' => [
                'entry' => $entry ? $this->entry($entry) : null,
                'reversal' => $reversal ? $this->entry($reversal) : null,
            ],
            'payment' => $payment ? [
                'id' => $payment->id,
                'payment_no' => $payment->payment_no,
                'status' => $payment->status,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'transaction_cost' => number_format((float) ($payment->transaction_cost ?? 0), 2, '.', ''),
                'date' => $payment->date_disbursed ? \Illuminate\Support\Carbon::parse($payment->date_disbursed)->toDateString() : null,
                'method' => $payment->payment_method,
                'reference' => $payment->external_reference,
                'source' => $voucher->paymentSource ? $voucher->paymentSource->only(['id', 'code', 'name', 'type']) : null,
                'voided_by' => $person($payment->voided_by),
                'voided_at' => $payment->voided_at ? \Illuminate\Support\Carbon::parse($payment->voided_at)->toIso8601String() : null,
                'void_reason' => $payment->void_reason,
            ] : null,
            'senior' => $senior,
            'attachments' => FinanceAttachment::query()->with('uploader:id,name')
                ->where('source_type', SpendVoucher::class)->where('source_id', $voucher->id)->latest()->get()
                ->map(fn (FinanceAttachment $a) => $this->presentAttachment($a))->values(),
            'can_add_evidence' => $this->mayAddEvidence($request->user(), $voucher),
        ])]);
    }

    /** POST api/finance/spend-vouchers/{id}/attachments */
    public function storeAttachment(Request $request, int $id, FinanceAttachmentService $service): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);
        $voucher = SpendVoucher::query()->findOrFail($id);
        abort_unless($this->mayAddEvidence($request->user(), $voucher), 403,
            'Only the requester or someone who approves or posts vouchers can add evidence.');

        $data = $request->validate([
            'evidence_type' => ['required', 'in:invoice,receipt,quotation,other'],
            'file' => ['nullable', 'file', 'max:10240', 'required_without:reference'],
            'reference' => ['nullable', 'string', 'max:255', 'required_without:file'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $uid = (int) $request->user()->id;
        $attachment = $request->hasFile('file')
            ? $service->attachFile(SpendVoucher::class, $voucher->id, $request->file('file'), $uid, $data['evidence_type'], $data['description'] ?? null)
            : $service->attachReference(SpendVoucher::class, $voucher->id, $uid, $data['reference'] ?? null, $data['evidence_type'], $data['description'] ?? null);

        return response()->json(['message' => 'Evidence attached.', 'data' => $this->presentAttachment($attachment->load('uploader:id,name'))], 201);
    }

    /** GET api/finance/spend-vouchers/{id}/attachments/{attachment}/download */
    public function downloadAttachment(Request $request, int $id, int $attachment): BinaryFileResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);
        $file = FinanceAttachment::query()->where('source_type', SpendVoucher::class)->where('source_id', $id)->findOrFail($attachment);
        abort_unless(filled($file->file_path) && Storage::disk('local')->exists($file->file_path), 404);

        return response()->download(Storage::disk('local')->path($file->file_path), $file->original_filename,
            ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function row(User $user, SpendVoucher $v, array $people, ?Payment $payment): array
    {
        $actions = SpendVoucherActions::forVoucher($user, $v, $payment);
        $lines = $v->relationLoaded('allocations')
            ? $v->allocations->map->costLine->filter()
            : CostLine::query()->whereIn('id', $v->allocations()->pluck('cost_line_id'))->get(['id', 'description', 'job_number', 'project_enquiry_id']);
        $jobs = $lines->pluck('job_number')->filter()->unique()->values();
        $project = $lines->contains(fn ($line) => $this->isProject($line));
        $overhead = $lines->contains(fn ($line) => ! $this->isProject($line));

        return [
            'id' => $v->id,
            'voucher_no' => $v->voucher_no,
            'type' => $v->type,
            'payee' => $v->payee_name,
            'purpose' => $v->notes ?: $lines->pluck('description')->filter()->first(),
            'classification' => $project && $overhead ? 'mixed' : ($project ? 'project' : 'overhead'),
            'job_numbers' => $jobs,
            'amount' => number_format((float) $v->total_amount, 2, '.', ''),
            'currency' => $v->currency ?: 'KES',
            'state' => SpendVoucherActions::state($v),
            'facets' => SpendVoucherActions::facets($v, $payment),
            'requester' => $v->requester_user_id ? ($people[(int) $v->requester_user_id] ?? ['id' => (int) $v->requester_user_id, 'name' => 'Former user']) : null,
            'created_at' => $v->created_at?->toIso8601String(),
            'source' => $v->paymentSource ? $v->paymentSource->only(['id', 'code', 'name', 'type']) : null,
            'payment_no' => $payment?->payment_no,
            'liabilities' => $lines->count(),
            'senior' => $this->senior($v),
            'actions' => $actions,
            'next_action' => collect(['post', 'senior_approve', 'approve', 'correct', 'resubmit'])
                ->first(fn (string $key) => $actions[$key]['allowed']),
        ];
    }

    /**
     * W4-2 for this voucher. The threshold is applied when the voucher is
     * approved; before that, `would_require` says what approval will do today.
     */
    private function senior(SpendVoucher $v): array
    {
        $policy = SpendVoucherActions::seniorPolicy();
        $awaiting = in_array($v->status, ['pending_approval', 'draft'], true);

        return [
            'policy' => $policy['state'],
            'threshold' => $policy['threshold'],
            'required' => $v->review_state === 'awaiting_senior_approval' || $v->senior_approved_at !== null,
            'would_require' => $awaiting && $policy['threshold'] !== null
                && bccomp((string) $v->total_amount, $policy['threshold'], 2) === 1,
            'approved' => $v->senior_approved_at !== null,
        ];
    }

    /** Recorded events only, oldest first. Nothing is inferred. */
    private function history(SpendVoucher $v, Collection $reviews, ?Payment $payment, callable $person): array
    {
        $events = [['event' => 'created', 'by' => $person($v->requester_user_id), 'at' => $v->created_at?->toIso8601String(), 'reason' => null]];
        foreach ($reviews as $review) {
            /** @var SpendVoucherReview $review */
            $events[] = ['event' => $review->action, 'by' => $person($review->actor_user_id),
                'at' => $review->created_at?->toIso8601String(), 'reason' => $review->reason];
        }
        if ($v->status === 'rejected' && ! $v->rejected_by) {
            $events[] = ['event' => 'cancelled', 'by' => $person($v->requester_user_id), 'at' => $v->updated_at?->toIso8601String(), 'reason' => null];
        }
        if ($v->posted_at) {
            $events[] = ['event' => 'posted', 'by' => $person($v->posted_by), 'at' => $v->posted_at->toIso8601String(), 'reason' => null];
        }
        if ($payment?->voided_at) {
            $events[] = ['event' => 'reversed', 'by' => $person($payment->voided_by),
                'at' => \Illuminate\Support\Carbon::parse($payment->voided_at)->toIso8601String(), 'reason' => $payment->void_reason];
        }

        return $events;
    }

    private function entry(JournalEntry $entry): array
    {
        $lines = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_entry_id', $entry->id)->orderBy('l.id')
            ->get(['a.code', 'a.name', 'l.entry_type', 'l.amount']);

        return [
            'entry_no' => $entry->entry_no,
            'status' => $entry->status,
            'posting_date' => $entry->posting_date ? \Illuminate\Support\Carbon::parse($entry->posting_date)->toDateString() : null,
            'lines' => $lines->map(fn ($line) => [
                'account' => "{$line->code} {$line->name}",
                'debit' => $line->entry_type === 'debit' ? number_format((float) $line->amount, 2, '.', '') : null,
                'credit' => $line->entry_type === 'credit' ? number_format((float) $line->amount, 2, '.', '') : null,
            ])->values(),
        ];
    }

    /** Counts per state group, across all vouchers; they do not move with the filters. */
    private function summary(): array
    {
        $counts = [];
        foreach (self::STATE_GROUPS as $group) {
            $counts[$group] = $this->whereState(SpendVoucher::query(), $group)->count();
        }
        $oldestApproved = $this->whereState(SpendVoucher::query(), 'approved')->min('approved_at');

        return [
            'counts' => $counts,
            'approved_amount' => number_format((float) $this->whereState(SpendVoucher::query(), 'approved')->sum('total_amount'), 2, '.', ''),
            'oldest_approved_days' => $oldestApproved ? (int) \Illuminate\Support\Carbon::parse($oldestApproved)->diffInDays(now()) : null,
        ];
    }

    private function whereState(Builder $query, string $group): Builder
    {
        $awaiting = ['pending_approval', 'draft'];
        $returned = ['returned_for_correction', 'corrected'];

        return match ($group) {
            'awaiting_approval' => $query->whereIn('status', $awaiting)
                ->where(fn (Builder $q) => $q->whereNull('review_state')->orWhereNotIn('review_state', $returned)),
            'returned' => $query->whereIn('status', $awaiting)->whereIn('review_state', $returned),
            'awaiting_senior_approval' => $query->where('status', 'approved')->where('review_state', 'awaiting_senior_approval'),
            'approved' => $query->where('status', 'approved')->whereNull('posted_at')
                ->where(fn (Builder $q) => $q->whereNull('review_state')->orWhere('review_state', '!=', 'awaiting_senior_approval')),
            'posted' => $query->where('status', 'posted'),
            'reversed' => $query->where('status', 'reversed'),
            'rejected' => $query->where('status', 'rejected')->whereNotNull('rejected_by'),
            'cancelled' => $query->where('status', 'rejected')->whereNull('rejected_by'),
        };
    }

    private function onProject(Builder $q): Builder
    {
        return $q->where(fn (Builder $r) => $r->whereNotNull('job_number')->where('job_number', '!=', '')
            ->orWhereNotNull('project_enquiry_id'));
    }

    private function isProject(CostLine $line): bool
    {
        return filled($line->job_number) || $line->project_enquiry_id !== null;
    }

    /** @return array<int, array{id: int, name: string}> */
    private function people(Collection $ids): array
    {
        return User::query()->whereIn('id', $ids->filter()->map(fn ($id) => (int) $id)->unique())->get(['id', 'name'])
            ->mapWithKeys(fn (User $u) => [$u->id => ['id' => $u->id, 'name' => $u->name]])->all();
    }

    /** The cash document each voucher minted, keyed by voucher id. */
    private function payments(Collection $vouchers): Collection
    {
        $ids = $vouchers->pluck('id');
        $byLink = $vouchers->pluck('petty_cash_disbursement_id')->filter();
        if ($ids->isEmpty()) {
            return collect();
        }

        $payments = Payment::query()->where(fn (Builder $q) => $q->whereIn('spend_voucher_id', $ids)->orWhereIn('id', $byLink))
            ->get(['id', 'payment_no', 'spend_voucher_id', 'status', 'amount', 'transaction_cost', 'date_disbursed',
                'payment_method', 'external_reference', 'voided_by', 'voided_at', 'void_reason']);

        return $vouchers->mapWithKeys(fn (SpendVoucher $v) => [$v->id => $payments->first(
            fn (Payment $p) => (int) $p->spend_voucher_id === $v->id || $p->id === (int) $v->petty_cash_disbursement_id,
        )])->filter();
    }

    private function mayAddEvidence(User $user, SpendVoucher $voucher): bool
    {
        return (int) $voucher->requester_user_id === (int) $user->id
            || $user->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE)
            || $user->can(Permissions::FINANCE_SPEND_VOUCHERS_POST);
    }

    private function presentAttachment(FinanceAttachment $a): array
    {
        return [
            'id' => $a->id, 'evidence_type' => $a->evidence_type, 'reference' => $a->reference, 'description' => $a->description,
            'original_filename' => $a->original_filename, 'uploader' => $a->uploader ? ['id' => $a->uploader->id, 'name' => $a->uploader->name] : null,
            'created_at' => $a->created_at?->toIso8601String(), 'downloadable' => filled($a->file_path),
        ];
    }
}
