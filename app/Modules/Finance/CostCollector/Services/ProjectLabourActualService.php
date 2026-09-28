<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineTransfer;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActual;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActualReturn;
use App\Services\ProjectFinancialAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * W7 Labour Cost Confirmed Subset — Service Layer.
 *
 * Design invariants (enforced here, not in the controller):
 *
 * 1. CLOSURE GUARD — every transition that can change project labour cost checks,
 *    under a lock, that the project is not financially closed (W7-14).
 *
 * 2. COST FORMULA — calculated_cost = usage × authoritative rate, in bcmath, rounded
 *    half-up to 2dp once at the end:
 *      hours unit:    actual_hours × unit_rate
 *      PAX/days unit: actual_quantity × actual_days × unit_rate
 *    The rate is a snapshot of the finalized Project Budget line at record time (or a Finance
 *    rate resolution for unbudgeted labour) and is never recalculated afterwards.
 *
 * 3. postsIndependently = false — every CostLine created here is analytical only.
 *    Payroll already recognises the company expense; W7 never posts a journal (W7-12).
 *
 * 4. STAGED VERIFICATION — Recorder → Project Officer (po_verify) → Finance
 *    (finance_verify, which creates the authoritative CostLine). Either reviewer may
 *    return for correction; the recorder corrects permitted fields and resubmits,
 *    which restarts Project Officer review. Every return is kept in an immutable
 *    history (project_labour_actual_returns).
 *
 * 5. OVERCOST ALERT — exceeding a budget line issues a governance alert; never a
 *    hard stop (W7-7).
 *
 * 6. CORRECTION (W7-13) — a Finance-verified actual is never edited. correct() opens
 *    a successor that runs the normal PO → Finance verification. Until the successor
 *    is Finance-verified the original remains the authoritative cost. On Finance
 *    verification the economic change is a W6-4 reversing pair
 *    (CostTransferService::correct(): OUT −original, IN +corrected, same project) and
 *    the original becomes superseded. A cross-project move is reclassify(), which is
 *    CostTransferService::transfer() unchanged.
 */
class ProjectLabourActualService
{
    /** Fields a recorder may change when resubmitting a returned actual. */
    private const RESUBMIT_FIELDS = [
        'actual_quantity', 'actual_days', 'actual_hours', 'work_date', 'employee_id',
        'rework_type', 'unbudgeted_reason', 'budget_line_id',
        // Operational classification of unbudgeted labour only (see resubmit()).
        'labour_role', 'labour_category', 'budget_unit',
    ];

    /** Fields Finance may change on a verified-actual correction. */
    private const CORRECTION_FIELDS = [
        'actual_quantity', 'actual_days', 'actual_hours', 'work_date',
        'employee_id', 'rework_type', 'unbudgeted_reason', 'metadata',
    ];

    public function __construct(
        private CostCollectorService $collector,
        private CostTransferService $transfers,
        private ProjectBudgetAuthority $budgets,
        private ProjectFinancialAccess $access,
    ) {}

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * All non-superseded labour actuals for a project, with the relations the API
     * resource needs (eager-loaded — no per-row queries).
     */
    public function index(ProjectEnquiry $enquiry): Collection
    {
        return ProjectLabourActual::forEnquiry($enquiry->id)
            ->active()
            ->with($this->resourceRelations())
            ->latest()
            ->get();
    }

    /** @return array<int, string> */
    public function resourceRelations(): array
    {
        return [
            'recorder', 'poVerifier', 'financeVerifier', 'returnedBy', 'resubmittedBy',
            'employee', 'actualCostLine', 'returns.returner', 'returns.resubmitter',
            'reversalOf', 'correction', 'correctionTransfer.outLine', 'correctionTransfer.inLine',
            'reclassificationTransfer.inLine',
        ];
    }

    /**
     * The labour lines of the project's current budget (ProjectBudgetAuthority), with
     * the authoritative position of each. Every money figure is a 2dp string computed
     * in bcmath. Lines are listed while the budget is still in progress so the plan is
     * visible, but `recordable` is true only once the budget is finalized.
     *
     *   budget_amount  — the active planned CostLine (what Project Costing plans)
     *   verified_cost  — verified Actual CostLines for this line (what Project Costing
     *                    counts, corrections and reclassifications included)
     *   pending_cost   — recorded / PO-verified actuals not yet Finance-verified, net of
     *                    any original a pending correction will replace
     *   remaining_cost — budget − verified (signed; negative = over budget, never capped)
     *   projected_remaining_cost — budget − (verified + pending)
     */
    public function getBudgetLabourLines(ProjectEnquiry $enquiry): array
    {
        $budget = $this->budgets->currentBudget($enquiry->id);

        if (!$budget || empty($budget->labour_data)) {
            return [];
        }
        $finalized = $this->budgets->isFinalized($budget);

        $planned = CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->counting()
            ->where('source_id', $budget->id)
            ->where('details->budget_category', 'labour')
            ->get(['source_ref', 'net_amount'])
            ->pluck('net_amount', 'source_ref');

        $verified = $this->verifiedCostByBudgetLine($enquiry);
        $pending = $this->pendingCostByBudgetLine($enquiry);

        return collect($budget->labour_data)
            ->filter(fn ($row) => is_array($row) && !empty($row['id']))
            ->map(function ($row) use ($planned, $verified, $pending, $finalized) {
                $id = $row['id'];
                $budgetAmount = $this->money($planned[$id] ?? ($row['amount'] ?? '0'));
                $verifiedCost = $verified[$id] ?? '0.00';
                $pendingCost = $pending[$id] ?? '0.00';
                $projected = bcadd($verifiedCost, $pendingCost, 2);

                return [
                    'id'             => $id,
                    'type'           => $row['type'] ?? null,
                    'category'       => $row['category'] ?? null,
                    'unit'           => $row['unit'] ?? null,
                    'quantity'       => (float) ($row['quantity'] ?? 0),
                    'days'           => (float) ($row['days'] ?? 0),
                    'unit_rate'      => $this->money($row['unitRate'] ?? '0'),
                    'budget_amount'  => $budgetAmount,
                    'verified_cost'  => $verifiedCost,
                    'pending_cost'   => $pendingCost,
                    // Kept for existing callers: the authoritative consumed figure.
                    'consumed_cost'  => $verifiedCost,
                    'remaining_cost' => bcsub($budgetAmount, $verifiedCost, 2),
                    'projected_remaining_cost' => bcsub($budgetAmount, $projected, 2),
                    'is_over_budget' => bccomp($verifiedCost, $budgetAmount, 2) === 1,
                    'is_included'    => (bool) ($row['isIncluded'] ?? true),
                    // Budgeted labour needs a finalized budget and an active planned line.
                    'recordable'     => $finalized && (bool) ($row['isIncluded'] ?? true) && isset($planned[$id]),
                ];
            })
            ->values()
            ->all();
    }

    /** none | in_progress | finalized — see ProjectBudgetAuthority. */
    public function budgetState(ProjectEnquiry $enquiry): string
    {
        return $this->budgets->state($enquiry->id);
    }

    // ── Lifecycle transitions ─────────────────────────────────────────────────

    /**
     * Record actual labour usage against a finalized Project Budget line or as unbudgeted.
     * Permission: finance.labour.record + project assignment (or Finance).
     */
    public function record(array $data, User $recorder): ProjectLabourActual
    {
        $enquiry = ProjectEnquiry::findOrFail($data['project_enquiry_id']);
        $this->authorize($this->access->canRecordLabour($recorder, $enquiry),
            'You do not have permission to record labour actuals for this project.');
        $this->guardClosure($enquiry);

        if ((bool) ($data['is_unbudgeted'] ?? false)) {
            $data = $this->unresolvedUnbudgetedData($data);
        } else {
            $data = array_merge($data, $this->authoritativeBudgetData($enquiry, (string) ($data['budget_line_id'] ?? '')));
        }

        $calculatedCost = $this->costFor($data);

        $actual = DB::transaction(function () use ($data, $recorder, $calculatedCost, $enquiry) {
            $actual = ProjectLabourActual::create([
                'project_enquiry_id' => $enquiry->id,
                'budget_line_id'     => $data['budget_line_id'] ?? null,
                'budget_id'          => $data['budget_id'] ?? null,
                'consumes_cost_line_id' => $data['consumes_cost_line_id'] ?? null,
                'labour_role'        => $data['labour_role'],
                'labour_category'    => $data['labour_category'],
                'budget_unit'        => $data['budget_unit'],
                'unit_rate'          => $data['unit_rate'],
                'actual_quantity'    => $data['actual_quantity'] ?? null,
                'actual_days'        => $data['actual_days'] ?? null,
                'actual_hours'       => $data['actual_hours'] ?? null,
                'calculated_cost'    => $calculatedCost,
                'work_date'          => $data['work_date'],
                'employee_id'        => $data['employee_id'] ?? null,
                'is_unbudgeted'      => (bool) ($data['is_unbudgeted'] ?? false),
                'unbudgeted_reason'  => $data['unbudgeted_reason'] ?? null,
                'rate_source'        => $data['rate_source'] ?? null,
                'rate_resolution_status' => $data['rate_resolution_status'],
                'rework_type'        => $data['rework_type'] ?? ProjectLabourActual::REWORK_NONE,
                'status'             => ProjectLabourActual::STATUS_RECORDED,
                'recorded_by'        => $recorder->id,
                'recorded_at'        => now(),
                'metadata'           => $data['metadata'] ?? null,
            ]);

            $this->auditLog($actual, $recorder, 'record', 'Labour actual recorded.');

            return $actual;
        });

        $this->checkBudgetAlert($actual, $enquiry);

        return $actual;
    }

    /**
     * Stage-1 Verification: Project Officer confirms the labour attribution.
     * Transitions: recorded → po_verified. Permission: finance.labour.po_verify.
     */
    public function poVerify(ProjectLabourActual $actual, User $verifier, ?string $notes = null): ProjectLabourActual
    {
        $this->authorize($this->access->canPoVerifyLabour($verifier, $actual->enquiry),
            'You do not have permission to PO-verify labour actuals for this project.');

        return DB::transaction(function () use ($actual, $verifier, $notes) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));
            $this->assertStatus($actual, ProjectLabourActual::STATUS_RECORDED, 'PO verification');
            $actual->update([
                'status'         => ProjectLabourActual::STATUS_PO_VERIFIED,
                'po_verified_by' => $verifier->id,
                'po_verified_at' => now(),
                'po_notes'       => $notes,
            ]);

            $this->auditLog($actual, $verifier, 'po_verify', 'Labour actual PO-verified.');
            return $actual->fresh();
        });
    }

    /**
     * Stage-2 Verification: Finance confirms the monetary labour cost.
     * Transitions: po_verified → finance_verified.
     *
     * A new actual gets one analytical Actual CostLine (postsIndependently = false).
     * A correction successor instead gets the IN leg of a W6-4 correction pair
     * against the original's authoritative line, and the original is superseded.
     *
     * Idempotent: a retry after success returns the existing outcome unchanged.
     */
    public function financeVerify(ProjectLabourActual $actual, User $verifier, ?string $notes = null): ProjectLabourActual
    {
        $this->authorize($this->access->canFinanceVerifyLabour($verifier),
            'You do not have permission to finance-verify labour actuals.');

        return DB::transaction(function () use ($actual, $verifier, $notes) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);

            // IDEMPOTENCY: a timed-out client retrying a successful verification must
            // get the same outcome, never a second CostLine.
            if ($actual->status === ProjectLabourActual::STATUS_FINANCE_VERIFIED && $actual->cost_line_id) {
                return $actual->fresh(['actualCostLine']);
            }

            $this->assertStatus($actual, ProjectLabourActual::STATUS_PO_VERIFIED, 'Finance verification');
            if ($actual->rate_resolution_status !== ProjectLabourActual::RATE_RESOLVED) {
                throw ValidationException::withMessages(['rate' => ['Finance verification is blocked until an authorized project labour rate source is confirmed.']]);
            }
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));

            if ($actual->reversal_of_id) {
                return $this->financeVerifyCorrection($actual, $verifier, $notes);
            }

            $consumesLineId = $this->currentPlannedLineId($actual);

            $costLine = $this->collector->collect(new CostContext(
                expenseCode: $this->resolveExpenseCode($actual),
                amount: (string) $actual->calculated_cost,
                nature: CostLine::NATURE_ACTUAL,
                enquiryId: $actual->project_enquiry_id,
                jobNumber: $actual->enquiry?->job_number,
                sourceType: 'ProjectLabourActual',
                sourceId: $actual->id,
                sourceRef: (string) $actual->id,
                consumesLineId: $consumesLineId,
                details: $this->costLineDetails($actual),
                incurredAt: $actual->work_date?->toIso8601String(),
                sourceApproved: true,          // Finance is verifying — skip the submit queue.
                postsIndependently: false,     // W7-12: payroll already owns the company expense.
                evidence: $this->evidence($actual),
            ));

            $actual->update([
                'status'              => ProjectLabourActual::STATUS_FINANCE_VERIFIED,
                'finance_verified_by' => $verifier->id,
                'finance_verified_at' => now(),
                'finance_notes'       => $notes,
                'cost_line_id'        => $costLine->id,
                'consumes_cost_line_id' => $consumesLineId,
            ]);

            $this->auditLog(
                $actual, $verifier, 'finance_verify',
                "Labour actual finance-verified. CostLine #{$costLine->id} created (analytical; no journal).",
            );

            return $actual->fresh(['actualCostLine']);
        });
    }

    /**
     * Return a recorded / PO-verified actual for correction.
     * From recorded: the Project Officer (po_verify). From po_verified: Finance
     * (finance_verify) or the Project Officer. Every return is kept in history.
     */
    public function returnForCorrection(
        ProjectLabourActual $actual,
        User $returner,
        string $reason,
    ): ProjectLabourActual {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => ['A return reason is required.']]);
        }

        return DB::transaction(function () use ($actual, $returner, $reason) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));

            if ($actual->status === ProjectLabourActual::STATUS_FINANCE_VERIFIED) {
                throw ValidationException::withMessages([
                    'status' => ['A Finance-verified labour actual cannot be returned. Use Correct instead.'],
                ]);
            }
            if (!in_array($actual->status, [ProjectLabourActual::STATUS_RECORDED, ProjectLabourActual::STATUS_PO_VERIFIED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["A labour actual in status '{$actual->status}' cannot be returned."],
                ]);
            }

            $canReturn = $this->access->canPoVerifyLabour($returner, $actual->enquiry)
                || ($actual->status === ProjectLabourActual::STATUS_PO_VERIFIED && $this->access->canFinanceVerifyLabour($returner));
            $this->authorize($canReturn, 'You do not have permission to return this labour actual.');

            $fromStatus = $actual->status;
            $cycle = ProjectLabourActualReturn::where('project_labour_actual_id', $actual->id)->max('cycle') + 1;

            ProjectLabourActualReturn::create([
                'project_labour_actual_id' => $actual->id,
                'cycle'                => $cycle,
                'returned_from_status' => $fromStatus,
                'returned_by'          => $returner->id,
                'returned_at'          => now(),
                'return_reason'        => $reason,
                'snapshot_before'      => $this->snapshot($actual),
            ]);

            $actual->update([
                'status'        => ProjectLabourActual::STATUS_RETURNED,
                'returned_by'   => $returner->id,
                'returned_at'   => now(),
                'return_reason' => $reason,
            ]);

            $this->auditLog($actual, $returner, 'return_for_correction', "Returned (cycle {$cycle}, from {$fromStatus}): {$reason}");
            return $actual->fresh();
        });
    }

    /**
     * The recorder corrects permitted fields of a returned actual and resubmits it.
     * Transitions: returned_for_correction → recorded (Project Officer review restarts).
     *
     * Budget-derived fields are never taken from the client: changing the budget line
     * re-derives role, category, unit and rate from the finalized Project Budget. For
     * unbudgeted labour the operational classification may be corrected; if it
     * changes, a previously resolved Finance rate no longer applies and is reset.
     *
     * Permission: finance.labour.record + project assignment (or Finance).
     */
    public function resubmit(ProjectLabourActual $actual, User $resubmitter, array $corrections = []): ProjectLabourActual
    {
        $this->authorize($this->access->canResubmitLabour($resubmitter, $actual->enquiry),
            'You do not have permission to resubmit labour actuals for this project.');

        $actual = DB::transaction(function () use ($actual, $resubmitter, $corrections) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $enquiry = ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id);
            $this->guardClosure($enquiry);
            $this->assertStatus($actual, ProjectLabourActual::STATUS_RETURNED, 'Resubmit');

            $safe = array_intersect_key($corrections, array_flip(self::RESUBMIT_FIELDS));
            $before = $this->snapshot($actual);
            $updates = [];

            if ($actual->is_unbudgeted) {
                unset($safe['budget_line_id']);
                $classificationChanged = false;
                foreach (['labour_role', 'labour_category', 'budget_unit'] as $field) {
                    if (array_key_exists($field, $safe) && (string) $safe[$field] !== (string) $actual->{$field}) {
                        if (blank($safe[$field])) {
                            throw ValidationException::withMessages([$field => ["{$field} cannot be blank."]]);
                        }
                        $classificationChanged = true;
                    }
                }
                if (array_key_exists('unbudgeted_reason', $safe) && blank($safe['unbudgeted_reason'])) {
                    throw ValidationException::withMessages(['unbudgeted_reason' => ['An unbudgeted reason is required.']]);
                }
                if ($classificationChanged && $actual->rate_resolution_status === ProjectLabourActual::RATE_RESOLVED) {
                    $unresolved = $this->unresolvedUnbudgetedData(['unbudgeted_reason' => $safe['unbudgeted_reason'] ?? $actual->unbudgeted_reason]);
                    $updates['unit_rate'] = $unresolved['unit_rate'];
                    $updates['rate_resolution_status'] = $unresolved['rate_resolution_status'];
                    $updates['rate_source'] = $unresolved['rate_source'];
                }
            } else {
                unset($safe['labour_role'], $safe['labour_category'], $safe['budget_unit'], $safe['unbudgeted_reason']);
                if (array_key_exists('budget_line_id', $safe) && $safe['budget_line_id'] !== $actual->budget_line_id) {
                    $updates = array_merge($updates, array_intersect_key(
                        $this->authoritativeBudgetData($enquiry, (string) $safe['budget_line_id']),
                        array_flip(['budget_id', 'consumes_cost_line_id', 'labour_role', 'labour_category', 'budget_unit', 'unit_rate', 'rate_source', 'rate_resolution_status']),
                    ));
                }
            }

            $updates = array_merge($safe, $updates);
            $merged = array_merge($actual->only(['budget_unit', 'actual_quantity', 'actual_days', 'actual_hours', 'unit_rate']), $updates);
            $updates['calculated_cost'] = $this->costFor($merged);

            $changes = [];
            foreach ($updates as $field => $value) {
                $old = $before[$field] ?? null;
                $new = is_array($value) ? $value : ($value === null ? null : (string) $value);
                if ($this->differs($old, $new)) {
                    $changes[$field] = ['from' => $old, 'to' => $new];
                }
            }

            $actual->update(array_merge($updates, [
                'status'             => ProjectLabourActual::STATUS_RECORDED,
                // The previous PO verification was of the version that was returned.
                'po_verified_by'     => null,
                'po_verified_at'     => null,
                'po_notes'           => null,
                'resubmitted_by'     => $resubmitter->id,
                'resubmitted_at'     => now(),
                'resubmission_count' => $actual->resubmission_count + 1,
            ]));

            $open = ProjectLabourActualReturn::where('project_labour_actual_id', $actual->id)
                ->whereNull('resubmitted_at')->orderByDesc('cycle')->first();
            $open?->update([
                'resubmitted_by' => $resubmitter->id,
                'resubmitted_at' => now(),
                'changes'        => $changes,
            ]);

            $summary = $changes === [] ? 'no field changes' : implode(', ', array_keys($changes));
            $this->auditLog($actual, $resubmitter, 'resubmit', "Labour actual corrected and resubmitted for Project Officer review ({$summary}).", ['changes' => $changes]);

            return $actual->fresh();
        });

        $this->checkBudgetAlert($actual, $actual->enquiry);

        return $actual;
    }

    /**
     * Correct a Finance-verified labour actual (W7-13).
     *
     * Opens a successor (status recorded, reversal_of_id = original) carrying the
     * corrected usage; rate, role, category, unit and budget line are immutable. The
     * original is NOT touched here: it stays the authoritative cost until the
     * successor passes PO and Finance verification, at which point the W6-4 reversing
     * pair replaces it (see financeVerifyCorrection()).
     *
     * Permission: finance.labour.correct.
     */
    public function correct(
        ProjectLabourActual $actual,
        User $corrector,
        array $corrections,
        string $correctionReason,
    ): ProjectLabourActual {
        $this->authorize($this->access->canCorrectLabour($corrector),
            'You do not have permission to correct labour actuals.');

        if (blank(trim($correctionReason))) {
            throw ValidationException::withMessages(['correction_reason' => ['A correction reason is required.']]);
        }

        return DB::transaction(function () use ($actual, $corrector, $corrections, $correctionReason) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));

            if ($actual->status === ProjectLabourActual::STATUS_SUPERSEDED) {
                throw ValidationException::withMessages([
                    'status' => ['This labour actual has already been superseded by a correction.'],
                ]);
            }

            // One successor per original — also enforced by the unique reversal_of_id.
            if (ProjectLabourActual::where('reversal_of_id', $actual->id)->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['A correction already exists for this labour actual.'],
                ]);
            }

            $this->assertStatus($actual, ProjectLabourActual::STATUS_FINANCE_VERIFIED, 'Correction');
            $this->assertStillAuthoritative($actual);

            $safe = array_intersect_key($corrections, array_flip(self::CORRECTION_FIELDS));

            $newData = array_merge([
                'project_enquiry_id' => $actual->project_enquiry_id,
                'budget_line_id'     => $actual->budget_line_id,
                'budget_id'          => $actual->budget_id,
                'consumes_cost_line_id' => $actual->consumes_cost_line_id,
                'labour_role'        => $actual->labour_role,
                'labour_category'    => $actual->labour_category,
                'budget_unit'        => $actual->budget_unit,
                'unit_rate'          => $actual->unit_rate,
                'actual_quantity'    => $actual->actual_quantity,
                'actual_days'        => $actual->actual_days,
                'actual_hours'       => $actual->actual_hours,
                'work_date'          => $actual->work_date?->toDateString(),
                'employee_id'        => $actual->employee_id,
                'is_unbudgeted'      => $actual->is_unbudgeted,
                'unbudgeted_reason'  => $actual->unbudgeted_reason,
                'rework_type'        => $actual->rework_type,
                'metadata'           => $actual->metadata,
            ], $safe);

            $corrected = ProjectLabourActual::create([
                ...$newData,
                'calculated_cost'   => $this->costFor($newData),
                'status'            => ProjectLabourActual::STATUS_RECORDED,
                'recorded_by'       => $corrector->id,
                'recorded_at'       => now(),
                'reversal_of_id'    => $actual->id,
                'correction_reason' => $correctionReason,
                'rate_resolution_status' => $actual->rate_resolution_status,
                'rate_source'       => $actual->rate_source,
            ]);

            $this->auditLog($corrected, $corrector, 'correct',
                "Correction of #{$actual->id} opened ({$actual->calculated_cost} → {$corrected->calculated_cost}): {$correctionReason}",
                ['original_id' => $actual->id, 'original_amount' => (string) $actual->calculated_cost, 'corrected_amount' => (string) $corrected->calculated_cost]);
            $this->auditLog($actual, $corrector, 'correction_requested',
                "Correction #{$corrected->id} opened; this actual remains authoritative until it is Finance-verified.");

            return $corrected->fresh();
        });
    }

    /**
     * Reclassify a Finance-verified labour actual to another project (W7-13 via W6-4).
     * The labour CostLine moves through CostTransferService::transfer() unchanged.
     * Permission: finance.labour.correct.
     */
    public function reclassify(
        ProjectLabourActual $actual,
        ProjectEnquiry $destination,
        User $actor,
        string $reason,
    ): ProjectLabourActual {
        $this->authorize($this->access->canCorrectLabour($actor),
            'You do not have permission to reclassify labour actuals.');

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => ['A reclassification reason is required.']]);
        }

        return DB::transaction(function () use ($actual, $destination, $actor, $reason) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));
            $this->assertStatus($actual, ProjectLabourActual::STATUS_FINANCE_VERIFIED, 'Reclassification');

            if (ProjectLabourActual::where('reversal_of_id', $actual->id)->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['This labour actual has a correction in progress; finish or return it first.'],
                ]);
            }
            $this->assertStillAuthoritative($actual);

            $transfer = $this->transfers->transfer($actual->actualCostLine, $destination, $actor->id, $reason);

            $actual->update(['reclassification_transfer_id' => $transfer->id]);

            $this->auditLog($actual, $actor, 'reclassify',
                "Labour cost reclassified to project #{$destination->id} ({$destination->job_number}) via transfer #{$transfer->id}: {$reason}",
                ['transfer_id' => $transfer->id, 'destination_enquiry_id' => $destination->id]);

            return $actual->fresh();
        });
    }

    /**
     * Finance-controlled rate resolution for unbudgeted labour (W7-6 control).
     *
     * A per-actual, Finance-authorised rate with a documented source and reference.
     * It is NOT a rate catalogue and never derives from payroll salary.
     * Permission: finance.labour.finance_verify.
     */
    public function resolveUnbudgetedRate(
        ProjectLabourActual $actual,
        User $financier,
        string $resolvedRate,
        string $rateSourceDescription,
        string $authorizationReference,
    ): ProjectLabourActual {
        $this->authorize($this->access->canFinanceVerifyLabour($financier),
            'You do not have permission to resolve unbudgeted labour rates.');

        if (!is_numeric($resolvedRate) || bccomp((string) $resolvedRate, '0', 2) !== 1) {
            throw ValidationException::withMessages(['resolved_rate' => ['Rate must be a positive amount.']]);
        }
        if (blank(trim($rateSourceDescription))) {
            throw ValidationException::withMessages(['rate_source_description' => ['A rate source is required.']]);
        }
        if (blank(trim($authorizationReference))) {
            throw ValidationException::withMessages(['authorization_reference' => ['An authorization reference is required.']]);
        }

        return DB::transaction(function () use ($actual, $financier, $resolvedRate, $rateSourceDescription, $authorizationReference) {
            $actual = ProjectLabourActual::query()->lockForUpdate()->findOrFail($actual->id);
            $this->guardClosure(ProjectEnquiry::query()->lockForUpdate()->findOrFail($actual->project_enquiry_id));

            $this->assertStatus($actual, ProjectLabourActual::STATUS_PO_VERIFIED, 'Rate resolution');
            if (!$actual->is_unbudgeted) {
                throw ValidationException::withMessages(['rate' => ['Rate resolution is only for unbudgeted labour.']]);
            }
            if ($actual->rate_resolution_status !== ProjectLabourActual::RATE_UNRESOLVED) {
                throw ValidationException::withMessages(['rate' => ['Rate is already resolved.']]);
            }

            $rate = $this->money($resolvedRate);
            $actual->update([
                'unit_rate' => $rate,
                'rate_resolution_status' => ProjectLabourActual::RATE_RESOLVED,
                'rate_source' => [
                    'type' => 'finance_override',
                    'resolved_by' => $financier->id,
                    'resolved_by_name' => $financier->name,
                    'resolved_at' => now()->toIso8601String(),
                    'rate' => $rate,
                    'source_description' => $rateSourceDescription,
                    'authorization_reference' => $authorizationReference,
                    'decision_required' => 'This rate resolution is per-actual Finance authorization. WNG has not yet approved a general unbudgeted labour rate catalogue.',
                ],
                'calculated_cost' => $this->costFor(array_merge(
                    $actual->only(['budget_unit', 'actual_quantity', 'actual_days', 'actual_hours']),
                    ['unit_rate' => $rate],
                )),
            ]);

            $this->auditLog($actual, $financier, 'resolve_unbudgeted_rate',
                "Finance resolved unbudgeted labour rate to {$rate}. Source: {$rateSourceDescription}. Reference: {$authorizationReference}");

            return $actual->fresh();
        });
    }

    // ── Private: correction ───────────────────────────────────────────────────

    /**
     * Finance verification of a correction successor ($successor is locked, PO-verified,
     * rate resolved, project open). The original's authoritative line is replaced by a
     * same-project reversing pair; the successor's CostLine is the IN leg.
     */
    private function financeVerifyCorrection(ProjectLabourActual $successor, User $verifier, ?string $notes): ProjectLabourActual
    {
        $original = ProjectLabourActual::query()->lockForUpdate()->findOrFail($successor->reversal_of_id);
        $this->assertStatus($original, ProjectLabourActual::STATUS_FINANCE_VERIFIED, 'Correction of the original');
        $this->assertStillAuthoritative($original);

        $transfer = $this->transfers->correct(
            $original->actualCostLine,
            (string) $successor->calculated_cost,
            $this->costLineDetails($successor),
            $verifier->id,
            "Labour correction #{$successor->id} of #{$original->id}: {$successor->correction_reason}",
        );

        $successor->update([
            'status'                 => ProjectLabourActual::STATUS_FINANCE_VERIFIED,
            'finance_verified_by'    => $verifier->id,
            'finance_verified_at'    => now(),
            'finance_notes'          => $notes,
            'cost_line_id'           => $transfer->in_cost_line_id,
            'correction_transfer_id' => $transfer->id,
        ]);

        $original->update([
            'status'           => ProjectLabourActual::STATUS_SUPERSEDED,
            'superseded_by_id' => $successor->id,
        ]);

        $this->auditLog($successor, $verifier, 'finance_verify',
            "Correction finance-verified via reversing pair (transfer #{$transfer->id}: OUT #{$transfer->out_cost_line_id}, IN #{$transfer->in_cost_line_id}).",
            ['transfer_id' => $transfer->id, 'original_id' => $original->id]);
        $this->auditLog($original, $verifier, 'supersede',
            "Superseded by #{$successor->id}; authoritative amount now {$successor->calculated_cost}.");

        return $successor->fresh(['actualCostLine']);
    }

    /** The actual's CostLine is still its authoritative line (not moved or corrected). */
    private function assertStillAuthoritative(ProjectLabourActual $actual): void
    {
        if ($actual->reclassification_transfer_id) {
            throw ValidationException::withMessages([
                'status' => ['This labour cost has been reclassified to another project.'],
            ]);
        }
        $line = $actual->cost_line_id ? CostLine::find($actual->cost_line_id) : null;
        if (!$line || $line->status !== CostLine::STATUS_VERIFIED
            || CostLineTransfer::where('source_cost_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages([
                'status' => ['This labour actual no longer carries an authoritative cost line.'],
            ]);
        }
    }

    // ── Private: budget ───────────────────────────────────────────────────────

    /** W7 records budgeted labour only against the finalized project budget (ProjectBudgetAuthority). */
    private function authoritativeBudget(ProjectEnquiry $enquiry): ?TaskBudgetData
    {
        return $this->budgets->authoritativeBudget($enquiry->id);
    }

    private function authoritativeBudgetData(ProjectEnquiry $enquiry, string $lineId): array
    {
        // The budget must be this project's own finalized budget (cross-project guard).
        $budget = $this->authoritativeBudget($enquiry);

        if (!$budget) {
            $message = $this->budgets->state($enquiry->id) === ProjectBudgetAuthority::STATE_IN_PROGRESS
                ? 'The Project Budget is still in progress. Complete the Budget task before recording budgeted labour.'
                : 'This project has no Project Budget.';
            throw ValidationException::withMessages(['budget_line_id' => [$message]]);
        }

        $line = collect($budget->labour_data ?? [])->firstWhere('id', $lineId);
        if (!$line || !($line['isIncluded'] ?? true)) {
            throw ValidationException::withMessages(['budget_line_id' => ['The selected line is not active labour in this project\'s current Project Budget.']]);
        }

        if (!isset($line['type']) && !isset($line['description'])) {
            throw ValidationException::withMessages(['budget_line_id' => ['The selected line is not a valid labour line.']]);
        }

        $planned = CostLine::query()->where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_PLANNED)->where('status', CostLine::STATUS_VERIFIED)
            ->where('source_id', $budget->id)->where('source_ref', $lineId)
            ->where('details->budget_category', 'labour')->first();
        if (!$planned) {
            throw ValidationException::withMessages(['budget_line_id' => ['This budget labour line has no active planned CostLine. Re-save the Project Budget (or run finance:project-budgets) to project it.']]);
        }

        return [
            'budget_id' => $budget->id, 'consumes_cost_line_id' => $planned->id,
            'labour_role' => (string) ($line['type'] ?? $line['description'] ?? 'Labour'),
            'labour_category' => (string) ($line['category'] ?? 'Other'),
            'budget_unit' => (string) ($line['unit'] ?? 'PAX'),
            'unit_rate' => $this->money($line['unitRate'] ?? '0'),
            'rate_source' => ['type' => 'project_budget', 'id' => $budget->id, 'line_id' => $lineId],
            'rate_resolution_status' => ProjectLabourActual::RATE_RESOLVED,
        ];
    }

    /**
     * The planned line a budgeted actual consumes at Finance verification. A budget
     * revision supersedes the planned CostLine the actual was recorded against; the
     * consumption follows the same budget line id onto its current planned line, while
     * the rate stays the snapshot taken at record time.
     */
    private function currentPlannedLineId(ProjectLabourActual $actual): ?int
    {
        if ($actual->is_unbudgeted || !$actual->budget_line_id) {
            return null;
        }

        $recorded = $actual->consumes_cost_line_id ? CostLine::find($actual->consumes_cost_line_id) : null;
        if ($recorded && $recorded->status === CostLine::STATUS_VERIFIED) {
            return $recorded->id;
        }

        $current = CostLine::query()->where('project_enquiry_id', $actual->project_enquiry_id)
            ->where('nature', CostLine::NATURE_PLANNED)->where('status', CostLine::STATUS_VERIFIED)
            ->where('source_ref', $actual->budget_line_id)
            ->where('details->budget_category', 'labour')
            ->latest('id')->first();

        if (!$current) {
            throw ValidationException::withMessages(['budget_line_id' => [
                'This budget line is no longer in the current Project Budget. Return the actual for correction to re-select a line.',
            ]]);
        }

        return $current->id;
    }

    private function unresolvedUnbudgetedData(array $data): array
    {
        if (blank($data['unbudgeted_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'unbudgeted_reason' => ['An unbudgeted reason is required when recording unbudgeted labour.'],
            ]);
        }

        return array_merge($data, [
            'budget_line_id' => null, 'budget_id' => null, 'consumes_cost_line_id' => null,
            'unit_rate' => '0.00', 'rate_resolution_status' => ProjectLabourActual::RATE_UNRESOLVED,
            'rate_source' => [
                'type' => 'unresolved',
                'reason' => 'No approved rate exists for unbudgeted labour. Finance must resolve a rate with a documented source and authorization before verification.',
                'decision_required' => 'WNG has not approved a general unbudgeted labour rate source; each rate is a per-actual Finance authorization.',
            ],
        ]);
    }

    /**
     * Verified Actual labour cost per budget line — the same verified CostLine pool
     * Project Costing sums, so the two always agree.
     *
     * @return array<string, string>
     */
    private function verifiedCostByBudgetLine(ProjectEnquiry $enquiry): array
    {
        return CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->counting()
            ->where('details->budget_category', 'labour')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_line_id')) AS line_id, SUM(net_amount) AS total")
            ->groupBy('line_id')
            ->get()
            // Unbudgeted labour has no line id (JSON null / absent) — not a budget line.
            ->reject(fn ($row) => $row->line_id === null || $row->line_id === 'null')
            ->mapWithKeys(fn ($row) => [$row->line_id => $this->money($row->total)])
            ->all();
    }

    /**
     * Cost still awaiting Finance verification, per budget line. A pending correction
     * contributes only its difference from the original, which is already verified.
     *
     * @return array<string, string>
     */
    private function pendingCostByBudgetLine(ProjectEnquiry $enquiry): array
    {
        $pending = ProjectLabourActual::forEnquiry($enquiry->id)
            ->whereIn('status', [ProjectLabourActual::STATUS_RECORDED, ProjectLabourActual::STATUS_PO_VERIFIED])
            ->whereNotNull('budget_line_id')
            ->with('reversalOf:id,calculated_cost')
            ->get(['id', 'budget_line_id', 'calculated_cost', 'reversal_of_id']);

        $byLine = [];
        foreach ($pending as $actual) {
            $delta = (string) $actual->calculated_cost;
            if ($actual->reversalOf) {
                $delta = bcsub($delta, (string) $actual->reversalOf->calculated_cost, 2);
            }
            $byLine[$actual->budget_line_id] = bcadd($byLine[$actual->budget_line_id] ?? '0.00', $delta, 2);
        }

        return $byLine;
    }

    /**
     * W7-7: alert when verified + pending labour on the line exceeds its budget.
     * Never a hard stop.
     */
    private function checkBudgetAlert(ProjectLabourActual $actual, ProjectEnquiry $enquiry): void
    {
        if ($actual->is_unbudgeted || !$actual->budget_line_id) {
            return;
        }

        $line = collect($this->getBudgetLabourLines($enquiry))->firstWhere('id', $actual->budget_line_id);
        if (!$line) {
            return;
        }

        $projected = bcadd($line['verified_cost'], $line['pending_cost'], 2);
        if (bccomp($projected, $line['budget_amount'], 2) !== 1) {
            return;
        }

        $overage = bcsub($projected, $line['budget_amount'], 2);
        GovernanceAuditLog::create([
            'project_enquiry_id' => $enquiry->id,
            'user_id'            => $actual->recorded_by,
            'gate_type'          => 'labour_cost',
            'action_status'      => 'alert',
            'model_type'         => ProjectLabourActual::class,
            'model_id'           => $actual->id,
            'message'            => "W7-7: Labour line overrun. Budget: {$line['budget_amount']}, Total confirmed: {$projected}, Overage: {$overage}.",
            'context'            => [
                'budget_line_id'   => $actual->budget_line_id,
                'budget_amount'    => $line['budget_amount'],
                'total_confirmed'  => $projected,
                'overage'          => $overage,
            ],
            'ip_address'         => request()?->ip(),
        ]);
    }

    // ── Private: money ────────────────────────────────────────────────────────

    /** W7-2 cost from a data array holding budget_unit, usage fields and unit_rate. */
    private function costFor(array $data): string
    {
        return $this->calculateCost(
            unit: (string) $data['budget_unit'],
            quantity: $this->decimalOrNull($data['actual_quantity'] ?? null),
            days: $this->decimalOrNull($data['actual_days'] ?? null),
            hours: $this->decimalOrNull($data['actual_hours'] ?? null),
            unitRate: (string) $data['unit_rate'],
        );
    }

    /**
     * W7-2: Cost formula, exact in bcmath, rounded half-up to the cent once.
     *   hours unit:    actual_hours × unit_rate
     *   PAX/days unit: actual_quantity × actual_days × unit_rate
     */
    private function calculateCost(
        string $unit,
        ?string $quantity,
        ?string $days,
        ?string $hours,
        string $unitRate,
    ): string {
        if (in_array(strtolower($unit), ['hours', 'hrs'], true)) {
            if ($hours === null || bccomp($hours, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'actual_hours' => ['actual_hours is required for an hours-unit labour line.'],
                ]);
            }
            return $this->roundMoney(bcmul($hours, $unitRate, 8));
        }

        return $this->roundMoney(bcmul(bcmul($quantity ?? '1', $days ?? '1', 8), $unitRate, 8));
    }

    /** Round a non-negative-or-negative decimal string half-up (away from zero) to 2dp. */
    private function roundMoney(string $value): string
    {
        $half = str_starts_with($value, '-') ? '-0.005' : '0.005';
        return bcadd(bcadd($value, $half, 8), '0', 2);
    }

    private function money(mixed $value): string
    {
        return $this->roundMoney(is_numeric($value) ? (string) $value : '0');
    }

    private function decimalOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    // ── Private: misc ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function costLineDetails(ProjectLabourActual $actual): array
    {
        $rework = $actual->rework_type !== ProjectLabourActual::REWORK_NONE ? "Rework: {$actual->rework_type}" : null;

        return [
            'budget_category'  => 'labour',
            'labour_role'      => $actual->labour_role,
            'labour_category'  => $actual->labour_category,
            'budget_unit'      => $actual->budget_unit,
            'unit_rate'        => (string) $actual->unit_rate,
            'actual_quantity'  => $actual->actual_quantity,
            'actual_days'      => $actual->actual_days,
            'actual_hours'     => $actual->actual_hours,
            'work_date'        => $actual->work_date?->toDateString(),
            'budget_line_id'   => $actual->budget_line_id,
            'budget_id'        => $actual->budget_id,
            'rate_source'      => $actual->rate_source,
            'is_unbudgeted'    => $actual->is_unbudgeted,
            'unbudgeted_reason'=> $actual->unbudgeted_reason,
            'rework_type'      => $actual->rework_type,
            'labour_actual_id' => $actual->id,
            // Expense-code required fields.
            'worker_count'     => $actual->actual_quantity ?? $actual->actual_days ?? 0,
            'days_worked'      => $actual->actual_days ?? 0,
            'what_went_wrong'  => $actual->correction_reason ?? $actual->unbudgeted_reason ?? $rework,
            // RW-LAB-001 requires 'rework_reason' ("What went wrong").
            'rework_reason'    => $rework,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function evidence(ProjectLabourActual $actual): array
    {
        return array_values(array_filter([
            ['key' => 'muster_sheet', 'label' => 'W7 Labour Actual Record', 'reference' => "W7-{$actual->id}"],
            // RW-LAB-001 requires a 'receipt' (work-authorization document); for
            // internal rework the classified muster record serves as that authority.
            $actual->rework_type !== ProjectLabourActual::REWORK_NONE
                ? ['key' => 'receipt', 'label' => 'Rework Work Authorization', 'reference' => "W7-REWORK-{$actual->id}"]
                : null,
        ]));
    }

    /** Resolve the expense code from the labour category/rework type. */
    private function resolveExpenseCode(ProjectLabourActual $actual): string
    {
        if ($actual->rework_type !== ProjectLabourActual::REWORK_NONE) {
            return 'RW-LAB-001';
        }

        return match (strtolower($actual->labour_category ?? '')) {
            'workshop', 'workshop_labour' => 'DL-CAS-002',
            'allowance', 'site_allowance' => 'DL-ALW-001',
            default                       => 'DL-CAS-001',  // site labour
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectLabourActual $actual): array
    {
        $snapshot = [];
        foreach ([
            'status', 'budget_line_id', 'budget_id', 'consumes_cost_line_id', 'labour_role', 'labour_category',
            'budget_unit', 'unit_rate', 'rate_resolution_status', 'actual_quantity', 'actual_days',
            'actual_hours', 'calculated_cost', 'employee_id', 'rework_type', 'unbudgeted_reason',
        ] as $field) {
            $value = $actual->{$field};
            $snapshot[$field] = $value === null ? null : (string) $value;
        }
        $snapshot['work_date'] = $actual->work_date?->toDateString();
        $snapshot['rate_source'] = $actual->rate_source;

        return $snapshot;
    }

    private function differs(mixed $old, mixed $new): bool
    {
        if (is_numeric($old) && is_numeric($new)) {
            return bccomp((string) $old, (string) $new, 4) !== 0;
        }
        return $old != $new;
    }

    /** W6-5: Guard — project must not be financially closed. */
    private function guardClosure(ProjectEnquiry $enquiry): void
    {
        if ($enquiry->financial_closure_status === 'closed') {
            throw ValidationException::withMessages([
                'project_enquiry_id' => [
                    "Project #{$enquiry->job_number} is financially closed. "
                    . 'Reopen it (finance.costs.reopen) before changing labour costs.',
                ],
            ]);
        }
    }

    private function assertStatus(ProjectLabourActual $actual, string $expected, string $action): void
    {
        if ($actual->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => [
                    "{$action} requires status '{$expected}', but actual is '{$actual->status}'.",
                ],
            ]);
        }
    }

    private function authorize(bool $allowed, string $message): void
    {
        if (!$allowed) {
            throw new HttpException(403, $message);
        }
    }

    /** Write a governance audit entry (actor + timestamp via created_at). */
    private function auditLog(
        ProjectLabourActual $actual,
        User $user,
        string $action,
        string $message,
        array $extra = [],
    ): void {
        GovernanceAuditLog::create([
            'project_enquiry_id' => $actual->project_enquiry_id,
            'user_id'            => $user->id,
            'gate_type'          => 'labour_cost',
            'action_status'      => $action,
            'model_type'         => ProjectLabourActual::class,
            'model_id'           => $actual->id,
            'message'            => $message,
            'context'            => array_merge([
                'status'           => $actual->status,
                'budget_line_id'   => $actual->budget_line_id,
                'calculated_cost'  => (string) $actual->calculated_cost,
                'is_unbudgeted'    => $actual->is_unbudgeted,
            ], $extra),
            'ip_address'         => request()?->ip(),
        ]);
    }
}
