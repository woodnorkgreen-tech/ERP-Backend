<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Services\TaskService;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use App\Modules\UniversalTask\Repositories\TaskRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
        TaskRepository $taskRepository
    ) {
        $this->taskService = $taskService;
        $this->permissionService = $permissionService;
        $this->taskRepository = $taskRepository;
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
            'task_type' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'parent_task_id' => 'nullable|exists:tasks,id',
            'taskable_type' => 'nullable|string|max:255',
            'taskable_id' => 'nullable|integer',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after_or_equal:today',
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
            $task = $this->taskService->createTask($request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully.',
                'data' => $task->load(['department', 'assignedUser', 'creator']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create task: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission
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

        try {
            $task->load([
                'department',
                'assignedUser',
                'creator',
                'parentTask',
                'subtasks',
                'dependencies',
                'assignments',
                'issues',
                'comments',
                'attachments',
            ]);

            return response()->json([
                'success' => true,
                'data' => $task,
            ]);

        } catch (\Exception $e) {
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
    public function update(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check edit permission
        if (!$this->permissionService->canEdit($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'edit_task', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to edit this task.',
                ]
            ], 403);
        }

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
    public function destroy(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check delete permission
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

        try {
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
    public function updateStatus(Request $request, Task $task): JsonResponse
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

        // Check status change permission
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

        try {
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
     * Assign task to users.
     */
    public function assign(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Validate request
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'role' => 'nullable|string|max:50',
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

        // Check assignment permissions for all users
        $assignees = User::whereIn('id', $request->user_ids)->get();
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

        try {
            $assignmentData = [
                'user_ids' => $request->user_ids,
                'role' => $request->role,
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
     * Get task history/audit trail.
     */
    public function getHistory(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission
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

        try {
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
    public function getActivity(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission
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

        try {
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