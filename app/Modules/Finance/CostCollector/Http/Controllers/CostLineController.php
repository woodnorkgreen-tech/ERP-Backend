<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Contracts\CollectsCost;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Http\Requests\StoreCostLineRequest;
use App\Modules\Finance\CostCollector\Http\Resources\CostLineResource;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostCollectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostLineController extends Controller
{
    public function __construct(private CollectsCost $collector, private CostCollectorService $costs) {}

    public function store(StoreCostLineRequest $request): JsonResponse
    {
        // finance.costs.create was seeded and granted but checked nowhere, which
        // made it decorative — the exact pattern the petty-cash RBAC cleanup
        // removed. It is granted widely on purpose (a control that stops people
        // recording what they spent produces missing data, not compliance), so
        // enforcing it costs nobody their access and makes the grant mean
        // something.
        $this->authorize('create', CostLine::class);

        try {
            $line = $this->collector->collect($request->toContext());
        } catch (CostValidationException $e) {
            // Returned in Laravel's own 422 shape so field-level errors reach the
            // form. The July Finance audit found the petty-cash form degrading
            // genuine 422s into a generic banner with nothing highlighted; there
            // is no reason to repeat that here.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return response()->json([
            'message' => 'Cost recorded and sent for verification.',
            'data' => new CostLineResource($line->load(['expenseCode', 'submittedBy'])),
        ], 201);
    }

    /** What the current user has reported, so they can see where it got to. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|max:32',
            'job_number' => 'nullable|string|max:64',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $mine = fn () => CostLine::where('submitted_by_user_id', $request->user()->id)
            ->where('nature', '!=', CostLine::NATURE_PLANNED);

        $lines = $mine()
            ->withReferenceNames()
            ->with(['expenseCode', 'submittedBy', 'verifiedBy'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['job_number'] ?? null, fn ($q, $job) => $q->where('job_number', $job))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => CostLineResource::collection($lines->items()),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
                // Counted server-side over everything this person has reported.
                // The screen summed the rows it happened to be holding, which was
                // right only while nothing was paginated — and the moment it was,
                // "reported by you" would have meant "on this page".
                'summary' => $this->mySummary($mine()),
            ],
        ]);
    }

    /**
     * The four figures a reporter asks of their own queue.
     *
     * @return array<string, mixed>
     */
    private function mySummary($query): array
    {
        $rows = (clone $query)
            ->selectRaw('status, COUNT(*) AS line_count, COALESCE(SUM(net_amount), 0) AS value')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $of = fn (string $status) => [
            'count' => (int) ($rows[$status]->line_count ?? 0),
            'value' => number_format((float) ($rows[$status]->value ?? 0), 2, '.', ''),
        ];

        return [
            'total' => [
                'count' => (int) $rows->sum('line_count'),
                'value' => number_format((float) $rows->sum('value'), 2, '.', ''),
            ],
            'submitted' => $of(CostLine::STATUS_SUBMITTED),
            'queried' => $of(CostLine::STATUS_QUERIED),
            'verified' => $of(CostLine::STATUS_VERIFIED),
            'rejected' => $of(CostLine::STATUS_REJECTED),
        ];
    }

    public function correct(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('resubmit', $cost);
        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0|max:99999999.99',
            'description' => 'nullable|string|max:255',
            'response' => 'required|string|min:3|max:500',
        ]);
        try {
            $line = $this->costs->correctQueried($cost->load('expenseCode'), $request->user(), [
                'amount' => (string) $validated['amount'],
                'description' => $validated['description'] ?? null,
                'response' => $validated['response'],
            ]);
        } catch (CostValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }
        return response()->json([
            'message' => 'Correction recorded and returned to Finance.',
            'data' => new CostLineResource($line->load(['expenseCode', 'submittedBy'])),
        ]);
    }

    /**
     * The projects this person is actually working on.
     *
     * The capture screen previously asked for an enquiry id in a number field —
     * a site technician does not know their job is enquiry 487. This returns the
     * handful they are assigned to, newest first, so choosing a project is one
     * tap rather than a lookup.
     *
     * `q` searches the wider list for the cases where someone is reporting a
     * cost against a job they are not assigned to, which is common enough
     * (a driver, a store keeper) that it cannot be a dead end.
     */
    public function myProjects(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => 'nullable|string|max:120']);
        $userId = $request->user()->id;
        $search = $validated['q'] ?? null;
        $portfolioAccess = $request->user()->can(Permissions::FINANCE_COSTS_READ)
            || $request->user()->can(Permissions::FINANCE_COSTS_CREATE);

        $enquiries = ProjectEnquiry::query()
            ->whereNotIn('status', EnquiryConstants::getClosedStatuses())
            ->whereNotNull('job_number')
            ->when($search, fn ($query, $term) => $query->where(function ($q) use ($term) {
                $q->where('job_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%");
            }))
            // Without a search term, narrow to what this person is on. Both the
            // pivot and the legacy column are checked, because task assignment
            // still writes the older field in places.
            ->when(! $portfolioAccess, fn ($query) => $query->where(function ($assigned) use ($userId) {
                $assigned->where('project_officer_id', $userId)
                    ->orWhere('assigned_po', $userId)
                    ->orWhereJsonContains('assigned_users', $userId)
                    ->orWhereHas('enquiryTasks', fn ($task) => $task->where(fn ($q) => $q
                        ->whereHas('assignedUsers', fn ($u) => $u->where('users.id', $userId))
                        ->orWhere('assigned_user_id', $userId)
                        ->orWhere('assigned_to', $userId)));
            }))
            ->latest('id')
            ->limit($search ? 25 : 10)
            ->get(['id', 'job_number', 'title', 'venue', 'status']);

        return response()->json([
            'data' => $enquiries->map(fn (ProjectEnquiry $enquiry) => [
                'enquiry_id' => $enquiry->id,
                'job_number' => $enquiry->job_number,
                'title' => $enquiry->title,
                'venue' => $enquiry->venue,
                'status' => $enquiry->status,
            ]),
            'meta' => ['scope' => $portfolioAccess && $search ? 'search' : 'assigned'],
        ]);
    }

    /**
     * The budget lines for a project, with what has already been drawn against
     * each — this is the list the capture screen renders as buttons, and the
     * remaining figure is what makes people want to open it before they spend.
     *
     * Gated on `create`, not `read`: this is the capture form's own lookup, so
     * anyone who may report a cost must be able to see the budget they are
     * spending against. It was previously ungated altogether, which made every
     * project's full budget — line by line, with remaining balances — readable
     * by any authenticated account from a guessable enquiry id.
     */
    public function budgetLines(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        if (! $request->user()->can(Permissions::FINANCE_COSTS_READ)) {
            $this->authorize('create', CostLine::class);
        }

        // Bound rather than taken as an int: `{enquiry}` resolves to a
        // ProjectEnquiry through implicit binding, which also means an unknown
        // project 404s here instead of quietly returning an empty budget.
        $planned = CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->counting()
            ->withSum(['consumers as drawn' => fn ($q) => $q->where('status', CostLine::STATUS_VERIFIED)], 'net_amount')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $planned->map(fn (CostLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'category' => $line->details['budget_category'] ?? null,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'budgeted' => $line->net_amount,
                'spent' => number_format((float) ($line->drawn ?? 0), 2, '.', ''),
                'remaining' => bcsub((string) $line->net_amount, (string) ($line->drawn ?: '0'), 2),
            ]),
        ]);
    }
}
