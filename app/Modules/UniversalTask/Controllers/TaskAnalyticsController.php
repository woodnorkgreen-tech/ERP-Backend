<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Services\TaskAnalyticsService;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaskAnalyticsController
{
    protected TaskAnalyticsService $analyticsService;
    protected TaskPermissionService $permissionService;

    public function __construct(TaskAnalyticsService $analyticsService, TaskPermissionService $permissionService)
    {
        $this->analyticsService = $analyticsService;
        $this->permissionService = $permissionService;
    }

    /**
     * Get dashboard data.
     */
    public function getDashboard(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'department_id' => 'nullable|exists:departments,id',
            'task_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:pending,in_progress,blocked,review,completed,cancelled,overdue',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid filter parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // Check analytics permission
        $filters = $request->only([
            'department_id', 'task_type', 'status', 'priority', 'assigned_user_id',
            'date_from', 'date_to', 'due_date_from', 'due_date_to'
        ]);

        if (!$this->permissionService->canViewAnalytics($user, $filters)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view analytics.',
                ]
            ], 403);
        }

        try {
            $data = $this->analyticsService->getDashboardData($filters);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving dashboard data.',
                ]
            ], 500);
        }
    }

    /**
     * Get key metrics.
     */
    public function getMetrics(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'department_id' => 'nullable|exists:departments,id',
            'task_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:pending,in_progress,blocked,review,completed,cancelled,overdue',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid filter parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // Check analytics permission
        $filters = $request->only([
            'department_id', 'task_type', 'status', 'priority', 'assigned_user_id',
            'date_from', 'date_to', 'due_date_from', 'due_date_to'
        ]);

        if (!$this->permissionService->canViewAnalytics($user, $filters)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view analytics.',
                ]
            ], 403);
        }

        try {
            $data = $this->analyticsService->getKeyMetrics($filters);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving metrics.',
                ]
            ], 500);
        }
    }

    /**
     * Get time series data.
     */
    public function getTimeSeries(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:day,week,month',
            'days' => 'nullable|integer|min:1|max:365',
            'department_id' => 'nullable|exists:departments,id',
            'task_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:pending,in_progress,blocked,review,completed,cancelled,overdue',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
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

        // Check analytics permission
        $filters = $request->only([
            'department_id', 'task_type', 'status', 'priority', 'assigned_user_id',
            'date_from', 'date_to', 'due_date_from', 'due_date_to'
        ]);

        if (!$this->permissionService->canViewAnalytics($user, $filters)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view analytics.',
                ]
            ], 403);
        }

        try {
            $period = $request->get('period', 'day');
            $days = $request->get('days', 30);

            $data = $this->analyticsService->getTimeSeriesData($period, $days, $filters);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving time series data.',
                ]
            ], 500);
        }
    }

    /**
     * Get department analytics.
     */
    public function getDepartmentAnalytics(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'department_id' => 'nullable|exists:departments,id',
            'task_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:pending,in_progress,blocked,review,completed,cancelled,overdue',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid filter parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // Check analytics permission
        $filters = $request->only([
            'department_id', 'task_type', 'status', 'priority', 'assigned_user_id',
            'date_from', 'date_to', 'due_date_from', 'due_date_to'
        ]);

        if (!$this->permissionService->canViewAnalytics($user, $filters)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view analytics.',
                ]
            ], 403);
        }

        try {
            $data = $this->analyticsService->getDepartmentAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving department analytics.',
                ]
            ], 500);
        }
    }

    /**
     * Export analytics data.
     */
    public function export(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:dashboard,metrics,time_series,department',
            'format' => 'nullable|in:json,csv',
            'period' => 'nullable|in:day,week,month',
            'days' => 'nullable|integer|min:1|max:365',
            'department_id' => 'nullable|exists:departments,id',
            'task_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:pending,in_progress,blocked,review,completed,cancelled,overdue',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid export parameters.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // Check analytics permission
        $filters = $request->only([
            'department_id', 'task_type', 'status', 'priority', 'assigned_user_id',
            'date_from', 'date_to', 'due_date_from', 'due_date_to'
        ]);

        if (!$this->permissionService->canViewAnalytics($user, $filters)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to export analytics.',
                ]
            ], 403);
        }

        try {
            $type = $request->type;
            $format = $request->get('format', 'json');

            $data = match ($type) {
                'dashboard' => $this->analyticsService->getDashboardData($filters),
                'metrics' => $this->analyticsService->getKeyMetrics($filters),
                'time_series' => $this->analyticsService->getTimeSeriesData(
                    $request->get('period', 'day'),
                    $request->get('days', 30),
                    $filters
                ),
                'department' => $this->analyticsService->getDepartmentAnalytics($filters),
            };

            if ($format === 'csv') {
                // For CSV export, we'd need to implement CSV generation
                // For now, return JSON with a note
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_IMPLEMENTED',
                        'message' => 'CSV export is not yet implemented.',
                    ]
                ], 501);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'exported_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXPORT_FAILED',
                    'message' => 'Failed to export analytics data: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }
}