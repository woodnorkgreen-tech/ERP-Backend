<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskExperienceLog;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskExperienceController
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of experience logs for a specific task.
     */
    public function index(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_task_experience_logs', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view experience logs for this task.',
                ]
            ], 403);
        }

        try {
            $logs = $task->experienceLogs()
                ->with('user')
                ->orderBy('logged_at', 'desc')
                ->paginate(25);

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving experience logs.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created experience log.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the task (basic requirement for logging experience)
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'create_task_experience_log', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to log experience for this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'log_type' => ['required', Rule::in(['observation', 'learning', 'best_practice', 'recommendation', 'issue', 'success'])],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid experience log data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $log = TaskExperienceLog::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'title' => $request->title,
                'content' => $request->content,
                'log_type' => $request->log_type,
                'tags' => $request->tags ?? [],
                'is_public' => $request->is_public ?? false,
                'logged_at' => now(),
            ]);

            // Load user relationship for response
            $log->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Experience log created successfully.',
                'data' => $log,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create experience log: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified experience log.
     */
    public function show(Task $task, TaskExperienceLog $log): JsonResponse
    {
        // Ensure the log belongs to the task
        if ($log->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Experience log not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_task_experience_log', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this experience log.',
                ]
            ], 403);
        }

        // Check if log is private and user is not the owner
        if (!$log->is_public && $log->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'This experience log is private.',
                ]
            ], 403);
        }

        try {
            $log->load(['task', 'user']);

            return response()->json([
                'success' => true,
                'data' => $log,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the experience log.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified experience log.
     */
    public function update(Request $request, Task $task, TaskExperienceLog $log): JsonResponse
    {
        // Ensure the log belongs to the task
        if ($log->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Experience log not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'update_task_experience_log', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to update this experience log.',
                ]
            ], 403);
        }

        // Only the owner can update their logs
        if ($log->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only update your own experience logs.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'log_type' => ['sometimes', 'required', Rule::in(['observation', 'learning', 'best_practice', 'recommendation', 'issue', 'success'])],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid experience log data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $log->update($request->only([
                'title', 'content', 'log_type', 'tags', 'is_public'
            ]));

            $log->load(['task', 'user']);

            return response()->json([
                'success' => true,
                'message' => 'Experience log updated successfully.',
                'data' => $log,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update experience log: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified experience log.
     */
    public function destroy(Task $task, TaskExperienceLog $log): JsonResponse
    {
        // Ensure the log belongs to the task
        if ($log->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Experience log not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'delete_task_experience_log', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to delete this experience log.',
                ]
            ], 403);
        }

        // Only the owner can delete their logs
        if ($log->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only delete your own experience logs.',
                ]
            ], 403);
        }

        try {
            $log->delete();

            return response()->json([
                'success' => true,
                'message' => 'Experience log deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete experience log: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Search experience logs across tasks.
     */
    public function search(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check basic read permission
        if (!$this->permissionService->canView($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to search experience logs.',
                ]
            ], 403);
        }

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'log_type' => ['nullable', Rule::in(['observation', 'learning', 'best_practice', 'recommendation', 'issue', 'success'])],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'is_public' => 'nullable|boolean',
            'task_id' => 'nullable|exists:tasks,id',
            'user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid search parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $query = TaskExperienceLog::with(['task', 'user']);

            // Apply permission filters - only show logs for tasks user can access
            $accessibleDepartments = $this->permissionService->getAccessibleDepartments($user);
            if (!empty($accessibleDepartments)) {
                $query->whereHas('task', function ($taskQuery) use ($accessibleDepartments) {
                    $taskQuery->whereIn('department_id', $accessibleDepartments);
                });
            }

            // Apply search
            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('content', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Apply filters
            $filters = [
                'type' => $request->log_type,
                'tags' => $request->tags,
                'is_public' => $request->is_public,
                'start_date' => $request->date_from,
                'end_date' => $request->date_to,
            ];

            $query->filter(array_filter($filters));

            // Additional filters
            if ($request->task_id) {
                $query->where('task_id', $request->task_id);
            }

            if ($request->user_id) {
                $query->where('user_id', $request->user_id);
            }

            // Sort by logged date descending
            $query->orderBy('logged_at', 'desc');

            // Paginate results
            $perPage = $request->get('per_page', 25);
            $logs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while searching experience logs.',
                ]
            ], 500);
        }
    }
}