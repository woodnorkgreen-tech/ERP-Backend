<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\LeaveHandover;
use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveHandoverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveHandover::with([
            'leaveRequest',
            'employee',
            'handedOverTo',
            'updatedBy',
        ]);

        if ($request->filled('status')) {
            $query->whereHas('leaveRequest', function ($leaveRequestQuery) use ($request) {
                $leaveRequestQuery->where('status', $request->status);
            });
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $handovers = $query->orderBy('updated_at', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $handovers->items(),
            'meta' => [
                'current_page' => $handovers->currentPage(),
                'last_page' => $handovers->lastPage(),
                'per_page' => $handovers->perPage(),
                'total' => $handovers->total(),
            ],
        ]);
    }

    public function show(int $leaveRequestId): JsonResponse
    {
        try {
            $handovers = LeaveHandover::with([
                'employee',
                'handedOverTo',
                'updatedBy',
            ])
                ->where('leave_request_id', $leaveRequestId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $handovers,
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching handover: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_request_id' => 'required|exists:leave_requests,id',
            'project_name' => 'nullable|string|max:255',
            'task_description' => 'nullable|string',
            'current_status' => 'nullable|string|max:255',
            'pending_actions' => 'nullable|string',
            'handed_over_to_employee_id' => 'nullable|exists:employees,id',
            'department' => 'nullable|string|max:255',
            'follow_up_deadline' => 'nullable|date',
            'update_during_leave' => 'nullable|string',
        ]);

        $leaveRequest = LeaveRequest::findOrFail($validated['leave_request_id']);

        $handover = LeaveHandover::create([
            'leave_request_id' => $validated['leave_request_id'],
            'employee_id' => $leaveRequest->employee_id,
            'project_name' => $validated['project_name'] ?? null,
            'task_description' => $validated['task_description'] ?? null,
            'current_status' => $validated['current_status'] ?? null,
            'pending_actions' => $validated['pending_actions'] ?? null,
            'handed_over_to_employee_id' => $validated['handed_over_to_employee_id'] ?? null,
            'department' => $validated['department'] ?? null,
            'follow_up_deadline' => $validated['follow_up_deadline'] ?? null,
            'update_during_leave' => $validated['update_during_leave'] ?? null,
            'updated_by' => Auth::id(),
            'updated_at' => now(),
        ]);

        $handover->load(['employee', 'handedOverTo', 'updatedBy']);

        return response()->json([
            'success' => true,
            'data' => $handover,
            'message' => 'Leave handover created successfully.',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'project_name' => 'nullable|string|max:255',
            'task_description' => 'nullable|string',
            'current_status' => 'nullable|string|max:255',
            'pending_actions' => 'nullable|string',
            'handed_over_to_employee_id' => 'nullable|exists:employees,id',
            'department' => 'nullable|string|max:255',
            'follow_up_deadline' => 'nullable|date',
            'update_during_leave' => 'nullable|string',
        ]);

        $handover = LeaveHandover::findOrFail($id);

        $handover->update(array_merge($validated, [
            'updated_by' => Auth::id(),
            'updated_at' => now(),
        ]));

        $handover->load(['employee', 'handedOverTo', 'updatedBy']);

        return response()->json([
            'success' => true,
            'data' => $handover,
            'message' => 'Leave handover updated successfully.',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $handover = LeaveHandover::findOrFail($id);
        $handover->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave handover deleted successfully.',
        ]);
    }
}
