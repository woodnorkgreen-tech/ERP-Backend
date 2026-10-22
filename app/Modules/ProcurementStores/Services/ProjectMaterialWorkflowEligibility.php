<?php

namespace App\Modules\ProcurementStores\Services;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Models\ProjectHold;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 78C — which jobs still have material work to do.
 *
 * Before this, "is this job live?" had four answers in the codebase and the
 * material demand behind Stores and Procurement had none: a completed job's
 * unissued BOM kept appearing as stock to issue, as shortage and as a reason to
 * buy. The rule now lives here, and every queue, count and write path asks it.
 *
 * **The authority is `project_enquiries.status`.** `projects.status` is a
 * mirror that was never backfilled for imported jobs (356 dev rows say
 * `planning` on a completed enquiry), so it is never read here.
 *
 * Two overlays sit on top of the status:
 *  - an open {@see ProjectHold} turns an active or pre-start job into `on_hold`;
 *  - requirement retirements ({@see MaterialRequirementRetirements}) work per
 *    BOM line, so they never block a job — they take the retired quantity out
 *    of demand. A job whose every line is retired is *tagged* `legacy` and
 *    filed with historical work; it contributes nothing to any count anyway.
 *
 * Overdue is a schedule tag on an active job, never a reason to exclude it.
 */
class ProjectMaterialWorkflowEligibility
{
    public const STATE_ACTIVE = 'active';
    public const STATE_ON_HOLD = 'on_hold';
    public const STATE_PRE_START = 'pre_start';
    public const STATE_PIPELINE = 'pipeline';
    public const STATE_COMPLETED = 'completed';
    public const STATE_CLOSED = 'closed';
    public const STATE_CANCELLED = 'cancelled';

    public const CAP_RESOLUTION = 'resolution';
    public const CAP_ISSUE = 'issue';
    public const CAP_SHORTAGE = 'shortage';
    public const CAP_DEMAND = 'procurement_demand';
    /** Reporting only: requirements kept for the record. Never a write capability. */
    public const CAP_HISTORICAL = 'historical';

    public const QUEUE_ACTIVE = 'active_work';
    public const QUEUE_ON_HOLD = 'on_hold';
    public const QUEUE_PRE_START = 'pre_start';
    public const QUEUE_HISTORICAL = 'historical';
    public const QUEUE_PIPELINE = 'pipeline';

    public const HISTORICAL_STATES = [self::STATE_COMPLETED, self::STATE_CLOSED, self::STATE_CANCELLED];

    /** Enquiry statuses that mean the job is being delivered. */
    private const ACTIVE_STATUSES = ['planning', 'in_progress'];

    /** Confirmed, waiting for funds (D-3: may create demand, may not issue). */
    private const PRE_START_STATUSES = ['quote_approved', 'awaiting_deposit'];

    /**
     * What each state may do. D-3: pre-start jobs create procurement demand and
     * shortage (lead time) but nothing is issued before the deposit. On hold
     * keeps planning work (resolution) and stops everything that moves stock or
     * money.
     */
    private const MATRIX = [
        self::CAP_RESOLUTION => [self::STATE_ACTIVE, self::STATE_ON_HOLD, self::STATE_PRE_START],
        self::CAP_ISSUE => [self::STATE_ACTIVE],
        self::CAP_SHORTAGE => [self::STATE_ACTIVE, self::STATE_PRE_START],
        self::CAP_DEMAND => [self::STATE_ACTIVE, self::STATE_PRE_START],
        self::CAP_HISTORICAL => self::HISTORICAL_STATES,
    ];

    /**
     * D-4: states a holder of PROJECT_MATERIALS_CLOSED_JOB_OVERRIDE may still
     * act on, with a recorded reason — snag or remedial work on a finished job,
     * an urgent need on a paused one. Cancelled and not-yet-started jobs have no
     * override: there is no delivery to support.
     */
    private const OVERRIDABLE = [
        self::CAP_ISSUE => [self::STATE_ON_HOLD, self::STATE_COMPLETED, self::STATE_CLOSED],
        self::CAP_DEMAND => [self::STATE_ON_HOLD, self::STATE_COMPLETED, self::STATE_CLOSED],
    ];

    public const OVERRIDE_REASON_MIN = 10;

    public function allows(string $state, string $capability): bool
    {
        return in_array($state, self::MATRIX[$capability] ?? [], true);
    }

    /** @return array<int, string> */
    public static function statesFor(string $capability): array
    {
        return self::MATRIX[$capability] ?? [];
    }

    public function stateFor(int $enquiryId): string
    {
        return $this->describeMany([$enquiryId])[$enquiryId]['state'] ?? self::STATE_PIPELINE;
    }

    /**
     * Everything a screen needs to file and badge a job, for many jobs at once.
     *
     * @param  iterable<int>  $enquiryIds
     * @return array<int, array<string, mixed>> keyed by enquiry id
     */
    public function describeMany(iterable $enquiryIds): array
    {
        $ids = collect($enquiryIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $enquiries = ProjectEnquiry::query()->whereIn('id', $ids)
            ->get(['id', 'status', 'expected_delivery_date'])->keyBy('id');
        $projects = Project::query()->whereIn('enquiry_id', $ids)
            ->get(['id', 'enquiry_id', 'end_date', 'status'])->keyBy('enquiry_id');
        $holds = ProjectHold::query()->open()->whereIn('project_enquiry_id', $ids)
            ->with('placedBy')->get()->keyBy('project_enquiry_id');
        $legacy = $this->fullyRetiredJobs($ids);
        $today = now()->toDateString();

        $described = [];
        foreach ($ids as $id) {
            $enquiry = $enquiries->get($id);
            if (! $enquiry) {
                continue;
            }
            $project = $projects->get($id);
            $hold = $holds->get($id);
            $state = $this->stateOf((string) $enquiry->status, $hold !== null);

            $due = $enquiry->expected_delivery_date?->toDateString() ?? $project?->end_date?->toDateString();
            $overdue = in_array($state, [self::STATE_ACTIVE, self::STATE_ON_HOLD], true)
                && $due !== null && $due < $today;
            $isLegacy = $state === self::STATE_ACTIVE && isset($legacy[$id]);

            $described[$id] = [
                'enquiry_status' => $enquiry->status,
                'mirror_status' => $project?->status,
                'state' => $state,
                'queue' => $this->queueFor($state, $isLegacy),
                'overdue' => $overdue,
                'due_date' => $due,
                'days_overdue' => $overdue ? (int) abs(now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($due))) : 0,
                'legacy' => $isLegacy,
                'read_only' => in_array($state, self::HISTORICAL_STATES, true) || $isLegacy,
                'hold' => $hold ? [
                    'id' => $hold->id,
                    'reason' => $hold->reason,
                    'placed_at' => $hold->placed_at?->toIso8601String(),
                    'placed_by' => $hold->placedBy?->name,
                ] : null,
                'can' => collect(array_keys(self::MATRIX))
                    ->reject(fn ($cap) => $cap === self::CAP_HISTORICAL)
                    ->mapWithKeys(fn ($cap) => [$cap => $this->allows($state, $cap)])->all(),
                'overridable' => collect(self::OVERRIDABLE)
                    ->map(fn ($states) => in_array($state, $states, true))->all(),
            ];
        }

        return $described;
    }

    /**
     * Enquiry ids whose state allows the capability. The state is computed by
     * the same {@see stateOf()} that describeMany() uses, so a list and the
     * count that labels it cannot disagree.
     *
     * @param  array<int, int>|null  $within
     * @return Collection<int, int>
     */
    public function eligibleEnquiryIds(string $capability, ?array $within = null): Collection
    {
        return DB::table('project_enquiries as e')
            ->when($within !== null, fn ($q) => $q->whereIn('e.id', $within ?: [0]))
            ->selectRaw('e.id, e.status, '
                .'EXISTS(SELECT 1 FROM project_holds h WHERE h.project_enquiry_id = e.id AND h.released_at IS NULL) AS on_hold')
            ->get()
            ->filter(fn ($row) => $this->allows(
                $this->stateOf((string) $row->status, (bool) $row->on_hold),
                $capability,
            ))
            ->pluck('id')->map(fn ($id) => (int) $id)->values();
    }

    /**
     * Refuse a write on a job whose state does not allow it, unless an
     * authorised override with a reason applies (D-4). Returns the override
     * record when one was used, so the caller can keep it with the movement.
     *
     * @return array{state: string, reason: string, user_id: int}|null
     */
    public function assertAllows(?int $enquiryId, string $capability, ?string $overrideReason = null, string $field = 'project_id'): ?array
    {
        if (! $enquiryId) {
            throw ValidationException::withMessages([$field => 'This project has no enquiry, so its lifecycle cannot be checked.']);
        }

        $described = $this->describeMany([$enquiryId])[$enquiryId] ?? null;
        $state = $described['state'] ?? self::STATE_PIPELINE;
        if ($this->allows($state, $capability)) {
            return null;
        }

        $reason = trim((string) $overrideReason);
        $user = auth()->user();
        $overridable = in_array($state, self::OVERRIDABLE[$capability] ?? [], true);

        if ($overridable && $reason !== '') {
            if (! $user instanceof User || ! $user->can(Permissions::PROJECT_MATERIALS_CLOSED_JOB_OVERRIDE)) {
                throw ValidationException::withMessages([$field => "This job is {$this->stateLabel($state)}. Only someone authorised to approve material work on completed, closed or on-hold jobs can proceed."]);
            }
            if (mb_strlen($reason) < self::OVERRIDE_REASON_MIN) {
                throw ValidationException::withMessages(['lifecycle_override_reason' => 'Say why this work is needed on a '.$this->stateLabel($state).' job (at least '.self::OVERRIDE_REASON_MIN.' characters).']);
            }

            GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiryId,
                'user_id' => $user->id,
                'gate_type' => 'material_lifecycle_override',
                'action_status' => 'authorized',
                'message' => "Material {$capability} authorised on a {$state} job: {$reason}",
                'context' => ['capability' => $capability, 'state' => $state, 'reason' => $reason],
                'ip_address' => request()?->ip(),
            ]);

            return ['state' => $state, 'reason' => $reason, 'user_id' => (int) $user->id];
        }

        $hint = $overridable
            ? ' An authorised manager can proceed by giving a reason for the work.'
            : '';
        throw ValidationException::withMessages([
            $field => $this->refusal($state, $capability).$hint,
        ]);
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_ACTIVE => 'active',
            self::STATE_ON_HOLD => 'on hold',
            self::STATE_PRE_START => 'waiting for its deposit',
            self::STATE_PIPELINE => 'not yet a confirmed project',
            default => $state,
        };
    }

    private function refusal(string $state, string $capability): string
    {
        $what = match ($capability) {
            self::CAP_ISSUE => 'Stock cannot be issued to it',
            self::CAP_DEMAND => 'New procurement cannot be raised for it',
            self::CAP_RESOLUTION => 'Its material requirements can no longer be changed from Stores',
            default => 'This is not allowed',
        };

        return "This job is {$this->stateLabel($state)}. {$what}.";
    }

    /** The one place a state is decided. */
    public function stateOf(string $status, bool $onHold): string
    {
        $state = $this->baseState($status);

        return $onHold && in_array($state, [self::STATE_ACTIVE, self::STATE_PRE_START], true)
            ? self::STATE_ON_HOLD
            : $state;
    }

    private function baseState(string $status): string
    {
        return match (true) {
            $status === 'completed' => self::STATE_COMPLETED,
            $status === 'closed' => self::STATE_CLOSED,
            $status === 'cancelled' => self::STATE_CANCELLED,
            // The enquiry status alone decides. Activation writes `planning` and
            // creates the projects row together, and a confirmed job waiting
            // for its deposit (D-3) has no row yet. Anything that moves stock
            // or counts demand still needs the row: the issue takes a
            // project_id and demand is read per project.
            in_array($status, self::ACTIVE_STATUSES, true) => self::STATE_ACTIVE,
            in_array($status, self::PRE_START_STATUSES, true) => self::STATE_PRE_START,
            default => self::STATE_PIPELINE,
        };
    }

    private function queueFor(string $state, bool $legacy): string
    {
        return match (true) {
            $legacy, in_array($state, self::HISTORICAL_STATES, true) => self::QUEUE_HISTORICAL,
            $state === self::STATE_ACTIVE => self::QUEUE_ACTIVE,
            $state === self::STATE_ON_HOLD => self::QUEUE_ON_HOLD,
            $state === self::STATE_PRE_START => self::QUEUE_PRE_START,
            default => self::QUEUE_PIPELINE,
        };
    }

    /**
     * Jobs where every included BOM line carries an active retirement that
     * covers its current quantity. A line added or increased after retirement
     * is not covered, so the job drops back into active work and only the new
     * quantity shows as demand.
     *
     * @return array<int, true>
     */
    private function fullyRetiredJobs(Collection $ids): array
    {
        $rows = DB::table('element_materials as em')
            ->join('project_deliverables as el', 'el.id', '=', 'em.project_element_id')
            ->join('task_materials_data as tmd', 'tmd.id', '=', 'el.task_materials_data_id')
            ->join('enquiry_tasks as t', 't.id', '=', 'tmd.enquiry_task_id')
            ->leftJoin('material_requirement_retirements as r', function ($join) {
                $join->on('r.element_material_id', '=', 'em.id')
                    ->whereNull('r.revoked_at')
                    ->whereColumn('r.bom_quantity_snapshot', '>=', 'em.quantity');
            })
            ->whereIn('t.project_enquiry_id', $ids)
            ->where('em.is_included', true)
            ->whereNull('em.archived_at')
            ->groupBy('t.project_enquiry_id')
            ->selectRaw('t.project_enquiry_id as enquiry_id, COUNT(*) as line_count, SUM(r.id IS NULL) as uncovered')
            ->get();

        return $rows->filter(fn ($row) => (int) $row->line_count > 0 && (int) $row->uncovered === 0)
            ->mapWithKeys(fn ($row) => [(int) $row->enquiry_id => true])->all();
    }
}
