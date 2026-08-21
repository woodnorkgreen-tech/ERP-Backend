<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Http\Resources\CostLineResource;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ProjectFinancialAccess;
use App\Constants\Permissions;
use App\Models\TaskBudgetData;

class CostAccountController extends Controller
{
    public function __construct(
        private CostAccountService $accounts,
        private ProjectFinancialAccess $access,
    ) {}

    /** Every project's cost account, one row each — the accounts grid. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        $validated = $request->validate([
            'status' => 'nullable|string|max:64',
            'cost_centre_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'q' => 'nullable|string|max:120',
            'sort' => 'nullable|in:planned,committed,accrued,actual,unbudgeted,last_cost',
            'direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filters = array_filter($validated, fn ($value) => $value !== null && $value !== '');

        // Read through `boolean()` rather than validated as `boolean`: the query
        // string carries the literal "true", which that rule rejects.
        foreach (['overrun_only', 'unbudgeted_only'] as $flag) {
            if ($request->boolean($flag)) {
                $filters[$flag] = true;
            }
        }

        return response()->json($this->accounts->index($filters, $validated['per_page'] ?? 25));
    }

    public function show(ProjectEnquiry $enquiry): JsonResponse
    {
        $user = request()->user();
        abort_unless($this->access->canReadAccount($user, $enquiry), 403);

        // Budget additions are gone: unplanned spend is captured directly on the
        // cost account through "Record a project cost", where it lands as an
        // unbudgeted actual line rather than a pending change awaiting approval.
        $data = $this->accounts->forEnquiry($enquiry);

        return response()->json(['data' => $data]);
    }

    /**
     * The lines behind one category on a project's cost account.
     *
     * The panel's whole purpose is explaining a variance, and it stopped one
     * level short of the costs that caused it.
     */
    public function categoryLines(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        abort_unless($this->access->canReadAccount($request->user(), $enquiry), 403);

        $validated = $request->validate([
            'category' => 'required|string|max:190',
        ]);

        $result = $this->accounts->linesForCategory($enquiry, $validated['category']);

        return response()->json([
            'category' => $result['category'],
            'planned' => CostLineResource::collection($result['planned']),
            'spend' => CostLineResource::collection($result['spend']),
            // The same lines, grouped the way materials are planned and built.
            // Served alongside the flat lists rather than instead of them so a
            // caller that wants the whole category still has it.
            'elements' => collect($result['elements'])->map(fn (array $group) => [
                'element' => $group['element'],
                'planned' => CostLineResource::collection($group['planned']),
                'spend' => CostLineResource::collection($group['spend']),
                'planned_total' => $group['planned_total'],
                'spend_total' => $group['spend_total'],
            ])->values(),
        ]);
    }
}
