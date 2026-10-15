<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Modules\ProcurementStores\Services\MaterialRequirementRetirements;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Report 78C — the operational material numbers with the lists behind them,
 * and requirement-level legacy retirement.
 */
class ProjectMaterialWorkflowController extends Controller
{
    public function __construct(
        private readonly ProjectMaterialWorkflowSummary $summary,
        private readonly MaterialRequirementRetirements $retirements,
    ) {}

    public function summary(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $this->summary->summarise()]);
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorizeView();
        $validated = $request->validate([
            'metric' => ['required', Rule::in(ProjectMaterialWorkflowSummary::METRICS)],
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        $rows = $this->summary->queue($validated['metric'], isset($validated['project_id']) ? (int) $validated['project_id'] : null);

        return response()->json(['data' => $rows, 'meta' => ['metric' => $validated['metric'], 'count' => $rows->count()]]);
    }

    public function retirements(Project $project): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $this->retirements->history($project)]);
    }

    public function retire(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::PROJECT_MATERIALS_RETIRE_LEGACY), 403, 'You are not permitted to retire requirements as fulfilled outside the ERP.');
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
            'line_ids' => 'nullable|array|min:1',
            'line_ids.*' => 'integer|distinct',
        ]);
        $created = $this->retirements->retire($project, $validated['line_ids'] ?? null, $validated['reason'], (int) $request->user()->id);

        return response()->json([
            'message' => "{$created->count()} requirement line(s) recorded as fulfilled outside the ERP. No stock or accounting entries were made.",
            'data' => $created,
        ], 201);
    }

    public function revoke(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::PROJECT_MATERIALS_RETIRE_LEGACY), 403, 'You are not permitted to revoke legacy retirements.');
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
            'reconciliation_note' => 'required|string|max:4000',
            'retirement_ids' => 'nullable|array|min:1',
            'retirement_ids.*' => 'integer|distinct',
        ]);
        $count = $this->retirements->revoke($project, $validated['retirement_ids'] ?? null, $validated['reason'], $validated['reconciliation_note'], (int) $request->user()->id);

        return response()->json(['message' => "{$count} retirement(s) revoked. The quantities are live demand again."]);
    }

    private function authorizeView(): void
    {
        $user = request()->user();
        abort_unless(
            $user && ($user->can(Permissions::STORES_VIEW) || $user->can(Permissions::STORES_MANAGE)
                || $user->can(Permissions::PROCUREMENT_VIEW) || $user->can(Permissions::PROJECT_MATERIALS_RETIRE_LEGACY)),
            403,
            'You are not permitted to view material workflow queues.',
        );
    }
}
