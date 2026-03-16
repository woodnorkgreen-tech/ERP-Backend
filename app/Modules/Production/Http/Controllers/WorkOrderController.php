<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderTask;
use App\Modules\Production\Models\WorkOrderFinalQcCheck;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WorkOrderController extends Controller
{
    protected \App\Modules\Production\Services\ProductionTaskAlignmentService $alignmentService;

    public function __construct(\App\Modules\Production\Services\ProductionTaskAlignmentService $alignmentService)
    {
        $this->alignmentService = $alignmentService;
    }
    /**
     * Get all work orders with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by project enquiry
        if ($request->has('project_enquiry_id')) {
            $query->where('project_enquiry_id', $request->project_enquiry_id);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by assigned user
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Search by work order number or title
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('work_order_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $workOrders = $query->with(['projectEnquiry.projectOfficer', 'projectEnquiry.client', 'projectEnquiry', 'project', 'enquiryTask', 'assignedTo', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        // Append status_category and project_officer_name to each work order for frontend
        $workOrders->getCollection()->transform(function ($workOrder) {
            $workOrder->status_category = $workOrder->getStatusCategory();
            
            // Simple: Add project officer name directly
            $workOrder->project_officer_name = $workOrder->projectEnquiry?->projectOfficer?->name;
            
            return $workOrder;
        });

        return response()->json([
            'success' => true,
            'data' => $workOrders
        ]);
    }

    /**
     * Create a new work order
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_order_number' => 'required|string|unique:work_orders',
            'enquiry_task_id' => 'nullable|exists:enquiry_tasks,id',
            'project_enquiry_id' => 'nullable|exists:project_enquiries,id',
            'project_id' => 'nullable|exists:projects,id',
            'title' => 'required|string',
            'specifications' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'status' => 'required|in:pending,in_progress,completed,on_hold,cancelled',
            'priority' => 'required|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $validated['created_by'] = auth()->id();

        $workOrder = WorkOrder::create($validated);

        // Load relationships and append status_category and project_officer_name for frontend
        $workOrder->load(['projectEnquiry.projectOfficer', 'projectEnquiry.client', 'projectEnquiry', 'project', 'enquiryTask', 'assignedTo', 'createdBy']);
        $workOrder->status_category = $workOrder->getStatusCategory();
        $workOrder->project_officer_name = $workOrder->projectEnquiry?->projectOfficer?->name;

        return response()->json([
            'success' => true,
            'message' => 'Work order created successfully',
            'data' => $workOrder
        ], 201);
    }

    /**
     * Get a specific work order
     */
    public function show($id): JsonResponse
    {
        $workOrder = WorkOrder::with(['projectEnquiry.projectOfficer', 'projectEnquiry.client', 'projectEnquiry', 'project', 'enquiryTask', 'assignedTo', 'createdBy'])
            ->findOrFail($id);

        // Append status_category and project_officer_name for frontend
        $workOrder->status_category = $workOrder->getStatusCategory();
        $workOrder->project_officer_name = $workOrder->projectEnquiry?->projectOfficer?->name;

        return response()->json([
            'success' => true,
            'data' => $workOrder
        ]);
    }

    /**
     * Update a work order
     */
    public function update(Request $request, $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string',
            'specifications' => 'sometimes|nullable|string',
            'quantity' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:pending,in_progress,completed,on_hold,cancelled',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'due_date' => 'sometimes|nullable|date',
            'started_at' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'completed_at' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
            'workflow_completed_steps' => 'sometimes|array',
            'workflow_completed_steps.*' => 'string'
        ]);

        if (array_key_exists('workflow_completed_steps', $validated)) {
            $validationError = $this->validateWorkflowProgression(
                $workOrder,
                $validated['workflow_completed_steps'] ?? []
            );

            if ($validationError) {
                return response()->json([
                    'success' => false,
                    'message' => $validationError
                ], 422);
            }
        }

        $workOrder->update($validated);

        // Reload relationships and append status_category and project_officer_name for frontend
        $workOrder->load(['projectEnquiry.projectOfficer', 'projectEnquiry.client', 'projectEnquiry', 'project', 'enquiryTask', 'assignedTo', 'createdBy']);
        $workOrder->status_category = $workOrder->getStatusCategory();
        $workOrder->project_officer_name = $workOrder->projectEnquiry?->projectOfficer?->name;

        return response()->json([
            'success' => true,
            'message' => 'Work order updated successfully',
            'data' => $workOrder
        ]);
    }

    /**
     * Delete a work order
     */
    public function destroy($id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);
        $workOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Work order deleted successfully'
        ]);
    }

    /**
     * Get work orders for a specific project enquiry
     */
    public function getByEnquiry($enquiry_id): JsonResponse
    {
        $workOrders = WorkOrder::where('project_enquiry_id', $enquiry_id)
            ->with(['projectEnquiry.projectOfficer', 'projectEnquiry.client', 'projectEnquiry', 'project', 'enquiryTask', 'assignedTo', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Append status_category and project_officer_name to each work order for frontend
        $workOrders->transform(function ($workOrder) {
            $workOrder->status_category = $workOrder->getStatusCategory();
            $workOrder->project_officer_name = $workOrder->projectEnquiry?->projectOfficer?->name;
            return $workOrder;
        });

        return response()->json([
            'success' => true,
            'data' => $workOrders
        ]);
    }

    /**
     * Create work orders for existing projects that don't have one
     */
    public function createForExistingProjects(): JsonResponse
    {
        try {
            $results = $this->alignmentService->createWorkOrdersForExistingProjects();

            return response()->json([
                'success' => true,
                'message' => 'Work order creation process completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create work orders for existing projects',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function validateWorkflowProgression(WorkOrder $workOrder, array $nextSteps): ?string
    {
        $orderedSteps = ['intake', 'design_assets', 'materials', 'fabrication', 'qc', 'packaging', 'close'];
        $nextFiltered = array_values(array_intersect($orderedSteps, array_unique($nextSteps)));
        $currentFiltered = array_values(array_intersect($orderedSteps, array_unique($workOrder->workflow_completed_steps ?? [])));

        // No step can be uncompleted once marked complete.
        foreach ($currentFiltered as $step) {
            if (!in_array($step, $nextFiltered, true)) {
                return 'Completed steps cannot be removed.';
            }
        }

        // Must be sequential with no skipping.
        for ($i = 0; $i < count($nextFiltered); $i++) {
            if ($nextFiltered[$i] !== $orderedSteps[$i]) {
                return 'Workflow must be completed in sequence from Intake to Close.';
            }
        }

        // Validate any newly added step gates.
        $newSteps = array_values(array_diff($nextFiltered, $currentFiltered));
        foreach ($newSteps as $step) {
            $gateError = $this->validateStepGate($workOrder, $step);
            if ($gateError) {
                return $gateError;
            }
        }

        return null;
    }

    private function validateStepGate(WorkOrder $workOrder, string $step): ?string
    {
        if ($step === 'intake') {
            $taskCount = WorkOrderTask::where('work_order_id', $workOrder->id)->count();
            if ($taskCount === 0) {
                return 'Cannot complete Intake. Add at least one workstation task.';
            }
            return null;
        }

        if ($step === 'fabrication') {
            $tasks = WorkOrderTask::where('work_order_id', $workOrder->id)
                ->where('included', true)
                ->get(['status', 'safety_checks']);

            if ($tasks->isEmpty()) {
                return 'Cannot complete Fabrication. No included tasks found.';
            }

            $incomplete = $tasks->contains(fn ($task) => $task->status !== 'completed');
            if ($incomplete) {
                return 'Cannot complete Fabrication. All included tasks must be completed.';
            }

            $missingSafety = $tasks->contains(function ($task) {
                $checks = $task->safety_checks ?? [];
                return !($checks['ppe'] ?? false) || !($checks['machine'] ?? false);
            });
            if ($missingSafety) {
                return 'Cannot complete Fabrication. Safety checks (PPE and machine) are required for all tasks.';
            }
            return null;
        }

        if ($step === 'qc') {
            $checks = WorkOrderFinalQcCheck::where('work_order_id', $workOrder->id)->get(['status']);
            if ($checks->isEmpty()) {
                return 'Cannot complete QC. Final QC checklist has not been filled.';
            }
            $hasPending = $checks->contains(fn ($check) => $check->status === 'pending');
            if ($hasPending) {
                return 'Cannot complete QC. Resolve all final QC checkpoints.';
            }
            return null;
        }

        if ($step === 'packaging') {
            $qcDone = in_array('qc', $workOrder->workflow_completed_steps ?? [], true);
            if (!$qcDone) {
                return 'Cannot complete Packaging before QC is complete.';
            }
            return null;
        }

        if ($step === 'close') {
            $packagingDone = in_array('packaging', $workOrder->workflow_completed_steps ?? [], true);
            if (!$packagingDone) {
                return 'Cannot complete Close before Packaging is complete.';
            }
            return null;
        }

        // design_assets, materials: sequence-enforced; additional gate can be added later.
        return null;
    }
}
