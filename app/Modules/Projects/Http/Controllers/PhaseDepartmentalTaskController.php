<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\UpdateTaskStatusAction;
use App\Modules\Projects\Http\Controllers\Concerns\HandlesProjectErrors;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;

class PhaseDepartmentalTaskController extends Controller
{
    use HandlesProjectErrors;

    public function __construct(
        protected \App\Modules\Projects\Services\NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->safe(function () use ($request) {
            $query = EnquiryTask::withTaskData()->with('enquiry', 'department', 'assignedUser');

            // Filter by enquiry if provided
            if ($request->has('enquiry_id')) {
                $query->where('project_enquiry_id', $request->enquiry_id);
            }

            // Filter by department if provided — include owned + collaborator tasks.
            if ($request->has('department_id')) {
                $query->forDepartmentPool($request->department_id);
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
        }, 'List departmental tasks');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->safe(function () use ($request) {
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

            // Send notification if assigned — non-fatal: a notification failure
            // must not roll back a successfully created task.
            if ($task->assigned_user_id) {
                $assignedUser = \App\Models\User::find($task->assigned_user_id);
                if ($assignedUser) {
                    $this->quietly(
                        fn () => $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, Auth::user()),
                        'Notify task assignment'
                    );
                }
            }

            return response()->json([
                'message' => 'Departmental task created successfully',
                'data' => $task->load('enquiry', 'department', 'assignedUser'),
            ], 201);
        }, 'Create departmental task');
    }

    public function show(EnquiryTask $task): JsonResponse
    {
        return $this->safe(fn () => response()->json([
            'data' => $task->load('enquiry', 'department', 'assignedUser'),
            'message' => 'Departmental task retrieved successfully'
        ]), 'Show departmental task');
    }

    public function update(Request $request, EnquiryTask $task): JsonResponse
    {
        return $this->safe(function () use ($request, $task) {
            // Status is intentionally NOT editable here. All status transitions go
            // through the dedicated status endpoint (UpdateTaskStatusAction), which
            // is the single source of truth for task state.
            $validator = Validator::make($request->all(), [
                'task_name' => 'sometimes|required|string|max:255',
                'task_description' => 'nullable|string',
                'priority' => 'sometimes|required|string|in:low,medium,high,urgent',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'due_date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldAssignee = $task->assigned_user_id;

            $updateData = $request->only([
                'task_name',
                'task_description',
                'priority',
                'assigned_to',
                'due_date',
            ]);

            // Map assigned_to to assigned_user_id for consistency
            if (isset($updateData['assigned_to'])) {
                $updateData['assigned_user_id'] = $updateData['assigned_to'];
            }

            $task->update($updateData);

            // Notify on Reassignment — non-fatal.
            if (isset($updateData['assigned_user_id']) && $updateData['assigned_user_id'] != $oldAssignee) {
                $assignedUser = \App\Models\User::find($updateData['assigned_user_id']);
                if ($assignedUser) {
                    $this->quietly(
                        fn () => $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, Auth::user(), true),
                        'Notify task reassignment'
                    );
                }
            }

            return response()->json([
                'message' => 'Departmental task updated successfully',
                'data' => $task->load('enquiry', 'department', 'assignedUser')
            ]);
        }, 'Update departmental task');
    }

    public function destroy(EnquiryTask $task): JsonResponse
    {
        return $this->safe(function () use ($task) {
            $task->delete();

            return response()->json([
                'message' => 'Departmental task deleted successfully'
            ]);
        }, 'Delete departmental task');
    }

    public function performAction(Request $request, EnquiryTask $task, UpdateTaskStatusAction $statusAction): JsonResponse
    {
        return $this->safe(function () use ($request, $task, $statusAction) {
            $validator = Validator::make($request->all(), [
                'action' => 'required|string|in:complete,cancel,reassign',
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
            $user = Auth::user();

            // Status changes are delegated to the single source of truth
            // (UpdateTaskStatusAction -> EnquiryWorkflowService), so gates,
            // enquiry sync and notifications are applied consistently.
            if (in_array($action, ['complete', 'cancel'], true)) {
                $statusAction->execute($task->id, $action === 'complete' ? 'completed' : 'cancelled', $request->notes);
            } elseif ($action === 'reassign' && $request->filled('assigned_to')) {
                $task->update([
                    'assigned_to' => $request->assigned_to,
                    'assigned_user_id' => $request->assigned_to,
                ]);

                $assignedUser = \App\Models\User::find($request->assigned_to);
                if ($assignedUser) {
                    $this->quietly(
                        fn () => $this->notificationService->sendEnquiryTaskAssignment($task, $assignedUser, $user, true),
                        'Notify task reassignment'
                    );
                }
            }

            return response()->json([
                'message' => "Task {$action} action performed successfully",
                'data' => $task->fresh()->load('enquiry', 'department', 'assignedUser')
            ]);
        }, 'Perform departmental task action');
    }

    public function getStats(Request $request): JsonResponse
    {
        return $this->safe(function () use ($request) {
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
        }, 'Get departmental task stats');
    }
}
