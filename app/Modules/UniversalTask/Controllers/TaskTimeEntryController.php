<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskTimeEntry;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use App\Modules\UniversalTask\Services\TimeTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaskTimeEntryController
{
    protected TaskPermissionService $permissionService;
    protected TimeTrackingService $timeTrackingService;

    public function __construct(TaskPermissionService $permissionService, TimeTrackingService $timeTrackingService)
    {
        $this->permissionService = $permissionService;
        $this->timeTrackingService = $timeTrackingService;
    }

    /**
     * Display a listing of time entries for a task.
     */
    public function index(Task $task, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view time entries for this task.',
                ]
            ], 403);
        }

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'is_billable' => 'nullable|boolean',
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
            $filters = $request->only(['user_id', 'date_from', 'date_to', 'is_billable']);
            $timeEntries = $this->timeTrackingService->getTimeEntriesForTask($task, $filters);

            return response()->json([
                'success' => true,
                'data' => $timeEntries,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving time entries.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created time entry.
     */
    public function store(Task $task, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check view permission for the task (required to log time)
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to log time for this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'hours' => 'required|numeric|min:0.01|max:24',
            'date_worked' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:1000',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
            'is_billable' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid time entry data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $timeEntry = $this->timeTrackingService->logTime($task, $request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Time logged successfully.',
                'data' => $timeEntry->load(['user', 'task']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LOG_TIME_FAILED',
                    'message' => 'Failed to log time: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified time entry.
     */
    public function show(Task $task, TaskTimeEntry $timeEntry): JsonResponse
    {
        $user = Auth::user();

        // Ensure time entry belongs to the task
        if ($timeEntry->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Time entry not found for this task.',
                ]
            ], 404);
        }

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this time entry.',
                ]
            ], 403);
        }

        try {
            $timeEntry->load(['user', 'task']);

            return response()->json([
                'success' => true,
                'data' => $timeEntry,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the time entry.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified time entry.
     */
    public function update(Task $task, TaskTimeEntry $timeEntry, Request $request): JsonResponse
    {
        $user = Auth::user();

        // Ensure time entry belongs to the task
        if ($timeEntry->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Time entry not found for this task.',
                ]
            ], 404);
        }

        // Check if user can edit this time entry (only the creator can edit)
        if ($timeEntry->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only edit your own time entries.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'hours' => 'sometimes|required|numeric|min:0.01|max:24',
            'date_worked' => 'sometimes|required|date|before_or_equal:today',
            'description' => 'nullable|string|max:1000',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
            'is_billable' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid time entry data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $timeEntry = $this->timeTrackingService->updateTimeEntry($timeEntry, $request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Time entry updated successfully.',
                'data' => $timeEntry->load(['user', 'task']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update time entry: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified time entry.
     */
    public function destroy(Task $task, TaskTimeEntry $timeEntry): JsonResponse
    {
        $user = Auth::user();

        // Ensure time entry belongs to the task
        if ($timeEntry->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Time entry not found for this task.',
                ]
            ], 404);
        }

        // Check if user can delete this time entry (only the creator can delete)
        if ($timeEntry->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only delete your own time entries.',
                ]
            ], 403);
        }

        try {
            $this->timeTrackingService->deleteTimeEntry($timeEntry, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Time entry deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete time entry: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get time variance for a task.
     */
    public function getVariance(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view time variance for this task.',
                ]
            ], 403);
        }

        try {
            $variance = $this->timeTrackingService->calculateTimeVariance($task);

            return response()->json([
                'success' => true,
                'data' => [
                    'task_id' => $task->id,
                    'estimated_hours' => $task->estimated_hours,
                    'actual_hours' => $task->calculateActualHours(),
                    'variance' => $variance,
                    'variance_formatted' => $variance !== null ? ($variance > 0 ? "+{$variance}h" : "{$variance}h") : null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while calculating time variance.',
                ]
            ], 500);
        }
    }
}