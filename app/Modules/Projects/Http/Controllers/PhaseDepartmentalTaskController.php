<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;

class PhaseDepartmentalTaskController extends Controller
{
    public function __construct(
        protected \App\Modules\Projects\Services\NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        // ... (existing implementation)
        $query = EnquiryTask::with('enquiry', 'department', 'assignedUser');

        // Filter by enquiry if provided
        if ($request->has('enquiry_id')) {
            $query->where('project_enquiry_id', $request->enquiry_id);
        }

        // Filter by department if provided
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $tasks = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'data' => $tasks,
            'message' => 'Departmental tasks retrieved successfully'
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_enquiry_id' => 'required|integer|exists:project_enquiries,id',
            'department_id' => 'required|integer|exists:departments,id',
            'task_name' => 'required|string|max:255',
            'task_description' => 'nullable|string',
            'priority' => 'required|string|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $task = EnquiryTask::create([
            'project_enquiry_id' => $request->project_enquiry_id,
            'department_id' => $request->department_id,
            'task_name' => $request->task_name,
            'task_description' => $request->task_description,
            'priority' => $request->priority,
            'assigned_to' => $request->assigned_to,
            // Ensure compat with assigned_user_id
            'assigned_user_id' => $request->assigned_to,
            'due_date' => $request->due_date,
            'status' => 'pending',
            'created_by' => Auth::id(),
        ]);

        // Send notification if assigned
        if ($task->assigned_user_id) {
            $assignedUser = \App\Models\User::find($task->assigned_user_id);
            if ($assignedUser) {
                $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, Auth::user());
            }
        }

        return response()->json([
            'message' => 'Departmental task created successfully',
            'data' => $task->load('enquiry', 'department', 'assignedUser'),
        ], 201);
    }

    public function show(EnquiryTask $task): JsonResponse
    {
        return response()->json([
            'data' => $task->load('enquiry', 'department', 'assignedUser'),
            'message' => 'Departmental task retrieved successfully'
        ]);
    }

    public function update(Request $request, EnquiryTask $task): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_name' => 'sometimes|required|string|max:255',
            'task_description' => 'nullable|string',
            'priority' => 'sometimes|required|string|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
            'status' => 'sometimes|required|string|in:pending,in_progress,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $oldStatus = $task->status;
        $oldAssignee = $task->assigned_user_id;

        $updateData = $request->only([
            'task_name',
            'task_description',
            'priority',
            'assigned_to',
            'due_date',
            'status',
        ]);

        // Map assigned_to to assigned_user_id for consistency
        if (isset($updateData['assigned_to'])) {
            $updateData['assigned_user_id'] = $updateData['assigned_to'];
        }

        $task->update($updateData);

        // Notify on Reassignment
        if (isset($updateData['assigned_user_id']) && $updateData['assigned_user_id'] != $oldAssignee) {
            $assignedUser = \App\Models\User::find($updateData['assigned_user_id']);
            if ($assignedUser) {
                 $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, Auth::user(), true);
            }
        }

        // Notify on Completion
        if ($task->status === 'completed' && $oldStatus !== 'completed') {
             $this->notificationService->sendEnquiryTaskCompleted($task, Auth::user());
        }

        return response()->json([
            'message' => 'Departmental task updated successfully',
            'data' => $task->load('enquiry', 'department', 'assignedUser')
        ]);
    }

    public function destroy(EnquiryTask $task): JsonResponse
    {
        $task->delete();

        return response()->json([
            'message' => 'Departmental task deleted successfully'
        ]);
    }

    public function performAction(Request $request, EnquiryTask $task): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:start,complete,cancel,reassign',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $action = $request->action;
        $updates = [];
        $user = Auth::user();

        switch ($action) {
            case 'start':
                $updates['status'] = 'in_progress';
                break;
            case 'complete':
                $updates['status'] = 'completed';
                $updates['completed_at'] = now();
                break;
            case 'cancel':
                $updates['status'] = 'cancelled';
                break;
            case 'reassign':
                if ($request->has('assigned_to')) {
                    $updates['assigned_to'] = $request->assigned_to;
                    $updates['assigned_user_id'] = $request->assigned_to;
                }
                break;
        }

        if (!empty($updates)) {
            $task->update($updates);

            if ($action === 'complete') {
                $this->notificationService->sendEnquiryTaskCompleted($task, $user);
            } elseif ($action === 'reassign' && isset($updates['assigned_user_id'])) {
                $assignedUser = \App\Models\User::find($updates['assigned_user_id']);
                if ($assignedUser) {
                    $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, $user, true);
                }
            }
        }

        return response()->json([
            'message' => "Task {$action} action performed successfully",
            'data' => $task->load('enquiry', 'department', 'assignedUser')
        ]);
    }

    public function getStats(Request $request): JsonResponse
    {
        $query = EnquiryTask::query();

        if ($request->has('enquiry_id')) {
            $query->where('project_enquiry_id', $request->enquiry_id);
        }

        $stats = [
            'total' => $query->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'data' => $stats,
            'message' => 'Departmental tasks stats retrieved successfully'
        ]);
    }
}
