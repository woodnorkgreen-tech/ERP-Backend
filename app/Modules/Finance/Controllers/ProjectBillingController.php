<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\EnquiryPayment;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\Models\ClientReceipt;
use App\Modules\Finance\Services\ClientFinancialPositionService;
use App\Modules\Finance\Support\ReceivablesActions;
use App\Modules\Projects\Services\FinanceService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET api/finance/project-billing/{enquiry} — one project's billing controls
 * (Report 59), replacing the reads the retired EnquiryFinanceModal made.
 *
 * Read-only. It composes the existing authorities and adds no calculation:
 * FinanceService::getPaymentProgress() for the commercial basis and deposit
 * gate, ClientFinancialPositionService for the money position, the project's
 * GovernanceAuditLog for who did what, and ReceivablesActions for what the
 * current user may do. Every change still goes through the per-project
 * routes on EnquiryController. Invoices and receipts are NOT repeated here:
 * the Finance invoice and receipt workspaces are their one home.
 */
class ProjectBillingController extends Controller
{
    public function __construct(
        private FinanceService $finance,
        private ClientFinancialPositionService $position,
        private ProjectFinancialAccess $access,
    ) {
    }

    public function show(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canReadReceivables($user, $enquiry), 403, 'You do not have access to this project\'s billing.');

        $enquiry->loadMissing('client:id,full_name,company_name');
        $progress = $this->finance->getPaymentProgress($enquiry);
        $logs = GovernanceAuditLog::query()->where('project_enquiry_id', $enquiry->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'user_id', 'gate_type', 'action_status', 'message', 'context', 'created_at']);
        $receipts = $this->splitReceipts($enquiry);

        $names = $this->names(collect([$enquiry->quote_waived_by, $enquiry->project_officer_id, $enquiry->assigned_po])
            ->merge($logs->pluck('user_id'))
            ->merge($receipts->flatMap(fn (array $r) => $r['recorded_by_id'] ? [$r['recorded_by_id']] : [])));

        // The release is the gate log ReleaseFinanceGateAction writes (it is the
        // only financial log carrying `threshold_met`); the terms change is the
        // log updateReceivablesTerms writes.
        $release = $logs->first(fn (GovernanceAuditLog $log) => $log->gate_type === 'financial'
            && is_array($log->context) && array_key_exists('threshold_met', $log->context));
        $termsChange = $logs->firstWhere('gate_type', 'Receivables Terms');

        return response()->json(['data' => [
            'project' => [
                'id' => $enquiry->id,
                'job_number' => $enquiry->job_number,
                'enquiry_number' => $enquiry->enquiry_number,
                'title' => $enquiry->title,
                'status' => $enquiry->status,
                'client' => $enquiry->client ? ['id' => $enquiry->client->id, 'name' => $enquiry->client->company_name ?: $enquiry->client->full_name] : null,
                'project_officer' => $this->person($names, $enquiry->project_officer_id ?: $enquiry->assigned_po),
            ],
            'commercial_basis' => [
                'amount' => $this->money($progress['total_quote']),
                'basis' => $progress['quote_basis'],
                'source_label' => $progress['quote_source_label'],
                'has_approved_quote' => (bool) $progress['has_approved_quote'],
                'waived' => (bool) $progress['quote_requirement_waived'],
                'established' => (bool) $progress['can_record_payments'],
                // No approved quote: billing is only possible through the
                // controlled exception (the quote waiver below).
                'exception_required' => ! $progress['has_approved_quote'],
                'approval' => $progress['quote_approval'] ? [
                    'approved_by' => $progress['quote_approval']['approved_by'] ?? null,
                    'approved_at' => $progress['quote_approval']['approved_at'] ?? null,
                ] : null,
                'waiver' => $enquiry->quote_requirement_waived ? [
                    'amount' => $this->money($enquiry->quote_waiver_billing_amount),
                    'reason' => $enquiry->quote_waiver_reason,
                    'by' => $this->person($names, $enquiry->quote_waived_by),
                    'at' => $enquiry->quote_waived_at?->toIso8601String(),
                ] : null,
            ],
            'deposit' => [
                'threshold_percentage' => (float) $progress['threshold_percentage'],
                'threshold_amount' => $this->money($progress['threshold_amount']),
                'verified_amount' => $this->money($progress['total_paid']),
                'percentage' => (float) $progress['percentage'],
                'amount_required' => $this->money($progress['amount_required_for_threshold']),
                'is_threshold_met' => (bool) $progress['is_threshold_met'],
                'pending_receipts' => (int) $progress['pending_payment_count'],
                'last_change' => $termsChange ? [
                    'by' => $this->person($names, $termsChange->user_id),
                    'at' => $termsChange->created_at?->toIso8601String(),
                    'reason' => $termsChange->context['reason'] ?? null,
                    'from' => $termsChange->context['old_threshold'] ?? null,
                    'to' => $termsChange->context['new_threshold'] ?? null,
                ] : null,
            ],
            'production' => [
                'released' => (bool) $progress['finance_released'],
                'released_at' => $progress['finance_released_at'],
                'released_by' => $release ? $this->person($names, $release->user_id) : null,
                'early' => $release ? ! ($release->context['threshold_met'] ?? true) : false,
                'reason' => $release?->context['reason'] ?? null,
                'post_release_breach' => (bool) $progress['is_post_release_breach'],
            ],
            // The money position needs the Finance read permission, like its
            // own endpoint; a project-account reader sees the controls only.
            'position' => $user->can(Permissions::FINANCE_RECEIVABLES_READ) ? $this->position->forEnquiry($enquiry) : null,
            'split_receipts' => $receipts->map(fn (array $r) => array_merge(
                collect($r)->except('recorded_by_id')->all(),
                ['recorded_by' => $this->person($names, $r['recorded_by_id'])],
            ))->values(),
            'history' => $logs->map(fn (GovernanceAuditLog $log) => [
                'id' => $log->id,
                'event' => $log->gate_type,
                'status' => $log->action_status,
                'message' => $log->message,
                'reason' => is_array($log->context) ? ($log->context['reason'] ?? null) : null,
                'by' => $this->person($names, $log->user_id),
                'at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'actions' => ReceivablesActions::forProjectBilling($user, $progress),
        ]]);
    }

    /**
     * Client receipts one of whose shares belongs to this project and that do
     * not belong to it alone: a single transfer split across projects, or one
     * with money still unallocated. Each share shows its project, its
     * verification state and the invoices it has been applied to.
     */
    private function splitReceipts(ProjectEnquiry $enquiry): Collection
    {
        $ids = EnquiryPayment::query()->where('project_enquiry_id', $enquiry->id)
            ->whereNotNull('client_receipt_id')->pluck('client_receipt_id')->unique();
        if ($ids->isEmpty()) {
            return collect();
        }

        $receipts = ClientReceipt::query()->whereIn('id', $ids)
            ->with(['paymentSource:id,name', 'allocations.enquiry:id,job_number,title,client_id', 'allocations.enquiry.client:id,full_name,company_name'])
            ->orderByDesc('payment_date')->get();
        $applied = DB::table('project_invoice_allocations as a')->join('project_invoices as i', 'i.id', '=', 'a.project_invoice_id')
            ->whereIn('a.enquiry_payment_id', $receipts->flatMap->allocations->pluck('id'))
            ->get(['a.enquiry_payment_id', 'a.amount', 'i.id as invoice_id', 'i.invoice_number'])->groupBy('enquiry_payment_id');

        return $receipts->map(function (ClientReceipt $receipt) use ($applied) {
            $shares = $receipt->allocations->map(fn (EnquiryPayment $share) => [
                'payment_id' => $share->id,
                'project' => $share->enquiry ? ['id' => $share->enquiry->id, 'job_number' => $share->enquiry->job_number, 'title' => $share->enquiry->title] : null,
                'client' => $share->enquiry?->client ? ['id' => $share->enquiry->client->id, 'name' => $share->enquiry->client->company_name ?: $share->enquiry->client->full_name] : null,
                'amount' => $this->money($share->amount),
                'status' => ($share->reversed_at || $share->status === 'reversed') ? 'reversed' : $share->status,
                'invoices' => ($applied[$share->id] ?? collect())->map(fn ($a) => [
                    'invoice_id' => $a->invoice_id, 'invoice_number' => $a->invoice_number, 'amount' => $this->money($a->amount),
                ])->values(),
            ]);
            $allocated = $receipt->allocations->filter(fn (EnquiryPayment $s) => ! $s->reversed_at && $s->status !== 'reversed')->sum('amount');

            return [
                'id' => $receipt->id,
                'reference' => $receipt->transaction_reference,
                'payment_date' => $receipt->payment_date?->toDateString(),
                'payment_method' => $receipt->payment_method,
                'payment_source' => $receipt->paymentSource?->name,
                'received_amount' => $this->money($receipt->received_amount),
                'allocated_amount' => $this->money($allocated),
                'unallocated_amount' => $this->money(max(0, (float) $receipt->received_amount - (float) $allocated)),
                'recorded_by_id' => $receipt->recorded_by,
                'shares' => $shares->values(),
            ];
        })->filter(fn (array $r) => count($r['shares']) > 1 || (float) $r['unallocated_amount'] > 0)->values();
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

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
