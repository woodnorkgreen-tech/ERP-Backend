<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OffboardingCase;
use App\Modules\HR\Services\OffboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OffboardingController extends Controller
{
    public function __construct(private OffboardingService $service) {}

    public function eligibleEmployees(): JsonResponse
    {
        return response()->json($this->service->getEligibleEmployees());
    }

    public function index(Request $request): JsonResponse
    {
        $cases = OffboardingCase::with(['employee.department', 'initiatedBy', 'department', 'cards'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $s) {
                $q->whereHas('employee', fn ($q2) =>
                    $q2->where('first_name', 'like', "%{$s}%")
                       ->orWhere('last_name', 'like', "%{$s}%")
                       ->orWhere('email', 'like', "%{$s}%")
                );
            })
            ->latest()
            ->get();

        return response()->json($cases);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'         => 'required|integer|exists:employees,id',
            'termination_type'    => ['nullable', Rule::in(Employee::TERMINATION_TYPES)],
            'termination_reason'  => 'nullable|string|max:1000',
            'last_working_day'    => 'nullable|date',
            'notes'               => 'nullable|string',
        ]);

        $case = $this->service->initiateOffboarding($validated);

        return response()->json($case, 201);
    }

    public function show(int $id): JsonResponse
    {
        $case = OffboardingCase::with([
            'employee.department',
            'initiatedBy',
            'department',
            'approvedBy',
            'cards.tasks',
            'assetReturns',
            'clearances',
            'exitInterview.conductedByUser',
            'finalSettlement.approvedByUser',
            'activityLogs.actor',
        ])->findOrFail($id);

        return response()->json($case);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);
        $case = $this->service->cancelOffboarding($id, $request->reason);

        return response()->json($case);
    }

    public function approveFinalGate(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string']);
        $result = $this->service->approveFinalGate($id, $request->notes);

        return response()->json($result);
    }

    public function activityLog(int $id): JsonResponse
    {
        $case = OffboardingCase::findOrFail($id);
        return response()->json($case->activityLogs()->with('actor')->get());
    }

    public function createTask(Request $request, int $cardId): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_optional' => 'nullable|boolean',
        ]);

        $task = $this->service->createTask($cardId, $validated);

        return response()->json($task);
    }

    public function completeTask(Request $request, int $taskId): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string']);
        $task = $this->service->completeTaskById($taskId, $request->notes);

        return response()->json($task);
    }

    public function reopenTask(int $taskId): JsonResponse
    {
        $task = $this->service->reopenTaskById($taskId);
        return response()->json($task);
    }

    public function toggleOptionalTask(Request $request, int $taskId): JsonResponse
    {
        $request->validate(['is_active' => 'required|boolean']);
        $task = $this->service->toggleOptionalTask($taskId, $request->boolean('is_active'));

        return response()->json($task);
    }

    public function updateTaskFlags(Request $request, int $taskId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
        ]);

        $task = $this->service->updateTaskFlags($taskId, $validated);

        return response()->json($task);
    }

    public function createAssetReturnItem(Request $request, int $caseId): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
        ]);

        $item = $this->service->createAssetReturnItem($caseId, $validated);

        return response()->json($item);
    }

    public function toggleAssetReturn(Request $request, int $itemId): JsonResponse
    {
        $request->validate(['condition' => 'nullable|in:good,damaged,lost']);
        $item = $this->service->toggleAssetReturn($itemId, $request->condition);

        return response()->json($item);
    }

    public function updateAssetReturn(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
            'notes'         => 'nullable|string',
        ]);

        $item = $this->service->updateAssetReturn($itemId, $validated);

        return response()->json($item);
    }

    public function createClearance(Request $request, int $caseId): JsonResponse
    {
        $validated = $request->validate([
            'label'          => 'required|string|max:255',
            'department_tag' => 'nullable|string|max:255',
        ]);

        $clearance = $this->service->createClearance($caseId, $validated);

        return response()->json($clearance);
    }

    public function updateClearanceStatus(Request $request, int $clearanceId): JsonResponse
    {
        $validated = $request->validate([
            'status'      => 'required|in:pending,cleared,flagged',
            'flag_reason' => 'required_if:status,flagged|nullable|string',
        ]);

        $clearance = $this->service->updateClearanceStatus(
            $clearanceId,
            $validated['status'],
            $validated['flag_reason'] ?? null
        );

        return response()->json($clearance);
    }

    public function updateClearanceFlags(Request $request, int $clearanceId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
        ]);

        $clearance = $this->service->updateClearanceFlags($clearanceId, $validated);

        return response()->json($clearance);
    }

    public function recordExitInterview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'conducted_at'       => 'nullable|date',
            'reason_for_leaving' => 'nullable|string|max:255',
            'feedback'           => 'nullable|string',
            'would_recommend'    => 'nullable|boolean',
            'rating'             => 'nullable|integer|min:1|max:5',
            'declined'           => 'nullable|boolean',
            'declined_reason'    => 'nullable|string',
        ]);

        $interview = $this->service->recordExitInterview($id, $validated);

        return response()->json($interview);
    }

    public function updateFinalSettlement(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'accrued_leave_days'  => 'nullable|numeric|min:0',
            'leave_payout_amount' => 'nullable|numeric|min:0',
            'outstanding_salary'  => 'nullable|numeric|min:0',
            'other_dues'          => 'nullable|numeric|min:0',
            'deductions'          => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string',
        ]);

        $settlement = $this->service->updateFinalSettlement($id, $validated);

        return response()->json($settlement);
    }

    public function approveFinalSettlement(int $id): JsonResponse
    {
        $settlement = $this->service->approveFinalSettlement($id);
        return response()->json($settlement);
    }

    public function markSettlementPaid(int $id): JsonResponse
    {
        $settlement = $this->service->markSettlementPaid($id);
        return response()->json($settlement);
    }
}
