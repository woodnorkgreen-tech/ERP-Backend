<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectLabourActualResource;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActual;
use App\Modules\Finance\CostCollector\Services\ProjectLabourActualService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * W7 Labour Cost — Project Labour Actuals lifecycle controller.
 *
 * All routes are project-scoped under /api/costs/projects/{enquiry}/labour-actuals.
 * Each transition is its own action so it can be permission-gated and tested alone;
 * the service re-checks every permission, so the controller is not the only guard.
 */
class ProjectLabourActualController extends Controller
{
    /** Usage is stored at 2dp; accepting more would let the cost and the stored usage disagree. */
    private const USAGE_RULE = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];

    public function __construct(
        private ProjectLabourActualService $service,
        private ProjectFinancialAccess $access,
    ) {}

    // ── Budget labour lines ───────────────────────────────────────────────────

    /** GET projects/{enquiry}/budget-labour-lines */
    public function budgetLines(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $this->assertAuthorized($this->access->canViewLabour($request->user(), $enquiry));

        return response()->json([
            'data' => $this->service->getBudgetLabourLines($enquiry),
            'meta' => $this->projectMeta($enquiry),
        ]);
    }

    // ── Labour actuals index/show ─────────────────────────────────────────────

    /** GET projects/{enquiry}/labour-actuals */
    public function index(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $this->assertAuthorized($this->access->canViewLabour($request->user(), $enquiry));

        return response()->json([
            'data' => ProjectLabourActualResource::collection($this->service->index($enquiry)),
            'meta' => $this->projectMeta($enquiry),
        ]);
    }

    /** GET projects/{enquiry}/labour-actuals/{actual} */
    public function show(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);
        $this->assertAuthorized($this->access->canViewLabour($request->user(), $enquiry));

        return $this->respond($actual);
    }

    // ── Record (create) ───────────────────────────────────────────────────────

    /** POST projects/{enquiry}/labour-actuals */
    public function store(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        if (!$this->access->canRecordLabour($request->user(), $enquiry)) {
            abort(403, 'You do not have permission to record labour actuals for this project.');
        }

        $validated = $request->validate([
            'budget_line_id'    => ['nullable', 'string', 'max:100'],
            'labour_role'       => ['nullable', 'required_if:is_unbudgeted,true', 'string', 'max:150'],
            'labour_category'   => ['nullable', 'required_if:is_unbudgeted,true', 'string', 'max:100'],
            'budget_unit'       => ['nullable', 'required_if:is_unbudgeted,true', 'string', 'max:50'],
            'unit_rate'         => ['nullable', 'numeric', 'min:0'],
            'actual_quantity'   => self::USAGE_RULE,
            'actual_days'       => self::USAGE_RULE,
            'actual_hours'      => self::USAGE_RULE,
            'work_date'         => ['required', 'date'],
            'employee_id'       => ['nullable', 'integer', 'exists:employees,id'],
            'is_unbudgeted'     => ['boolean'],
            'unbudgeted_reason' => ['nullable', 'string', 'max:500',
                Rule::requiredIf((bool) $request->is_unbudgeted),
            ],
            'rework_type'       => ['nullable', Rule::in($this->reworkTypes())],
            'metadata'          => ['nullable', 'array'],
        ]);

        if (!($validated['is_unbudgeted'] ?? false) && empty($validated['budget_line_id'])) {
            return response()->json([
                'message' => 'budget_line_id is required for budgeted labour actuals.',
                'errors'  => ['budget_line_id' => ['Required when is_unbudgeted is false.']],
            ], 422);
        }

        // SERVER-AUTHORITATIVE: a budgeted actual takes role, category, unit and rate
        // from the approved budget; an unbudgeted actual's rate is unresolved until
        // Finance resolves it. A client-supplied rate is never used.
        unset($validated['unit_rate']);
        if (!($validated['is_unbudgeted'] ?? false)) {
            unset($validated['labour_role'], $validated['labour_category'], $validated['budget_unit']);
        }

        $actual = $this->service->record(
            array_merge($validated, ['project_enquiry_id' => $enquiry->id]),
            $request->user(),
        );

        return $this->respond($actual, 'Labour actual recorded.', 201);
    }

    // ── Verification ──────────────────────────────────────────────────────────

    /** POST .../{actual}/po-verify */
    public function poVerify(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canPoVerifyLabour($request->user(), $enquiry)) {
            abort(403, 'You do not have permission to PO-verify labour actuals for this project.');
        }

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->respond(
            $this->service->poVerify($actual, $request->user(), $validated['notes'] ?? null),
            'Labour actual PO-verified.',
        );
    }

    /** POST .../{actual}/finance-verify */
    public function financeVerify(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canFinanceVerifyLabour($request->user())) {
            abort(403, 'You do not have permission to finance-verify labour actuals.');
        }

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->respond(
            $this->service->financeVerify($actual, $request->user(), $validated['notes'] ?? null),
            'Labour actual finance-verified.',
        );
    }

    // ── Return → correct → resubmit ───────────────────────────────────────────

    /** POST .../{actual}/return */
    public function returnForCorrection(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        $canReturn = $this->access->canPoVerifyLabour($request->user(), $enquiry)
            || $this->access->canFinanceVerifyLabour($request->user());
        if (!$canReturn) {
            abort(403, 'You do not have permission to return this labour actual.');
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->respond(
            $this->service->returnForCorrection($actual, $request->user(), $validated['reason']),
            'Labour actual returned for correction.',
        );
    }

    /**
     * POST .../{actual}/resubmit
     *
     * The recorder corrects permitted fields of a returned actual and resubmits it
     * for Project Officer review. Rate is never accepted from the client.
     */
    public function resubmit(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canResubmitLabour($request->user(), $enquiry)) {
            abort(403, 'You do not have permission to resubmit labour actuals for this project.');
        }

        $validated = $request->validate([
            'budget_line_id'    => ['sometimes', 'string', 'max:100'],
            'labour_role'       => ['sometimes', 'string', 'max:150'],
            'labour_category'   => ['sometimes', 'string', 'max:100'],
            'budget_unit'       => ['sometimes', 'string', 'max:50'],
            'actual_quantity'   => ['sometimes', ...self::USAGE_RULE],
            'actual_days'       => ['sometimes', ...self::USAGE_RULE],
            'actual_hours'      => ['sometimes', ...self::USAGE_RULE],
            'work_date'         => ['sometimes', 'date'],
            'employee_id'       => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'unbudgeted_reason' => ['sometimes', 'string', 'max:500'],
            'rework_type'       => ['sometimes', Rule::in($this->reworkTypes())],
        ]);

        return $this->respond(
            $this->service->resubmit($actual, $request->user(), $validated),
            'Labour actual corrected and resubmitted for Project Officer review.',
        );
    }

    // ── Unbudgeted rate (Finance) ─────────────────────────────────────────────

    /**
     * POST .../{actual}/resolve-rate
     *
     * Finance-controlled, per-actual rate for unbudgeted labour — not a rate source
     * of its own and never derived from payroll salary.
     */
    public function resolveRate(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canFinanceVerifyLabour($request->user())) {
            abort(403, 'You do not have permission to resolve unbudgeted labour rates.');
        }

        $validated = $request->validate([
            'resolved_rate' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'rate_source_description' => ['required', 'string', 'max:500'],
            'authorization_reference' => ['required', 'string', 'max:200'],
        ]);

        $resolved = $this->service->resolveUnbudgetedRate(
            $actual,
            $request->user(),
            (string) $validated['resolved_rate'],
            $validated['rate_source_description'],
            $validated['authorization_reference'],
        );

        return $this->respond($resolved, "Unbudgeted labour rate resolved to {$resolved->unit_rate}. Ready for Finance verification.");
    }

    // ── Verified correction / reclassification (W7-13) ────────────────────────

    /**
     * POST .../{actual}/correct
     *
     * Opens a correction of a Finance-verified actual. The original stays
     * authoritative until the correction is Finance-verified.
     */
    public function correct(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canCorrectLabour($request->user())) {
            abort(403, 'You do not have permission to correct labour actuals.');
        }

        $validated = $request->validate([
            'correction_reason' => ['required', 'string', 'max:500'],
            // Correctable usage only — rate, role, category, unit and budget line are immutable.
            'actual_quantity'   => self::USAGE_RULE,
            'actual_days'       => self::USAGE_RULE,
            'actual_hours'      => self::USAGE_RULE,
            'work_date'         => ['nullable', 'date'],
            'employee_id'       => ['nullable', 'integer', 'exists:employees,id'],
            'unbudgeted_reason' => ['nullable', 'string', 'max:500'],
            'rework_type'       => ['nullable', Rule::in($this->reworkTypes())],
        ]);

        $corrections = array_filter(
            array_diff_key($validated, ['correction_reason' => true]),
            fn ($v) => $v !== null,
        );

        $corrected = $this->service->correct($actual, $request->user(), $corrections, $validated['correction_reason']);

        return $this->respond(
            $corrected,
            "Correction #{$corrected->id} opened. Original #{$actual->id} stays authoritative until the correction is Finance-verified.",
            201,
        );
    }

    /** POST .../{actual}/reclassify — move the verified labour cost to another project. */
    public function reclassify(Request $request, ProjectEnquiry $enquiry, ProjectLabourActual $actual): JsonResponse
    {
        $this->ensureBelongsToEnquiry($actual, $enquiry);

        if (!$this->access->canCorrectLabour($request->user())) {
            abort(403, 'You do not have permission to reclassify labour actuals.');
        }

        $validated = $request->validate([
            'destination_enquiry_id' => ['required', 'integer', 'exists:project_enquiries,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $destination = ProjectEnquiry::findOrFail($validated['destination_enquiry_id']);

        return $this->respond(
            $this->service->reclassify($actual, $destination, $request->user(), $validated['reason']),
            "Labour cost reclassified to {$destination->job_number}.",
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function respond(ProjectLabourActual $actual, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'data' => new ProjectLabourActualResource($actual->load($this->service->resourceRelations())),
            'message' => $message,
        ]), $status);
    }

    /** Closure state lets the UI show a financially closed project; the server still enforces it. */
    private function projectMeta(ProjectEnquiry $enquiry): array
    {
        return [
            'financial_closure_status' => $enquiry->financial_closure_status ?? 'open',
            'financially_closed_at' => $enquiry->financially_closed_at?->toIso8601String(),
            // none | in_progress | finalized: budgeted labour needs a finalized Project Budget.
            'budget_state' => $this->service->budgetState($enquiry),
        ];
    }

    /** @return array<int, string> */
    private function reworkTypes(): array
    {
        return [
            ProjectLabourActual::REWORK_NONE,
            ProjectLabourActual::REWORK_CLIENT_CAUSED,
            ProjectLabourActual::REWORK_INTERNAL,
        ];
    }

    private function assertAuthorized(bool $allowed): void
    {
        if (!$allowed) {
            abort(403, 'You do not have permission to view labour actuals for this project.');
        }
    }

    private function ensureBelongsToEnquiry(ProjectLabourActual $actual, ProjectEnquiry $enquiry): void
    {
        if ($actual->project_enquiry_id !== $enquiry->id) {
            abort(404, 'Labour actual not found for this project.');
        }
    }
}
