<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Services\SubtaskService;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubtaskController
{
    protected SubtaskService $subtaskService;
    protected TaskPermissionService $permissionService;

    public function __construct(SubtaskService $subtaskService, TaskPermissionService $permissionService)
    {
        $this->subtaskService = $subtaskService;
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of subtasks for a parent task.
     */
    public function index(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the parent task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_subtasks', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view subtasks for this task.',
                ]
            ], 403);
        }

        try {
            $subtasks = $task->subtasks()
                ->with(['assignedUser', 'creator'])
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $subtasks,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving subtasks.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created subtask.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can create subtasks (can edit the parent task)
        if (!$this->permissionService->canEdit($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'create_subtask', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to create subtasks for this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'task_type' => 'nullable|string|max:50',
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_user_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid subtask data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $subtask = $this->subtaskService->createSubtask($task, $request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Subtask created successfully.',
                'data' => $subtask->load(['assignedUser', 'creator']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create subtask: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get the full hierarchy tree for a task.
     */
    public function getHierarchy(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_hierarchy', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this task hierarchy.',
                ]
            ], 403);
        }

        try {
            $hierarchy = $this->subtaskService->getHierarchyTree($task);

            return response()->json([
                'success' => true,
                'data' => $hierarchy,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving task hierarchy.',
                ]
            ], 500);
        }
    }

    /**
     * Move a subtask to a different parent.
     */
    public function move(Request $request, Task $subtask): JsonResponse
    {
        $user = Auth::user();

        // Check if user can edit the subtask
        if (!$this->permissionService->canEdit($user, $subtask)) {
            $this->permissionService->logPermissionDenial($user, 'move_subtask', $subtask);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to move this subtask.',
                ]
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'new_parent_id' => 'nullable|exists:tasks,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid move data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $newParent = $request->new_parent_id ? Task::find($request->new_parent_id) : null;

            // Check if user can edit the new parent (if specified)
            if ($newParent && !$this->permissionService->canEdit($user, $newParent)) {
                $this->permissionService->logPermissionDenial($user, 'move_subtask_to_parent', $newParent);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'You do not have permission to move subtasks to the specified parent task.',
                    ]
                ], 403);
            }

            $movedSubtask = $this->subtaskService->moveSubtask($subtask, $newParent, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Subtask moved successfully.',
                'data' => $movedSubtask->load(['parentTask', 'assignedUser']),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_MOVE',
                    'message' => $e->getMessage(),
                ]
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MOVE_FAILED',
                    'message' => 'Failed to move subtask: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }
}