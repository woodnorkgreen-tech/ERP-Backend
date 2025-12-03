<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskIssueController
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of issues for a specific task.
     */
    public function index(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_task_issues', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view issues for this task.',
                ]
            ], 403);
        }

        try {
            $issues = $task->issues()
                ->with(['reporter', 'assignee', 'resolver'])
                ->orderBy('reported_at', 'desc')
                ->paginate(25);

            return response()->json([
                'success' => true,
                'data' => $issues,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving task issues.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created issue.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check if user can view the task (basic requirement for logging issues)
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'create_task_issue', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to log issues for this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'issue_type' => ['required', Rule::in(['blocker', 'technical', 'resource', 'dependency', 'general'])],
            'severity' => ['required', Rule::in(['critical', 'high', 'medium', 'low'])],
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid issue data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $issue = TaskIssue::create([
                'task_id' => $task->id,
                'title' => $request->title,
                'description' => $request->description,
                'issue_type' => $request->issue_type,
                'severity' => $request->severity,
                'status' => 'open',
                'reported_by' => $user->id,
                'assigned_to' => $request->assigned_to,
                'reported_at' => now(),
            ]);

            // Load relationships for response
            $issue->load(['reporter', 'assignee']);

            return response()->json([
                'success' => true,
                'message' => 'Issue logged successfully.',
                'data' => $issue,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to log issue: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified issue.
     */
    public function show(Task $task, TaskIssue $issue): JsonResponse
    {
        // Ensure the issue belongs to the task
        if ($issue->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Issue not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'view_task_issue', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this issue.',
                ]
            ], 403);
        }

        try {
            $issue->load(['task', 'reporter', 'assignee', 'resolver']);

            return response()->json([
                'success' => true,
                'data' => $issue,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the issue.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified issue.
     */
    public function update(Request $request, Task $task, TaskIssue $issue): JsonResponse
    {
        // Ensure the issue belongs to the task
        if ($issue->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Issue not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'update_task_issue', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to update this issue.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'issue_type' => ['sometimes', 'required', Rule::in(['blocker', 'technical', 'resource', 'dependency', 'general'])],
            'severity' => ['sometimes', 'required', Rule::in(['critical', 'high', 'medium', 'low'])],
            'status' => ['sometimes', 'required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid issue data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $issue->update($request->only([
                'title', 'description', 'issue_type', 'severity', 'status', 'assigned_to'
            ]));

            $issue->load(['reporter', 'assignee', 'resolver']);

            return response()->json([
                'success' => true,
                'message' => 'Issue updated successfully.',
                'data' => $issue,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update issue: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Resolve the specified issue.
     */
    public function resolve(Request $request, Task $task, TaskIssue $issue): JsonResponse
    {
        // Ensure the issue belongs to the task
        if ($issue->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Issue not found for this task.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check if user can view the task
        if (!$this->permissionService->canView($user, $task)) {
            $this->permissionService->logPermissionDenial($user, 'resolve_task_issue', $task);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to resolve this issue.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid resolution data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $issue->markAsResolved($user->id, $request->resolution_notes);

            $issue->load(['reporter', 'assignee', 'resolver']);

            return response()->json([
                'success' => true,
                'message' => 'Issue resolved successfully.',
                'data' => $issue,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RESOLUTION_FAILED',
                    'message' => 'Failed to resolve issue: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Search issues across tasks.
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
                    'message' => 'You do not have permission to search issues.',
                ]
            ], 403);
        }

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'severity' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'issue_type' => ['nullable', Rule::in(['blocker', 'technical', 'resource', 'dependency', 'general'])],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'task_id' => 'nullable|exists:tasks,id',
            'reported_by' => 'nullable|exists:users,id',
            'assigned_to' => 'nullable|exists:users,id',
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
            $query = TaskIssue::with(['task', 'reporter', 'assignee', 'resolver']);

            // Apply permission filters - only show issues for tasks user can access
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
                      ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Apply filters
            $filters = $request->only([
                'severity', 'issue_type', 'status', 'task_id', 'reported_by', 'assigned_to'
            ]);

            foreach ($filters as $field => $value) {
                if ($value !== null) {
                    if (str_ends_with($field, '_from')) {
                        $query->where(substr($field, 0, -5), '>=', $value);
                    } elseif (str_ends_with($field, '_to')) {
                        $query->where(substr($field, 0, -3), '<=', $value);
                    } else {
                        $query->where($field, $value);
                    }
                }
            }

            // Apply date range
            if ($request->date_from) {
                $query->where('reported_at', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->where('reported_at', '<=', $request->date_to);
            }

            // Sort by reported date descending
            $query->orderBy('reported_at', 'desc');

            // Paginate results
            $perPage = $request->get('per_page', 25);
            $issues = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $issues,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while searching issues.',
                ]
            ], 500);
        }
    }
}