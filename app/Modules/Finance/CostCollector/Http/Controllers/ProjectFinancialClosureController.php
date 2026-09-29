<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Services\ProjectFinancialClosureService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectFinancialClosureController extends Controller
{
    public function __construct(
        private ProjectFinancialClosureService $service,
        private ProjectFinancialAccess $access,
    ) {}

    /** GET /api/costs/projects/{enquiry}/closure-check */
    public function check(ProjectEnquiry $enquiry): JsonResponse
    {
        abort_unless($this->access->canClose(request()->user()), 403);

        return response()->json(['data' => $this->service->checkPreClosure($enquiry)]);
    }

    /** POST /api/costs/projects/{enquiry}/close */
    public function close(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        abort_unless($this->access->canClose($request->user()), 403);

        $this->service->close($enquiry, (int) $request->user()->id);

        return response()->json(['data' => ['financial_closure_status' => 'closed']]);
    }

    /** POST /api/costs/projects/{enquiry}/reopen */
    public function reopen(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        // Reopen requires the special permission — unassigned to any role by default.
        abort_unless($request->user()->hasPermissionTo(Permissions::FINANCE_COSTS_REOPEN), 403);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->service->reopen($enquiry, (int) $request->user()->id, $validated['reason']);

        return response()->json(['data' => [
            'financial_closure_status' => 'open',
            'reopen_reason'            => $validated['reason'],
        ]]);
    }
}
