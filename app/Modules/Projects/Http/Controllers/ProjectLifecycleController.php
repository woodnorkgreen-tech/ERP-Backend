<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility;
use App\Modules\Projects\Actions\ProjectLifecycleAction;
use App\Modules\Projects\Models\ProjectHold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Report 78C — governed cancel / reactivate / hold, and a job's lifecycle
 * record. Authorisation is route middleware (PROJECT_LIFECYCLE_GOVERN,
 * PROJECT_HOLD_MANAGE); the rules live in {@see ProjectLifecycleAction}.
 */
class ProjectLifecycleController extends Controller
{
    public function __construct(private readonly ProjectLifecycleAction $action) {}

    public function show(ProjectEnquiry $enquiry, ProjectMaterialWorkflowEligibility $eligibility): JsonResponse
    {
        return response()->json(['data' => [
            'lifecycle' => $eligibility->describeMany([$enquiry->id])[$enquiry->id] ?? null,
            'holds' => ProjectHold::query()->where('project_enquiry_id', $enquiry->id)
                ->with(['placedBy', 'releasedBy'])->orderByDesc('id')->get(),
            'events' => GovernanceAuditLog::query()->where('project_enquiry_id', $enquiry->id)
                ->whereIn('gate_type', [
                    'project_completed', 'project_closed', 'project_cancelled', 'project_reactivated',
                    'project_hold_placed', 'project_hold_released', 'material_requirements_retired',
                    'material_requirements_retirement_revoked', 'material_lifecycle_override',
                ])
                ->with('user:id,name')->orderByDesc('id')->limit(50)
                ->get(['id', 'user_id', 'gate_type', 'message', 'context', 'created_at']),
            'open_commitments' => $this->action->openCommitments($enquiry),
        ]]);
    }

    public function cancel(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:2000']);
        $result = $this->action->cancel($enquiry, (int) Auth::id(), $validated['reason']);

        return response()->json([
            'message' => 'Project cancelled. Open procurement commitments were left in place for review.',
            'data' => $result['enquiry'],
            'open_commitments' => $result['open_commitments'],
        ]);
    }

    public function reactivate(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
            'reconciliation_note' => 'nullable|string|max:4000',
        ]);
        $result = $this->action->reactivate($enquiry, (int) Auth::id(), $validated['reason'], $validated['reconciliation_note'] ?? null);

        return response()->json([
            'message' => 'Project reactivated.',
            'data' => $result['enquiry'],
            'reappearing_demand' => $result['reappearing'],
        ]);
    }

    public function placeHold(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json([
            'message' => 'Project put on hold. Material issue and new procurement are paused.',
            'data' => $this->action->placeHold($enquiry, (int) Auth::id(), $validated['reason']),
        ], 201);
    }

    public function releaseHold(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json([
            'message' => 'Hold released. The job is back in active work.',
            'data' => $this->action->releaseHold($enquiry, (int) Auth::id(), $validated['reason']),
        ]);
    }
}
