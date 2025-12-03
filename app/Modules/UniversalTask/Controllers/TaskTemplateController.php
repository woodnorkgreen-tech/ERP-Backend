<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\TaskTemplate;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use App\Modules\UniversalTask\Services\TaskTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaskTemplateController
{
    protected TaskPermissionService $permissionService;
    protected TaskTemplateService $templateService;

    public function __construct(TaskPermissionService $permissionService, TaskTemplateService $templateService)
    {
        $this->permissionService = $permissionService;
        $this->templateService = $templateService;
    }

    /**
     * Display a listing of task templates.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view task templates.',
                ]
            ], 403);
        }

        // Validate request parameters
        $validator = Validator::make($request->all(), [
            'category' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:255',
            'include_inactive' => 'nullable|boolean',
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
            $query = TaskTemplate::with(['creator', 'updater']);

            // Filter by category if provided
            if ($request->has('category') && !empty($request->category)) {
                $query->byCategory($request->category);
            }

            // Search by name or description
            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Include inactive templates if requested
            if (!$request->boolean('include_inactive', false)) {
                $query->active();
            }

            // Get latest versions only
            $query->latestVersions();

            $templates = $query->orderBy('updated_at', 'desc')->paginate(25);

            return response()->json([
                'success' => true,
                'data' => $templates,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving templates.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created task template.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to create task templates.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:50',
            'template_data' => 'required|array',
            'template_data.tasks' => 'required|array|min:1',
            'template_data.tasks.*.title' => 'required|string|max:255',
            'template_data.tasks.*.description' => 'nullable|string',
            'template_data.tasks.*.task_type' => 'nullable|string|max:50',
            'template_data.tasks.*.priority' => 'nullable|in:low,medium,high,urgent',
            'template_data.tasks.*.estimated_hours' => 'nullable|numeric|min:0',
            'template_data.tasks.*.tags' => 'nullable|array',
            'template_data.tasks.*.metadata' => 'nullable|array',
            'template_data.tasks.*.parent_id' => 'nullable|string',
            'template_data.tasks.*.due_date_offset_days' => 'nullable|integer|min:0',
            'template_data.dependencies' => 'nullable|array',
            'template_data.dependencies.*.task_id' => 'required|string',
            'template_data.dependencies.*.depends_on_task_id' => 'required|string',
            'template_data.dependencies.*.dependency_type' => 'nullable|in:blocks,relates_to',
            'variables' => 'nullable|array',
            'variables.*.required' => 'nullable|boolean',
            'tags' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid template data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $template = $this->templateService->createTemplate($request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task template created successfully.',
                'data' => $template->load(['creator', 'updater']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create template: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified task template.
     */
    public function show(TaskTemplate $template): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view task templates.',
                ]
            ], 403);
        }

        try {
            $template->load(['creator', 'updater', 'previousVersion', 'newerVersions']);

            return response()->json([
                'success' => true,
                'data' => $template,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the template.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified task template.
     */
    public function update(Request $request, TaskTemplate $template): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to update task templates.',
                ]
            ], 403);
        }

        // Validate request data (similar to store but all fields optional)
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:50',
            'template_data' => 'sometimes|required|array',
            'template_data.tasks' => 'required_with:template_data|array|min:1',
            'template_data.tasks.*.title' => 'required|string|max:255',
            'template_data.tasks.*.description' => 'nullable|string',
            'template_data.tasks.*.task_type' => 'nullable|string|max:50',
            'template_data.tasks.*.priority' => 'nullable|in:low,medium,high,urgent',
            'template_data.tasks.*.estimated_hours' => 'nullable|numeric|min:0',
            'template_data.tasks.*.tags' => 'nullable|array',
            'template_data.tasks.*.metadata' => 'nullable|array',
            'template_data.tasks.*.parent_id' => 'nullable|string',
            'template_data.tasks.*.due_date_offset_days' => 'nullable|integer|min:0',
            'template_data.dependencies' => 'nullable|array',
            'template_data.dependencies.*.task_id' => 'required|string',
            'template_data.dependencies.*.depends_on_task_id' => 'required|string',
            'template_data.dependencies.*.dependency_type' => 'nullable|in:blocks,relates_to',
            'variables' => 'nullable|array',
            'variables.*.required' => 'nullable|boolean',
            'tags' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid template data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $template = $this->templateService->updateTemplate($template, $request->all(), $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Task template updated successfully.',
                'data' => $template->load(['creator', 'updater']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update template: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified task template.
     */
    public function destroy(TaskTemplate $template): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to delete task templates.',
                ]
            ], 403);
        }

        try {
            $this->templateService->deleteTemplate($template);

            return response()->json([
                'success' => true,
                'message' => 'Task template deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete template: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Instantiate a template to create tasks.
     */
    public function instantiate(Request $request, TaskTemplate $template): JsonResponse
    {
        $user = Auth::user();

        // Check if user can create tasks (required for instantiation)
        if (!$this->permissionService->canCreate($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to instantiate task templates.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'variables' => 'nullable|array',
            'taskable_type' => 'nullable|string|max:255',
            'taskable_id' => 'nullable|integer',
            'department_id' => 'nullable|exists:departments,id',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid instantiation data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $context = array_merge($request->only([
                'taskable_type', 'taskable_id', 'department_id', 'assigned_user_id'
            ]), [
                'created_by' => $user->id,
                'template_id' => $template->id,
                'template_version' => $template->version,
            ]);

            $result = $this->templateService->instantiateTemplate(
                $template,
                $request->get('variables', []),
                $context
            );

            return response()->json([
                'success' => true,
                'message' => 'Template instantiated successfully.',
                'data' => [
                    'tasks_created' => count($result['tasks']),
                    'dependencies_created' => count($result['dependencies']),
                    'tasks' => $result['tasks'],
                    'dependencies' => $result['dependencies'],
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ]
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSTANTIATION_FAILED',
                    'message' => 'Failed to instantiate template: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get all versions of a template.
     */
    public function getVersions(TaskTemplate $template): JsonResponse
    {
        $user = Auth::user();

        // Check if user can manage templates
        if (!$this->permissionService->canManageTemplates($user)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view template versions.',
                ]
            ], 403);
        }

        try {
            $versions = $template->getAllVersions()->load(['creator', 'updater']);

            return response()->json([
                'success' => true,
                'data' => $versions,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving template versions.',
                ]
            ], 500);
        }
    }
}