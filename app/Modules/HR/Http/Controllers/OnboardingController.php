<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\OnboardingCase;
use App\Modules\HR\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $service) {}

    public function hiredCandidates(): JsonResponse
    {
        return response()->json($this->service->getHiredCandidates());
    }

    public function index(Request $request): JsonResponse
    {
        $cases = OnboardingCase::with([
                'candidate.jobPosting',
                'employee',
                'department',
                'hrOwner',
                'departmentLead',
                'cards',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $s) {
                $q->whereHas('candidate', fn ($q2) =>
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
            'candidate_id'    => 'required|integer',
            'job_posting_id'  => 'nullable|integer',
            'start_date'      => 'nullable|date',
            'hr_owner_id'     => 'nullable|integer',
            'department_lead_id' => 'nullable|integer',
            'employment_type' => 'nullable|in:full-time,part-time,contract,intern',
            'department_id'   => 'nullable|integer',
            'position'        => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
        ]);

        $case = $this->service->startOnboarding($validated);

        return response()->json($case, 201);
    }

    public function show(int $id): JsonResponse
    {
        $case = OnboardingCase::with([
            'candidate.jobPosting',
            'employee',
            'department',
            'hrOwner',
            'departmentLead',
            'approvedBy',
            'cards.tasks',
            'documentRequirements',
            'welcomeKitItems',
            'handover.handedOverByUser',
            'handover.departmentLead',
            'reviews.conductedByUser',
            'activityLogs.actor',
        ])->findOrFail($id);

        return response()->json($case);
    }

    public function linkEmployee(Request $request, int $id): JsonResponse
    {
        $request->validate(['employee_id' => 'required|integer']);
        $case = $this->service->linkEmployee($id, $request->employee_id);

        return response()->json($case);
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

    public function updateTaskFlags(Request $request, int $taskId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
        ]);

        $task = $this->service->updateTaskFlags($taskId, $validated);

        return response()->json($task);
    }

    public function updateDocumentRequirement(Request $request, int $requirementId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
        ]);

        $req = $this->service->updateDocumentRequirementFlags($requirementId, $validated);

        return response()->json($req);
    }

    public function createDocumentRequirement(Request $request, int $caseId): JsonResponse
    {
        $validated = $request->validate([
            'label'       => 'required|string|max:255',
            'is_required' => 'nullable|boolean',
        ]);

        $req = $this->service->createDocumentRequirement($caseId, $validated);

        return response()->json($req);
    }

    public function createWelcomeKitItem(Request $request, int $caseId): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
        ]);

        $item = $this->service->createWelcomeKitItem($caseId, $validated);

        return response()->json($item);
    }

    public function approveHRGate(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string']);
        $case = $this->service->approveHRGate($id, $request->notes);

        return response()->json($case);
    }

    public function recordHandover(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'handover_notes' => 'nullable|string',
            'handover_date'  => 'nullable|date',
            'department_lead_id'=> 'nullable|integer',
        ]);

        $handover = $this->service->recordHandover($id, $validated);

        return response()->json($handover);
    }

    public function submitReview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'review_type'        => 'required|in:two_week,one_month',
            'scheduled_date'     => 'nullable|date',
            'conducted_date'     => 'nullable|date',
            'performance_rating' => 'nullable|integer|min:1|max:5',
            'feedback'           => 'nullable|string',
            'improvement_notes'  => 'nullable|string',
            'employee_feedback'  => 'nullable|string',
        ]);

        $review = $this->service->submitReview($id, $validated);

        return response()->json($review);
    }

    public function updateDocumentStatus(Request $request, int $requirementId): JsonResponse
    {
        $validated = $request->validate([
            'status'           => 'required|in:submitted,verified,rejected',
            'rejection_reason' => 'nullable|string',
        ]);

        $req = $this->service->updateDocumentStatus(
            $requirementId,
            $validated['status'],
            $validated['rejection_reason'] ?? null
        );

        return response()->json($req);
    }

    public function toggleWelcomeKitItem(int $itemId): JsonResponse
    {
        $item = $this->service->toggleWelcomeKitItem($itemId);
        return response()->json($item);
    }

    public function updateWelcomeKitItem(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'is_applicable' => 'nullable|boolean',
            'is_needed'     => 'nullable|boolean',
        ]);

        $item = $this->service->updateWelcomeKitItem($itemId, $validated);

        return response()->json($item);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);
        $case = $this->service->cancelOnboarding($id, $request->reason);

        return response()->json($case);
    }

    public function activityLog(int $id): JsonResponse
    {
        $case = OnboardingCase::findOrFail($id);
        return response()->json($case->activityLogs()->with('actor')->get());
    }
}
