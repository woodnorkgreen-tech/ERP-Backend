<?php

namespace App\Http\Controllers;

use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;

class BudgetController extends Controller
{
    public function __construct()
    {
        // No permission restrictions for budget operations
    }

    /**
     * Get budget data for a task
     */
    public function getBudgetData(int $taskId): JsonResponse
    {
        try {
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

            if (!$budgetData) {
                return response()->json([
                    'data' => $this->getDefaultBudgetStructure($taskId),
                    'message' => 'Budget data retrieved successfully'
                ]);
            }

            // Format the response to match frontend expectations
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data,
                'labour' => $budgetData->labour_data ?? [],
                'expenses' => $budgetData->expenses_data ?? [],
                'logistics' => $budgetData->logistics_data ?? [],
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status,
                'createdAt' => $budgetData->created_at?->toISOString(),
                'updatedAt' => $budgetData->updated_at?->toISOString()
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Budget data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save budget data for a task
     */
    public function saveBudgetData(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'projectInfo' => 'required|array',
            'projectInfo.projectId' => 'required|string|max:255',
            'projectInfo.enquiryTitle' => 'required|string|max:255',
            'projectInfo.clientName' => 'required|string|max:255',
            'projectInfo.eventVenue' => 'required|string|max:255',
            'projectInfo.setupDate' => 'required|string',
            'projectInfo.setDownDate' => 'nullable|string',
            'materials' => 'present|array', // Changed from required to present to allow empty arrays
            'materials.*.id' => 'required|string',
            'materials.*.elementType' => 'required|string|max:255',
            'materials.*.name' => 'required|string|max:255',
            'materials.*.category' => 'required|in:production,hire,outsourced',
            'materials.*.isIncluded' => 'required|boolean',
            'materials.*.materials' => 'required|array|min:1',
            'materials.*.materials.*.id' => 'required|string',
            'materials.*.materials.*.description' => 'required|string|max:500',
            'materials.*.materials.*.unitOfMeasurement' => 'required|string|max:50',
            'materials.*.materials.*.quantity' => 'required|numeric|min:0.01',
            'materials.*.materials.*.isIncluded' => 'required|boolean',
            'materials.*.materials.*.unitPrice' => 'required|numeric|min:0',
            'materials.*.materials.*.totalPrice' => 'required|numeric|min:0',
            'labour' => 'present|array', // Changed from required to present to allow empty arrays
            'labour.*.id' => 'required|string',
            'labour.*.category' => 'required|in:Production,Installation,Technical,Supervision,Other',
            'labour.*.type' => 'required|string|max:255',
            'labour.*.description' => 'nullable|string|max:500',
            'labour.*.unit' => 'required|in:PAX,days,hours',
            'labour.*.quantity' => 'required|numeric|min:0.01',
            'labour.*.unitRate' => 'required|numeric|min:0',
            'labour.*.amount' => 'required|numeric|min:0',
            'expenses' => 'present|array', // Changed from required to present to allow empty arrays
            'expenses.*.id' => 'required|string',
            'expenses.*.description' => 'required|string|max:500',
            'expenses.*.category' => 'required|string|max:255',
            'expenses.*.amount' => 'required|numeric|min:0.01',
            'logistics' => 'present|array', // Changed from required to present to allow empty arrays
            'logistics.*.id' => 'required|string',
            'logistics.*.vehicleReg' => 'nullable|string|max:50',
            'logistics.*.description' => 'required|string|max:500',
            'logistics.*.category' => 'required|string|max:255',
            'logistics.*.unit' => 'nullable|string|max:50',
            'logistics.*.quantity' => 'required|numeric|min:0.01',
            'logistics.*.unitRate' => 'required|numeric|min:0',
            'logistics.*.amount' => 'required|numeric|min:0',
            'budgetSummary' => 'required|array',
            'budgetSummary.materialsTotal' => 'required|numeric|min:0',
            'budgetSummary.labourTotal' => 'required|numeric|min:0',
            'budgetSummary.expensesTotal' => 'required|numeric|min:0',
            'budgetSummary.logisticsTotal' => 'required|numeric|min:0',
            'budgetSummary.grandTotal' => 'required|numeric|min:0',
            'status' => 'required|in:draft,pending_approval,approved,rejected',
            'taskId' => 'nullable|integer' // Added taskId validation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed. Please check all required fields and ensure amounts are valid.',
                'errors' => $validator->errors(),
                'error_type' => 'validation_error'
            ], 422);
        }

        // Additional business logic validation
        $businessValidationErrors = $this->validateBudgetBusinessLogic($request->all());
        if (!empty($businessValidationErrors)) {
            return response()->json([
                'message' => 'Business logic validation failed.',
                'errors' => $businessValidationErrors,
                'error_type' => 'business_logic_error'
            ], 422);
        }

        try {
            // Validate task exists and is accessible
            $task = EnquiryTask::find($taskId);
            if (!$task) {
                return response()->json([
                    'message' => 'Budget task not found',
                    'error_type' => 'task_not_found'
                ], 404);
            }

            // Check if materials data has been modified
            $existingBudgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            $materialsModified = $existingBudgetData &&
                $existingBudgetData->materials_imported_from_task &&
                $request->materials !== $existingBudgetData->materials_data;

            $budgetData = TaskBudgetData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $request->projectInfo,
                    'materials_data' => $request->materials,
                    'labour_data' => $request->labour,
                    'expenses_data' => $request->expenses,
                    'logistics_data' => $request->logistics,
                    'budget_summary' => $request->budgetSummary,
                    'status' => $request->status,
                    'materials_manually_modified' => $materialsModified || ($existingBudgetData ? $existingBudgetData->materials_manually_modified : false),
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'data' => $budgetData->fresh(),
                'message' => 'Budget data saved successfully'
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('Database error saving budget data', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return response()->json([
                'message' => 'Database error occurred while saving budget data. Please try again.',
                'error_type' => 'database_error'
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error saving budget data', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred while saving budget data. Please contact support if the problem persists.',
                'error_type' => 'unexpected_error'
            ], 500);
        }
    }

    /**
     * Submit budget for approval
     */
    public function submitForApproval(int $taskId): JsonResponse
    {
        try {
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

            if (!$budgetData) {
                return response()->json([
                    'message' => 'Budget data not found'
                ], 404);
            }

            $budgetData->update(['status' => 'pending_approval']);

            // TODO: Send notifications to approvers

            return response()->json([
                'message' => 'Budget submitted for approval successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to submit budget for approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import materials from the materials task into budget
     */
    public function importMaterials(int $taskId): JsonResponse
    {
        try {
            $task = EnquiryTask::with('enquiry')->find($taskId);

            if (!$task) {
                return response()->json([
                    'message' => 'Budget task not found'
                ], 404);
            }

            // Find materials task for the same enquiry
            $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                return response()->json([
                    'message' => 'Materials task not found for this enquiry'
                ], 404);
            }

            // Get materials data using MaterialsController
            $materialsController = new \App\Http\Controllers\MaterialsController();
            $materialsResponse = $materialsController->getMaterialsData($materialsTask->id);
            $materialsData = json_decode($materialsResponse->getContent(), true);

            if (!$materialsData['data'] || !$materialsData['data']['projectElements']) {
                return response()->json([
                    'message' => 'No materials found in Materials Task'
                ], 404);
            }

            // Transform materials for budget
            $budgetMaterials = [];
            foreach ($materialsData['data']['projectElements'] as $element) {
                if (!$element['isIncluded']) continue;

                $budgetElement = [
                    'id' => $element['id'],
                    'templateId' => $element['templateId'] ?? null,
                    'elementType' => $element['elementType'],
                    'name' => $element['name'],
                    'category' => $element['category'],
                    'dimensions' => $element['dimensions'] ?? ['length' => '', 'width' => '', 'height' => ''],
                    'isIncluded' => $element['isIncluded'],
                    'notes' => $element['notes'] ?? '',
                    'addedAt' => now()->toISOString(),
                    'materials' => []
                ];

                foreach ($element['materials'] as $material) {
                    if (!$material['isIncluded']) continue;

                    $budgetElement['materials'][] = [
                        'id' => $material['id'],
                        'description' => $material['description'],
                        'unitOfMeasurement' => $material['unitOfMeasurement'],
                        'quantity' => $material['quantity'],
                        'isIncluded' => $material['isIncluded'],
                        'unitPrice' => 0,
                        'totalPrice' => 0,
                        'isAddition' => false,
                        'addedAt' => now()->toISOString()
                    ];
                }

                if (!empty($budgetElement['materials'])) {
                    $budgetMaterials[] = $budgetElement;
                }
            }

            // Create or update budget with imported materials
            $budgetData = TaskBudgetData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $materialsData['data']['projectInfo'] ?? [
                        'projectId' => $task->enquiry->enquiry_number ?? "ENQ-{$taskId}",
                        'enquiryTitle' => $task->enquiry->title ?? 'Untitled Project',
                        'clientName' => $task->enquiry->client->full_name ?? 'Unknown Client',
                        'eventVenue' => $task->enquiry->venue ?? 'Venue TBC',
                        'setupDate' => $task->enquiry->expected_delivery_date ?? 'Date TBC',
                        'setDownDate' => 'TBC'
                    ],
                    'materials_data' => $budgetMaterials,
                    'labour_data' => [],
                    'expenses_data' => [],
                    'logistics_data' => [],
                    'budget_summary' => [
                        'materialsTotal' => 0,
                        'labourTotal' => 0,
                        'expensesTotal' => 0,
                        'logisticsTotal' => 0,
                        'grandTotal' => 0
                    ],
                    'status' => 'draft',
                    'updated_at' => now()
                ]
            );

            // Format response
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data,
                'labour' => $budgetData->labour_data,
                'expenses' => $budgetData->expenses_data,
                'logistics' => $budgetData->logistics_data,
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status,
                'materialsImportInfo' => [
                    'importedAt' => now()->toISOString(),
                    'importedFromTask' => $materialsTask->id,
                    'manuallyModified' => false,
                    'importMetadata' => [
                        'imported_at' => now()->toISOString(),
                        'materials_task_id' => $materialsTask->id,
                        'materials_task_title' => $materialsTask->title ?? 'Materials Task',
                        'total_elements' => count($budgetMaterials),
                        'total_materials' => array_sum(array_map(fn($e) => count($e['materials']), $budgetMaterials))
                    ]
                ]
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Materials imported successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import materials',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if materials have been updated since last import
     */
    public function checkMaterialsUpdate(int $taskId): JsonResponse
    {
        try {
            // Find the budget task
            $budgetTask = EnquiryTask::find($taskId);
            if (!$budgetTask) {
                return response()->json([
                    'message' => 'Budget task not found'
                ], 404);
            }

            // Get current budget data
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData || !$budgetData->materials_imported_from_task) {
                return response()->json([
                    'has_updates' => false,
                    'message' => 'No materials import history found'
                ]);
            }

            // Find the materials task
            $materialsTask = EnquiryTask::find($budgetData->materials_imported_from_task);
            if (!$materialsTask) {
                return response()->json([
                    'has_updates' => false,
                    'message' => 'Materials task no longer exists'
                ]);
            }

            // Get current materials data
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
            if (!$materialsData) {
                return response()->json([
                    'has_updates' => false,
                    'message' => 'Materials data no longer exists'
                ]);
            }

            // Check if materials were updated after last import
            $hasUpdates = $materialsData->updated_at > $budgetData->materials_imported_at;

            return response()->json([
                'has_updates' => $hasUpdates,
                'last_import_at' => $budgetData->materials_imported_at?->toISOString(),
                'materials_updated_at' => $materialsData->updated_at?->toISOString(),
                'materials_task_title' => $materialsTask->title,
                'message' => $hasUpdates ? 'Materials have been updated since last import' : 'Materials are up to date'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to check materials update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Get default budget structure
     */
    private function getDefaultBudgetStructure(int $taskId): array
    {
        $task = EnquiryTask::with('enquiry')->find($taskId);

        return [
            'projectInfo' => [
                'projectId' => $task->enquiry->enquiry_number ?? "ENQ-{$taskId}",
                'enquiryTitle' => $task->enquiry->title ?? 'Untitled Project',
                'clientName' => $task->enquiry->client->full_name ?? 'Unknown Client',
                'eventVenue' => $task->enquiry->venue ?? 'Venue TBC',
                'setupDate' => $task->enquiry->expected_delivery_date ?? 'Date TBC',
                'setDownDate' => 'TBC'
            ],
            'materials' => [], // Will be populated from MaterialsTask
            'labour' => [],    // Default labour structure
            'expenses' => [],  // Empty expenses
            'logistics' => [], // Empty logistics
            'budgetSummary' => [
                'materialsTotal' => 0,
                'labourTotal' => 0,
                'expensesTotal' => 0,
                'logisticsTotal' => 0,
                'grandTotal' => 0
            ],
            'status' => 'draft'
        ];
    }

    /**
     * Validate and retrieve the budget task
     */
    private function validateAndGetBudgetTask(int $taskId): EnquiryTask
    {
        $budgetTask = EnquiryTask::find($taskId);

        if (!$budgetTask) {
            \Log::warning("Budget task not found: {$taskId}", [
                'task_id' => $taskId,
                'error_type' => 'task_not_found'
            ]);
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Budget task not found");
        }

        if ($budgetTask->type !== 'budget') {
            \Log::warning("Task {$taskId} is not a budget task", [
                'task_id' => $taskId,
                'task_type' => $budgetTask->type,
                'expected_type' => 'budget'
            ]);
            throw new \InvalidArgumentException("Task is not a budget task");
        }

        \Log::info("Validated budget task: {$budgetTask->id} - {$budgetTask->title}", [
            'budget_task_id' => $budgetTask->id,
            'budget_task_title' => $budgetTask->title,
            'enquiry_id' => $budgetTask->project_enquiry_id
        ]);

        return $budgetTask;
    }

    /**
     * Find materials task for the given enquiry
     */
    private function findMaterialsTaskForEnquiry(int $enquiryId): EnquiryTask
    {
        $materialsTask = EnquiryTask::where('project_enquiry_id', $enquiryId)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) {
            \Log::warning("Materials task not found for enquiry ID: {$enquiryId}", [
                'enquiry_id' => $enquiryId,
                'error_type' => 'materials_task_not_found'
            ]);
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Materials task not found for this enquiry");
        }

        \Log::info("Found materials task: {$materialsTask->id} - {$materialsTask->title}", [
            'materials_task_id' => $materialsTask->id,
            'materials_task_title' => $materialsTask->title,
            'enquiry_id' => $enquiryId
        ]);

        return $materialsTask;
    }

    /**
     * Get and validate materials data
     */
    private function getValidatedMaterialsData(int $materialsTaskId): TaskMaterialsData
    {
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTaskId)
            ->with(['elements.materials'])
            ->first();

        if (!$materialsData) {
            \Log::warning("No materials data found for materials task ID: {$materialsTaskId}", [
                'materials_task_id' => $materialsTaskId,
                'error_type' => 'no_materials_data'
            ]);
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("No materials data found to import");
        }

        // Validate that materials data has elements
        if ($materialsData->elements->isEmpty()) {
            \Log::warning("Materials data has no elements for task ID: {$materialsTaskId}", [
                'materials_task_id' => $materialsTaskId,
                'error_type' => 'empty_materials_data'
            ]);
            throw new \InvalidArgumentException("Materials data contains no elements");
        }

        \Log::info("Validated materials data with {$materialsData->elements->count()} elements", [
            'materials_task_id' => $materialsTaskId,
            'elements_count' => $materialsData->elements->count(),
            'materials_data_id' => $materialsData->id
        ]);

        return $materialsData;
    }

    /**
     * Resolve conflicts and merge budget data
     */
    private function resolveBudgetDataConflicts(int $budgetTaskId, array $formattedMaterials, EnquiryTask $materialsTask, TaskMaterialsData $materialsData): TaskBudgetData
    {
        $existingBudgetData = TaskBudgetData::where('enquiry_task_id', $budgetTaskId)->first();

        \Log::info("Analyzing existing budget data for conflict resolution", [
            'budget_task_id' => $budgetTaskId,
            'existing_budget_data_id' => $existingBudgetData ? $existingBudgetData->id : null,
            'has_existing_materials' => $existingBudgetData ? !empty($existingBudgetData->materials_data) : false,
            'materials_manually_modified' => $existingBudgetData ? $existingBudgetData->materials_manually_modified : false
        ]);

        // Determine if we should preserve existing materials data
        $shouldPreserveExistingMaterials = $existingBudgetData &&
            $existingBudgetData->materials_manually_modified &&
            !empty($existingBudgetData->materials_data);

        if ($shouldPreserveExistingMaterials) {
            \Log::info("Preserving existing manually modified materials data", [
                'budget_task_id' => $budgetTaskId,
                'reason' => 'materials_manually_modified_flag_set'
            ]);

            // Only update import metadata, don't replace materials
            $updateData = [
                'materials_imported_at' => now(),
                'materials_imported_from_task' => $materialsTask->id,
                'materials_import_metadata' => [
                    'imported_at' => now()->toISOString(),
                    'materials_task_id' => $materialsTask->id,
                    'materials_task_title' => $materialsTask->title,
                    'total_elements' => $materialsData->elements->count(),
                    'total_materials' => $materialsData->elements->sum(function ($element) {
                        return $element->materials->count();
                    }),
                    'preserved_existing_data' => true,
                    'reason' => 'materials_manually_modified'
                ],
                'updated_at' => now()
            ];
        } else {
            \Log::info("Importing new materials data", [
                'budget_task_id' => $budgetTaskId,
                'reason' => $existingBudgetData ? 'no_manual_modifications' : 'new_budget_data',
                'materials_count' => count($formattedMaterials)
            ]);

            // Prepare full update data
            $updateData = [
                'materials_data' => $formattedMaterials,
                'materials_imported_at' => now(),
                'materials_imported_from_task' => $materialsTask->id,
                'materials_manually_modified' => false,
                'materials_import_metadata' => [
                    'imported_at' => now()->toISOString(),
                    'materials_task_id' => $materialsTask->id,
                    'materials_task_title' => $materialsTask->title,
                    'total_elements' => $materialsData->elements->count(),
                    'total_materials' => $materialsData->elements->sum(function ($element) {
                        return $element->materials->count();
                    })
                ],
                'labour_data' => $existingBudgetData ? $existingBudgetData->labour_data : [],
                'expenses_data' => $existingBudgetData ? $existingBudgetData->expenses_data : [],
                'logistics_data' => $existingBudgetData ? $existingBudgetData->logistics_data : [],
                'budget_summary' => $existingBudgetData ? $existingBudgetData->budget_summary : [
                    'materialsTotal' => 0,
                    'labourTotal' => 0,
                    'expensesTotal' => 0,
                    'logisticsTotal' => 0,
                    'grandTotal' => 0
                ],
                'status' => $existingBudgetData ? $existingBudgetData->status : 'draft',
                'updated_at' => now()
            ];

            // Only set project_info if it doesn't already exist
            if (!$existingBudgetData || !$existingBudgetData->project_info) {
                $projectInfo = $this->getDefaultBudgetStructure($budgetTaskId)['projectInfo'];
                $updateData['project_info'] = $projectInfo;
                \Log::info("Setting project_info for budget data", [
                    'budget_task_id' => $budgetTaskId,
                    'project_info_set' => true
                ]);
            }
        }

        // Update or create budget data
        $budgetData = TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $budgetTaskId],
            $updateData
        );

        \Log::info("Budget data resolved and saved", [
            'budget_task_id' => $budgetTaskId,
            'budget_data_id' => $budgetData->id,
            'operation' => $existingBudgetData ? 'updated' : 'created',
            'materials_imported' => !$shouldPreserveExistingMaterials,
            'preserved_existing' => $shouldPreserveExistingMaterials
        ]);

        return $budgetData;
    }

    /**
     * Log successful import operation
     */
    private function logImportSuccess(int $taskId, TaskBudgetData $budgetData, EnquiryTask $materialsTask, TaskMaterialsData $materialsData): void
    {
        \Log::info("Materials import completed successfully", [
            'budget_task_id' => $taskId,
            'budget_data_id' => $budgetData->id,
            'materials_task_id' => $materialsTask->id,
            'materials_data_id' => $materialsData->id,
            'total_elements' => $materialsData->elements->count(),
            'total_materials' => $materialsData->elements->sum(function ($element) {
                return $element->materials->count();
            }),
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Handle validation errors during import
     */
    private function handleImportValidationError(\Exception $e, int $taskId): JsonResponse
    {
        $errorType = $this->getErrorType($e);

        \Log::warning("Import validation error for task {$taskId}: " . $e->getMessage(), [
            'task_id' => $taskId,
            'error_type' => $errorType,
            'error_message' => $e->getMessage()
        ]);

        $statusCode = $this->getStatusCodeForError($errorType);

        return response()->json([
            'message' => $e->getMessage(),
            'error_type' => $errorType
        ], $statusCode);
    }

    /**
     * Handle general import errors
     */
    private function handleImportError(\Exception $e, int $taskId): JsonResponse
    {
        \Log::error("Import failed for task {$taskId}: " . $e->getMessage(), [
            'task_id' => $taskId,
            'error_message' => $e->getMessage(),
            'error_line' => $e->getLine(),
            'error_file' => $e->getFile(),
            'stack_trace' => $e->getTraceAsString(),
            'timestamp' => now()->toISOString()
        ]);

        return response()->json([
            'message' => 'Failed to import materials',
            'error' => $e->getMessage(),
            'task_id' => $taskId
        ], 500);
    }

    /**
     * Get error type from exception
     */
    private function getErrorType(\Exception $e): string
    {
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            if (str_contains($e->getMessage(), 'Budget task not found')) {
                return 'budget_task_not_found';
            }
            if (str_contains($e->getMessage(), 'Materials task not found')) {
                return 'materials_task_not_found';
            }
            if (str_contains($e->getMessage(), 'No materials data found')) {
                return 'no_materials_data';
            }
        }

        if ($e instanceof \InvalidArgumentException) {
            if (str_contains($e->getMessage(), 'not a budget task')) {
                return 'invalid_task_type';
            }
            if (str_contains($e->getMessage(), 'no elements')) {
                return 'empty_materials_data';
            }
        }

        return 'unknown_validation_error';
    }

    /**
     * Validate business logic rules for budget data
     */
    private function validateBudgetBusinessLogic(array $data): array
    {
        $errors = [];

        // Get approved budget additions for this task
        $approvedAdditionsTotal = $this->getApprovedAdditionsTotal($data['taskId'] ?? null);

        // Validate materials totals match calculated values
        $calculatedMaterialsTotal = 0;
        foreach ($data['materials'] ?? [] as $element) {
            foreach ($element['materials'] ?? [] as $material) {
                if ($material['isIncluded']) {
                    $expectedTotal = round($material['quantity'] * $material['unitPrice'], 2);
                    if (abs($material['totalPrice'] - $expectedTotal) > 0.01) {
                        $errors['materials_calculation'] = 'Material total prices do not match quantity × unit price calculations';
                        break 2;
                    }
                    $calculatedMaterialsTotal += $material['totalPrice'];
                }
            }
        }

        // Add approved additions to materials total for validation
        $totalMaterialsWithAdditions = $calculatedMaterialsTotal + $approvedAdditionsTotal['materials'];

        if (abs($data['budgetSummary']['materialsTotal'] - $totalMaterialsWithAdditions) > 0.01) {
            $errors['materials_total_mismatch'] = 'Materials total does not match sum of individual material totals plus approved additions';
        }

        // Validate labour totals match calculated values
        $calculatedLabourTotal = 0;
        foreach ($data['labour'] ?? [] as $labour) {
            $expectedAmount = round($labour['quantity'] * $labour['unitRate'], 2);
            if (abs($labour['amount'] - $expectedAmount) > 0.01) {
                $errors['labour_calculation'] = 'Labour amounts do not match quantity × unit rate calculations';
                break;
            }
            $calculatedLabourTotal += $labour['amount'];
        }

        if (abs($data['budgetSummary']['labourTotal'] - $calculatedLabourTotal) > 0.01) {
            $errors['labour_total_mismatch'] = 'Labour total does not match sum of individual labour amounts';
        }

        // Validate expenses total
        $calculatedExpensesTotal = array_sum(array_column($data['expenses'] ?? [], 'amount'));
        if (abs($data['budgetSummary']['expensesTotal'] - $calculatedExpensesTotal) > 0.01) {
            $errors['expenses_total_mismatch'] = 'Expenses total does not match sum of individual expense amounts';
        }

        // Validate logistics totals match calculated values
        $calculatedLogisticsTotal = 0;
        foreach ($data['logistics'] ?? [] as $item) {
            $expectedAmount = round($item['quantity'] * $item['unitRate'], 2);
            if (abs($item['amount'] - $expectedAmount) > 0.01) {
                $errors['logistics_calculation'] = 'Logistics amounts do not match quantity × unit rate calculations';
                break;
            }
            $calculatedLogisticsTotal += $item['amount'];
        }

        if (abs($data['budgetSummary']['logisticsTotal'] - $calculatedLogisticsTotal) > 0.01) {
            $errors['logistics_total_mismatch'] = 'Logistics total does not match sum of individual logistics amounts';
        }

        // Validate grand total (including approved additions)
        $expectedGrandTotal = $data['budgetSummary']['materialsTotal'] +
                              $data['budgetSummary']['labourTotal'] +
                              $data['budgetSummary']['expensesTotal'] +
                              $data['budgetSummary']['logisticsTotal'];

        // Add all approved additions to expected grand total
        $totalApprovedAdditions = $approvedAdditionsTotal['materials'] +
                                  $approvedAdditionsTotal['labour'] +
                                  $approvedAdditionsTotal['expenses'] +
                                  $approvedAdditionsTotal['logistics'];

        $expectedGrandTotalWithAdditions = $expectedGrandTotal + $totalApprovedAdditions;

        if (abs($data['budgetSummary']['grandTotal'] - $expectedGrandTotalWithAdditions) > 0.01) {
            $errors['grand_total_mismatch'] = 'Grand total does not match sum of all category totals plus approved additions';
        }

        // Validate budget status transitions
        if (isset($data['status'])) {
            $existingBudget = TaskBudgetData::where('enquiry_task_id', $data['taskId'] ?? null)->first();
            $currentStatus = $existingBudget ? $existingBudget->status : 'draft';

            $validTransitions = [
                'draft' => ['pending_approval'],
                'pending_approval' => ['approved', 'rejected', 'draft', 'pending_approval'], // Allow resubmission
                'approved' => [], // Final state
                'rejected' => ['draft'] // Can resubmit
            ];

            if (!in_array($data['status'], $validTransitions[$currentStatus] ?? [])) {
                $errors['invalid_status_transition'] = "Cannot change status from {$currentStatus} to {$data['status']}";
            }
        }

        return $errors;
    }

    /**
     * Get HTTP status code for error type
     */
    private function getStatusCodeForError(string $errorType): int
    {
        return match ($errorType) {
            'budget_task_not_found', 'materials_task_not_found', 'no_materials_data' => 404,
            'invalid_task_type', 'empty_materials_data' => 422,
            default => 400
        };
    }

    /**
     * Get total amounts from approved budget additions for a task
     */
    private function getApprovedAdditionsTotal(?int $taskId): array
    {
        if (!$taskId) {
            return [
                'materials' => 0,
                'labour' => 0,
                'expenses' => 0,
                'logistics' => 0
            ];
        }

        try {
            // Get the budget data for this task to find the budget ID
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return [
                    'materials' => 0,
                    'labour' => 0,
                    'expenses' => 0,
                    'logistics' => 0
                ];
            }

            // Get approved additions for this budget
            $approvedAdditions = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)
                ->where('status', 'approved')
                ->get();

            $totals = [
                'materials' => 0,
                'labour' => 0,
                'expenses' => 0,
                'logistics' => 0
            ];

            foreach ($approvedAdditions as $addition) {
                // Sum materials
                if ($addition->materials) {
                    foreach ($addition->materials as $material) {
                        $totals['materials'] += $material['totalPrice'] ?? 0;
                    }
                }

                // Sum labour
                if ($addition->labour) {
                    foreach ($addition->labour as $labour) {
                        $totals['labour'] += $labour['amount'] ?? 0;
                    }
                }

                // Sum expenses
                if ($addition->expenses) {
                    foreach ($addition->expenses as $expense) {
                        $totals['expenses'] += $expense['amount'] ?? 0;
                    }
                }

                // Sum logistics
                if ($addition->logistics) {
                    foreach ($addition->logistics as $item) {
                        $totals['logistics'] += $item['amount'] ?? 0;
                    }
                }
            }

            return $totals;

        } catch (\Exception $e) {
            \Log::error('Failed to calculate approved additions total', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            return [
                'materials' => 0,
                'labour' => 0,
                'expenses' => 0,
                'logistics' => 0
            ];
        }
    }

    /**
     * Format materials data for budget structure
     */
    private function formatMaterialsForBudget(TaskMaterialsData $materialsData): array
    {
        $formattedMaterials = [];

        foreach ($materialsData->elements as $element) {
            // Skip elements that are not included
            if (!$element->is_included) {
                continue;
            }

            $elementMaterials = [];

            foreach ($element->materials as $material) {
                // Skip materials that are not included
                if (!$material->is_included) {
                    continue;
                }

                $elementMaterials[] = [
                    'id' => (string) $material->id,
                    'description' => $material->description,
                    'unitOfMeasurement' => $material->unit_of_measurement,
                    'quantity' => (float) $material->quantity,
                    'isIncluded' => true, // Already filtered
                    'unitPrice' => 0.0, // To be filled by budget user
                    'totalPrice' => 0.0, // Calculated: quantity * unitPrice
                    'isAddition' => false,
                    'notes' => $material->notes,
                    'category' => $element->category
                ];
            }

            // Only add element if it has materials
            if (!empty($elementMaterials)) {
                $formattedMaterials[] = [
                    'id' => (string) $element->id,
                    'elementType' => $element->element_type,
                    'name' => $element->name,
                    'category' => $element->category,
                    'materials' => $elementMaterials,
                    'isIncluded' => true, // Already filtered
                    'notes' => $element->notes
                ];
            }
        }

        \Log::info("Formatted materials for budget", [
            'original_elements' => $materialsData->elements->count(),
            'formatted_elements' => count($formattedMaterials),
            'total_materials' => array_sum(array_map(function ($element) {
                return count($element['materials']);
            }, $formattedMaterials))
        ]);

        return $formattedMaterials;
    }
}
