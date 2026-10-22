<?php

namespace App\Modules\Projects\Actions;

use App\Constants\EnquiryConstants;
use App\Models\GovernanceAuditLog;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Services\DesignWorkTrackingService;
use App\Modules\Projects\Models\ProjectHold;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 78C (D-2, D-6) — the lifecycle moves that change what Stores and
 * Procurement treat as live work.
 *
 * Cancelling and reopening a job used to be a free-text edit through
 * `PUT /enquiries/{id}`: no permission beyond editing the enquiry, no reason,
 * no audit. Completion and closure already had governed actions
 * ({@see CompleteProjectAction}); these are the rest of the set.
 *
 * None of them touches stock, purchase orders, bills or journals. A job's open
 * commitments are *reported* so someone decides each one — an approved PO is
 * never cancelled because its job was.
 */
class ProjectLifecycleAction
{
    public const REASON_MIN = 10;

    private const HISTORICAL = [
        EnquiryConstants::STATUS_COMPLETED,
        EnquiryConstants::STATUS_CLOSED,
        EnquiryConstants::STATUS_CANCELLED,
    ];

    public function __construct(private readonly DesignWorkTrackingService $designWorkTracking)
    {
    }

    /** @return array{enquiry: ProjectEnquiry, open_commitments: array} */
    public function cancel(ProjectEnquiry $enquiry, int $userId, string $reason): array
    {
        $reason = $this->reason($reason, 'reason', 'Say why this job is being cancelled');
        if (in_array($enquiry->status, self::HISTORICAL, true)) {
            throw ValidationException::withMessages(['status' => $enquiry->status === EnquiryConstants::STATUS_CANCELLED
                ? 'This job is already cancelled.'
                : "A {$enquiry->status} job cannot be cancelled. Reactivate it first if the work is being withdrawn."]);
        }

        $commitments = $this->openCommitments($enquiry);

        $enquiry = DB::transaction(function () use ($enquiry, $userId, $reason, $commitments) {
            $from = $enquiry->status;
            $enquiry->status = EnquiryConstants::STATUS_CANCELLED;
            $enquiry->save();
            $enquiry->project()->update(['status' => EnquiryConstants::STATUS_CANCELLED]);

            $designJob = DesignJob::query()->where('project_enquiry_id', $enquiry->id)->lockForUpdate()->first();
            if ($designJob) {
                $designJob->update(['status' => 'cancelled', 'updated_by' => $userId]);
                foreach ($designJob->items()->get() as $item) {
                    $this->designWorkTracking->closeForWorkflow($item, 'cancelled', $reason, $userId);
                }
            }

            ProjectHold::query()->open()->where('project_enquiry_id', $enquiry->id)->update([
                'released_by' => $userId, 'released_at' => now(), 'release_reason' => 'Job cancelled: '.$reason,
            ]);

            $this->audit($enquiry, $userId, 'project_cancelled', "Project cancelled: {$reason}", [
                'from_status' => $from,
                'reason' => $reason,
                'open_commitments' => $commitments['summary'],
            ]);

            return $enquiry->fresh();
        });

        return ['enquiry' => $enquiry, 'open_commitments' => $commitments];
    }

    /**
     * Bring a completed, closed or cancelled job back into live work.
     *
     * Material demand is derived on read, so nothing is recreated: the job's
     * outstanding requirements simply reappear, once. Because a finished job
     * may have been supplied by hand while it sat outside the ERP, reopening a
     * completed or closed job requires a reconciliation note saying what was
     * issued that way — retire those quantities before Stores issues again.
     *
     * @return array{enquiry: ProjectEnquiry, reappearing: array}
     */
    public function reactivate(ProjectEnquiry $enquiry, int $userId, string $reason, ?string $reconciliationNote): array
    {
        $reason = $this->reason($reason, 'reason', 'Say why this job is being reopened');
        if (! in_array($enquiry->status, self::HISTORICAL, true)) {
            throw ValidationException::withMessages(['status' => 'Only a completed, closed or cancelled job can be reactivated.']);
        }
        $note = trim((string) $reconciliationNote);
        if ($enquiry->status !== EnquiryConstants::STATUS_CANCELLED && mb_strlen($note) < self::REASON_MIN) {
            throw ValidationException::withMessages(['reconciliation_note' => 'Record whether any of this job\'s materials were issued outside the ERP while it was '.$enquiry->status.' (at least '.self::REASON_MIN.' characters). Retire those quantities before Stores issues again.']);
        }

        $project = $enquiry->project;
        $to = $project
            ? EnquiryConstants::STATUS_PLANNING
            : $this->statusBeforeCancellation($enquiry) ?? EnquiryConstants::STATUS_ENQUIRY_LOGGED;

        $enquiry = DB::transaction(function () use ($enquiry, $userId, $reason, $note, $to) {
            $from = $enquiry->status;
            $enquiry->status = $to;
            $enquiry->save();
            $enquiry->project()->update(['status' => EnquiryConstants::STATUS_PLANNING]);

            if ($from === EnquiryConstants::STATUS_CANCELLED) {
                DesignJob::query()->where('project_enquiry_id', $enquiry->id)->where('status', 'cancelled')
                    ->update(['status' => 'in_design', 'updated_by' => $userId]);
            }

            $this->audit($enquiry, $userId, 'project_reactivated', "Project reactivated from {$from}: {$reason}", [
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'reconciliation_note' => $note !== '' ? $note : null,
            ]);

            return $enquiry->fresh();
        });

        $reappearing = ['lines' => 0, 'jobs_demand' => []];
        if ($enquiry->project) {
            $lines = app(ProjectMaterialDemand::class)->pendingLines(null, ProjectMaterialWorkflowEligibility::CAP_ISSUE)
                ->where('project_id', $enquiry->project->id);
            $reappearing = ['lines' => $lines->count(), 'materials' => $lines->pluck('library_material_id')->unique()->count()];
        }

        return ['enquiry' => $enquiry, 'reappearing' => $reappearing];
    }

    public function placeHold(ProjectEnquiry $enquiry, int $userId, string $reason): ProjectHold
    {
        $reason = $this->reason($reason, 'reason', 'Say why this job is on hold');
        $state = app(ProjectMaterialWorkflowEligibility::class)->stateFor($enquiry->id);
        if ($state === ProjectMaterialWorkflowEligibility::STATE_ON_HOLD) {
            throw ValidationException::withMessages(['status' => 'This job is already on hold.']);
        }
        if (! in_array($state, [ProjectMaterialWorkflowEligibility::STATE_ACTIVE, ProjectMaterialWorkflowEligibility::STATE_PRE_START], true)) {
            throw ValidationException::withMessages(['status' => 'Only an active or confirmed job can be put on hold.']);
        }

        return DB::transaction(function () use ($enquiry, $userId, $reason) {
            $hold = ProjectHold::create([
                'project_enquiry_id' => $enquiry->id,
                'reason' => $reason,
                'placed_by' => $userId,
                'placed_at' => now(),
            ]);
            $this->audit($enquiry, $userId, 'project_hold_placed', "Project put on hold: {$reason}", ['hold_id' => $hold->id]);

            return $hold;
        });
    }

    public function releaseHold(ProjectEnquiry $enquiry, int $userId, string $reason): ProjectHold
    {
        $reason = $this->reason($reason, 'reason', 'Say why work is resuming');

        return DB::transaction(function () use ($enquiry, $userId, $reason) {
            $hold = ProjectHold::query()->open()->where('project_enquiry_id', $enquiry->id)->lockForUpdate()->first();
            if (! $hold) {
                throw ValidationException::withMessages(['status' => 'This job is not on hold.']);
            }
            $hold->update(['released_by' => $userId, 'released_at' => now(), 'release_reason' => $reason]);
            $this->audit($enquiry, $userId, 'project_hold_released', "Project hold released: {$reason}", ['hold_id' => $hold->id]);

            return $hold->fresh();
        });
    }

    /**
     * Procurement and Stores obligations still open on a job. Reported, never
     * acted on: each needs an explicit close-out decision by a person.
     */
    public function openCommitments(ProjectEnquiry $enquiry): array
    {
        $projectId = Project::where('enquiry_id', $enquiry->id)->value('id');

        $requisitions = $projectId ? Requisition::query()
            ->where('project_id', $projectId)
            ->whereIn('status', ['draft', 'pending_approval', 'approved'])
            ->whereDoesntHave('purchaseOrders', fn ($q) => $q->whereNotIn('status', ['cancelled', 'rejected']))
            ->get(['id', 'requisition_number', 'status', 'total_amount']) : collect();

        $orders = $projectId ? PurchaseOrder::query()
            ->whereHas('requisition', fn ($q) => $q->where('project_id', $projectId))
            ->whereNotIn('status', ['cancelled', 'rejected', 'completed'])
            ->get(['id', 'po_number', 'status', 'total_amount']) : collect();

        $boardRequests = $projectId ? BoardRequest::query()
            ->where('project_id', $projectId)->where('status', 'pending')
            ->get(['id', 'material_id', 'qty_requested', 'qty_fulfilled', 'status']) : collect();

        $bills = Bill::query()
            ->where(fn ($q) => $q->where('project_enquiry_id', $enquiry->id)
                ->when($projectId, fn ($w) => $w->orWhere('project_id', $projectId)))
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get(['id', 'bill_number', 'status', 'balance']);

        return [
            'requisitions' => $requisitions->values()->all(),
            'purchase_orders' => $orders->values()->all(),
            'board_requests' => $boardRequests->values()->all(),
            'unpaid_bills' => $bills->values()->all(),
            'summary' => [
                'requisitions' => $requisitions->count(),
                'purchase_orders' => $orders->count(),
                'board_requests' => $boardRequests->count(),
                'unpaid_bills' => $bills->count(),
            ],
        ];
    }

    private function statusBeforeCancellation(ProjectEnquiry $enquiry): ?string
    {
        $from = GovernanceAuditLog::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('gate_type', 'project_cancelled')
            ->latest('id')->first()?->context['from_status'] ?? null;

        return in_array($from, EnquiryConstants::getAllStatuses(), true) && ! in_array($from, self::HISTORICAL, true)
            ? $from
            : null;
    }

    private function reason(string $reason, string $field, string $prompt): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw ValidationException::withMessages([$field => "{$prompt} (at least ".self::REASON_MIN.' characters).']);
        }

        return $reason;
    }

    /** Written inside the transaction: a lifecycle move without its record must not happen. */
    private function audit(ProjectEnquiry $enquiry, int $userId, string $event, string $message, array $context): void
    {
        GovernanceAuditLog::create([
            'project_enquiry_id' => $enquiry->id,
            'user_id' => $userId,
            'gate_type' => $event,
            'action_status' => 'authorized',
            'message' => $message,
            'context' => $context,
            'ip_address' => request()?->ip(),
        ]);
    }
}
