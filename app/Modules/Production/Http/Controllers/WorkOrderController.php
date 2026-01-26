<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WorkOrderController extends Controller
{
    protected ProductionService $productionService;

    public function __construct(ProductionService $productionService)
    {
        $this->productionService = $productionService;
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
        ]);

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
            $results = $this->productionService->createWorkOrdersForExistingProjects();

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
}
