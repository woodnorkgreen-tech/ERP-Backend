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

        $data = $this->accounts->forEnquiry($enquiry);
        $budgetTask = $enquiry->enquiryTasks()->where('type', 'budget')->first();
        $budgetData = $budgetTask
            ? TaskBudgetData::query()->where('enquiry_task_id', $budgetTask->id)->first()
            : null;
        $pending = $budgetData
            ? $budgetData->budgetAdditions()->where('status', 'pending_approval')
                ->with('creator:id,name')->latest()->get()
            : collect();

        $data['budget_change'] = [
            'task_id' => $budgetTask?->id,
            'pending_total' => number_format((float) $pending->sum('total_amount'), 2, '.', ''),
            'forecast_budget' => number_format(
                (float) $data['totals']['planned'] + (float) $pending->sum('total_amount'),
                2, '.', ''
            ),
            'pending' => $pending->map(fn ($addition) => [
                'id' => $addition->id,
                'title' => $addition->title,
                'description' => $addition->description,
                'total_amount' => $addition->total_amount,
                'created_at' => $addition->created_at,
                'creator' => $addition->creator?->name,
            ])->values(),
            'can_create' => $budgetTask ? $this->access->canCreateAddition($user, $budgetTask) : false,
            'can_decide' => $user->can(Permissions::FINANCE_BUDGET_ADDITIONS_APPROVE)
                || $user->can(Permissions::FINANCE_BUDGET_ADDITIONS_REJECT),
        ];

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
        ]);
    }
}
