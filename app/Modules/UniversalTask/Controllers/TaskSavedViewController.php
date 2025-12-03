<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\TaskSavedView;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaskSavedViewController
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of saved views for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        try {
            $query = TaskSavedView::forUser($user->id);

            // Include shared views if requested
            if ($request->boolean('include_shared', false)) {
                $query->orWhere('is_shared', true);
            }

            $views = $query->orderBy('is_default', 'desc')
                          ->orderBy('updated_at', 'desc')
                          ->get();

            return response()->json([
                'success' => true,
                'data' => $views,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving saved views.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created saved view.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'filters' => 'required|array',
            'sort_config' => 'nullable|array',
            'sort_config.sort_by' => 'nullable|string',
            'sort_config.sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'is_default' => 'nullable|boolean',
            'is_shared' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid saved view data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            // If setting as default, remove existing default
            if ($request->boolean('is_default', false)) {
                TaskSavedView::forUser($user->id)->default()->update(['is_default' => false]);
            }

            $view = TaskSavedView::create([
                'name' => $request->name,
                'description' => $request->description,
                'user_id' => $user->id,
                'filters' => $request->filters,
                'sort_config' => $request->sort_config,
                'per_page' => $request->get('per_page', 25),
                'is_default' => $request->boolean('is_default', false),
                'is_shared' => $request->boolean('is_shared', false),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Saved view created successfully.',
                'data' => $view,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create saved view: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified saved view.
     */
    public function show(TaskSavedView $view): JsonResponse
    {
        $user = Auth::user();

        // Check ownership or shared status
        if ($view->user_id !== $user->id && !$view->is_shared) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this saved view.',
                ]
            ], 403);
        }

        try {
            return response()->json([
                'success' => true,
                'data' => $view,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the saved view.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified saved view.
     */
    public function update(Request $request, TaskSavedView $view): JsonResponse
    {
        $user = Auth::user();

        // Check ownership
        if ($view->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only update your own saved views.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'filters' => 'sometimes|required|array',
            'sort_config' => 'nullable|array',
            'sort_config.sort_by' => 'nullable|string',
            'sort_config.sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'is_default' => 'nullable|boolean',
            'is_shared' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid saved view data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            // If setting as default, remove existing default
            if ($request->boolean('is_default', false) && !$view->is_default) {
                TaskSavedView::forUser($user->id)->default()->update(['is_default' => false]);
            }

            $view->update($request->only([
                'name', 'description', 'filters', 'sort_config', 'per_page', 'is_default', 'is_shared'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Saved view updated successfully.',
                'data' => $view,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update saved view: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified saved view.
     */
    public function destroy(TaskSavedView $view): JsonResponse
    {
        $user = Auth::user();

        // Check ownership
        if ($view->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only delete your own saved views.',
                ]
            ], 403);
        }

        try {
            $view->delete();

            return response()->json([
                'success' => true,
                'message' => 'Saved view deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete saved view: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Apply a saved view and return filtered tasks.
     */
    public function apply(Request $request, TaskSavedView $view): JsonResponse
    {
        $user = Auth::user();

        // Check ownership or shared status
        if ($view->user_id !== $user->id && !$view->is_shared) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to use this saved view.',
                ]
            ], 403);
        }

        try {
            // Get the TaskController to reuse its index method with the saved view's filters
            $taskController = app(TaskController::class);

            // Merge saved view parameters with request
            $params = array_merge($request->all(), [
                'per_page' => $view->per_page,
                'sort_by' => $view->getSortConfig()['sort_by'] ?? 'created_at',
                'sort_direction' => $view->getSortConfig()['sort_direction'] ?? 'desc',
            ], $view->getFilters());

            // Create a new request with merged parameters
            $newRequest = new Request($params);

            return $taskController->index($newRequest);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while applying the saved view.',
                ]
            ], 500);
        }
    }
}