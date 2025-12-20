<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Services\TaskService;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use App\Modules\UniversalTask\Repositories\TaskRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskController
{
    protected TaskService $taskService;
    protected TaskPermissionService $permissionService;
    protected TaskRepository $taskRepository;

    public function __construct(
        TaskService $taskService,
        TaskPermissionService $permissionService,
        TaskRepository $taskRepository,
        protected \App\Modules\Projects\Services\NotificationService $notificationService
    ) {
        $this->taskService = $taskService;
        $this->permissionService = $permissionService;
        $this->taskRepository = $taskRepository;
        $this->notificationService = $notificationService;
    }

    // ... (existing code)

    /**
     * Update task status.
     */
    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        // ... (existing validation and permission checks)

        try {
            $oldStatus = $task->status;
            
            $task = $this->taskService->updateStatus(
                $task,
                $request->status,
                $user->id,
                $request->notes
            );

            // Send completion notification if task wasn't completed before
            if ($request->status === 'completed' && $oldStatus !== 'completed') {
                $this->notificationService->sendUniversalTaskCompleted($task, $user);
            }

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully.',
                'data' => $task,
            ]);

        } catch (\InvalidArgumentException $e) {
            // ... (existing catch)
        } catch (\Exception $e) {
            // ... (existing catch)
        }
    }

    /**
     * Assign task to users.
     */
    public function assign(Request $request, Task $task): JsonResponse
    {
        // ... (existing validation)

        // Check assignment permissions for all users
        $assignees = User::whereIn('id', $request->user_ids)->get();
        // ... (existing permission checking loop)

        try {
            $assignmentData = [
                'user_ids' => $request->user_ids,
                'role' => $request->role,
                'replace_existing' => $request->get('replace_existing', false),
            ];

            $task = $this->taskService->assignTask($task, $assignmentData, $user->id);

            // Send notifications to assignees
            foreach ($assignees as $assignee) {
                // If the current user assigned themselves, maybe don't notify? 
                // Or standard behavior is to notify anyway to confirm assignment.
                $this->notificationService->sendUniversalTaskAssignment($task, $assignee, $user);
            }

            return response()->json([
                'success' => true,
                'message' => 'Task assigned successfully.',
                'data' => $task->load('assignments.user'),
            ]);

        } catch (\Exception $e) {
            // ... (existing catch)
        }
    }

    /**
     * Display a listing of tasks with filtering, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // TODO: Temporarily bypass permission check for development
        // Check basic read permission
        // if (!$this->permissionService->canView($user)) {
        //     return response()->json([
        //         'success' => false,
        //         'error' => [
        //             'code' => 'INSUFFICIENT_PERMISSIONS',
        //         'message' => 'You do not have permission to view tasks.',
        //         ]
        //     ], 403);
        // }

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled', 'overdue'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'task_type' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'created_by' => 'nullable|exists:users,id',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
            'sort_by' => ['nullable', Rule::in(['created_at', 'updated_at', 'due_date', 'priority', 'status', 'title', 'estimated_hours', 'actual_hours', 'completion_percentage'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'tags' => 'nullable|array',
            'overdue' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            // Get parameters for repository
            $params = $request->all();

            // Use repository for advanced search and filtering
            $tasks = $this->taskRepository->getTasks($params, $user);

            // TODO: Temporarily bypass permission filtering for development
            // Apply permission filters to the results
            // $filteredTasks = collect($tasks->items())->filter(function ($task) use ($user) {
            //     return $this->permissionService->canView($user, $task);
            // });

            // Create new paginator with filtered results
            // $filteredPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
            //     $filteredTasks,
            //     $tasks->total(),
            //     $tasks->perPage(),
            //     $tasks->currentPage(),
            //     ['path' => $request->url(), 'pageName' => 'page']
            // );

            return response()->json([
                'success' => true,
                'data' => $tasks,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving tasks.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created task.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'task_type' => 'required|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'parent_task_id' => 'nullable|exists:tasks,id',
            'taskable_type' => 'nullable|string|max:255',
            'taskable_id' => 'nullable|integer',
            'department_id' => 'required|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'required|date|after_or_equal:today',
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
            'context' => 'nullable|array', // For type-specific data
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid task data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // TODO: Temporarily bypass permission check for development
        // Check create permission
        // if (!$this->permissionService->canCreate($user, $request->all())) {
        //     $this->permissionService->logPermissionDenial($user, 'create_task', null, $request->all());
        //     return response()->json([
        //         'success' => false,
        //         'error' => [
        //             'code' => 'INSUFFICIENT_PERMISSIONS',
        //             'message' => 'You do not have permission to create tasks.',
        //         ]
        //     ], 403);
        // }

        try {
            Log::info('Task creation attempt', [
                'user_id' => $user->id,
                'request_data' => $request->all(),
            ]);

            $task = $this->taskService->createTask($request->all(), $user);

            Log::info('Task created successfully', [
                'task_id' => $task->id,
                'task_data' => $task->toArray(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully.',
                'data' => $task->load(['department', 'assignedUser.employee', 'creator']),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Task creation validation error', [
                'user_id' => $user->id,
                'request_data' => $request->all(),
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed: ' . $e->getMessage(),
                    'details' => $e->errors(),
                ]
            ], 422);
        } catch (\Exception $e) {
            Log::error('Task creation failed', [
                'user_id' => $user->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create task: ' . $e->getMessage(),
                    'details' => $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified task.
     */
    public function show($taskId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);

            // Check view permission AFTER loading the task
            if (!$this->permissionService->canView($user, $task)) {
                $this->permissionService->logPermissionDenial($user, 'view_task', $task);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to view this task.',
                    ]
                ], 403);
            }

            $task->load([
                'department',
                'assignedUser.employee',
                'creator',
                'parentTask',
                'subtasks',
                'dependencies',
                'assignments.user',
                'assignments.assignedBy',
                'issues',
                'comments',
                'attachments',
                'taskable',
                'logisticsContext',
                'designContext',
                'financeContext',
            ]);
            return response()->json([
                'success' => true,
                'data' => $task,
            ]);

        } catch (\Exception $e) {
            Log::error('Task show error', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the task.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified task.
     */
    public function update(Request $request, $taskId): JsonResponse
    {
        $user = Auth::user();

        // TODO: Temporarily bypass permission check for development
        // Check edit permission
        // if (!$this->permissionService->canEdit($user, $task)) {
        //     $this->permissionService->logPermissionDenial($user, 'edit_task', $task);
        //     return response()->json([
        //         'success' => false,
        //         'error' => [
        //             'code' => 'INSUFFICIENT_PERMISSIONS',
        //             'message' => 'You do not have permission to edit this task.',
        //         ]
        //     ], 403);
        // }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'task_type' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled', 'overdue'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'parent_task_id' => 'nullable|exists:tasks,id',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
            'context' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid task data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $task = Task::findOrFail($taskId);
            $task = $this->taskService->updateTask($task, $request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully.',
                'data' => $task->load(['department', 'assignedUser', 'creator']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update task: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified task.
     */
    public function destroy($taskId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);

            // Check delete permission AFTER loading the task
            if (!$this->permissionService->canDelete($user, $task)) {
                $this->permissionService->logPermissionDenial($user, 'delete_task', $task);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to delete this task.',
                    ]
                ], 403);
            }

            $this->taskService->deleteTask($task, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete task: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Update task status.
     */
    public function updateStatus(Request $request, $taskId): JsonResponse
    {
        $user = Auth::user();

        // Validate request
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled'])],
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid status data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $task = Task::findOrFail($taskId);

            // Check status change permission AFTER loading the task
            if (!$this->permissionService->canChangeStatus($user, $task, $request->status)) {
                $this->permissionService->logPermissionDenial($user, 'change_status', $task, ['new_status' => $request->status]);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to change this task\'s status.',
                    ]
                ], 403);
            }
            $task = $this->taskService->updateStatus(
                $task,
                $request->status,
                $user->id,
                $request->notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully.',
                'data' => $task,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TRANSITION',
                    'message' => $e->getMessage(),
                ]
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATUS_UPDATE_FAILED',
                    'message' => 'Failed to update task status: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Assign task to users with roles.
     */
    public function assign(Request $request, $taskId): JsonResponse
    {
        $user = Auth::user();

        // Validate request
        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array|min:1',
            'assignments.*.user_id' => 'required|exists:users,id',
            'assignments.*.role' => 'nullable|string|max:50',
            'assignments.*.is_primary' => 'nullable|boolean',
            'assignments.*.expires_at' => 'nullable|date|after:now',
            'replace_existing' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid assignment data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $task = Task::findOrFail($taskId);

            // Check assignment permissions for all users AFTER loading the task
            $userIds = collect($request->assignments)->pluck('user_id')->toArray();
            $assignees = User::whereIn('id', $userIds)->get();
            
            foreach ($assignees as $assignee) {
                if (!$this->permissionService->canAssign($user, $task, $assignee)) {
                    $this->permissionService->logPermissionDenial($user, 'assign_task', $task, ['assignee_id' => $assignee->id]);
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'INSUFFICIENT_PERMISSIONS',
                            'message' => 'You do not have permission to assign this task to the specified users.',
                        ]
                    ], 403);
                }
            }
            
            $assignmentData = [
                'assignments' => $request->assignments,
                'replace_existing' => $request->get('replace_existing', false),
            ];

            $task = $this->taskService->assignTask($task, $assignmentData, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task assigned successfully.',
                'data' => $task->load('assignments.user'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ASSIGNMENT_FAILED',
                    'message' => 'Failed to assign task: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get all assignees for a task.
     */
    public function getAssignees($taskId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);

            // Check view permission AFTER loading the task
            if (!$this->permissionService->canView($user, $task)) {
                $this->permissionService->logPermissionDenial($user, 'view_task_assignees', $task);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to view this task\'s assignees.',
                    ]
                ], 403);
            }

            $assignees = $task->getAssigneesWithRoles();

            return response()->json([
                'success' => true,
                'data' => $assignees,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving task assignees.',
                ]
            ], 500);
        }
    }

    /**
     * Remove a specific assignee from a task.
     */
    public function removeAssignee($taskId, $assignmentId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);
            $assignment = TaskAssignment::findOrFail($assignmentId);

            // Verify the assignment belongs to this task
            if ($assignment->task_id !== $task->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_REQUEST',
                        'message' => 'The specified assignment does not belong to this task.',
                    ]
                ], 400);
            }

            // Check assignment permissions
            if (!$this->permissionService->canAssign($user, $task, $assignment->user)) {
                $this->permissionService->logPermissionDenial($user, 'remove_assignee', $task, ['assignee_id' => $assignment->user->id]);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to remove this assignee from the task.',
                    ]
                ], 403);
            }

            // If removing the primary assignee, we need to update the task's assigned_user_id
            if ($assignment->is_primary) {
                // Find another assignee to be the primary
                $newPrimary = $task->assignments()->where('id', '!=', $assignment->id)->first();
                if ($newPrimary) {
                    $newPrimary->is_primary = true;
                    $newPrimary->save();
                    $task->assigned_user_id = $newPrimary->user_id;
                    $task->save();
                } else {
                    // No other assignees, clear the assigned_user_id
                    $task->assigned_user_id = null;
                    $task->save();
                }
            }

            $assignment->delete();

            // Record removal in history
            $this->taskService->recordTaskHistory($task, [
                'removed_assignee' => $assignment->user_id,
                'role' => $assignment->role,
            ], $user->id, 'unassigned');

            // Clear cache for the assigned task
            $this->taskService->clearTaskCache($task);

            return response()->json([
                'success' => true,
                'message' => 'Assignee removed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNASSIGNMENT_FAILED',
                    'message' => 'Failed to remove assignee: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get task history/audit trail.
     */
    public function getHistory($taskId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);

            // Check view permission AFTER loading the task
            if (!$this->permissionService->canView($user, $task)) {
                $this->permissionService->logPermissionDenial($user, 'view_task_history', $task);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to view this task\'s history.',
                    ]
                ], 403);
            }

            $history = $task->history()
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving task history.',
                ]
            ], 500);
        }
    }

    /**
     * Get task activity feed (comments, status changes, assignments, etc.).
     */
    public function getActivity($taskId): JsonResponse
    {
        $user = Auth::user();

        try {
            $task = Task::findOrFail($taskId);

            // Check view permission AFTER loading the task
            if (!$this->permissionService->canView($user, $task)) {
                $this->permissionService->logPermissionDenial($user, 'view_task_activity', $task);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to view this task\'s activity.',
                    ]
                ], 403);
            }

            $activity = $task->getConsolidatedActivityLog();

            return response()->json([
                'success' => true,
                'data' => $activity,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving task activity.',
                ]
            ], 500);
        }
    }
}