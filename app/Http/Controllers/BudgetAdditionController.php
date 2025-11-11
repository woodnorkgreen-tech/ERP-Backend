<?php

namespace App\Http\Controllers;

use App\Models\BudgetAddition;
use App\Models\TaskBudgetData;
use App\Services\BudgetAdditionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BudgetAdditionController extends Controller
{
    protected $budgetAdditionService;

    public function __construct(BudgetAdditionService $budgetAdditionService)
    {
        $this->budgetAdditionService = $budgetAdditionService;
        // No permission restrictions for budget additions (for now)
    }

    /**
     * Get budget additions for a specific task
     */
    public function index(int $taskId): JsonResponse
    {
        try {
            $additions = $this->budgetAdditionService->getForTask($taskId);

            return response()->json([
                'data' => $additions,
                'message' => 'Budget additions retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve budget additions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new budget addition
     */
    public function store(Request $request, int $taskId): JsonResponse
    {
        try {
            $addition = $this->budgetAdditionService->create($taskId, $request->all());

            return response()->json([
                'data' => $addition,
                'message' => 'Budget addition created successfully'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create budget addition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific budget addition
     */
    public function show(int $taskId, int $additionId): JsonResponse
    {
        try {
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)
                ->with(['creator', 'approver', 'budget'])
                ->findOrFail($additionId);

            return response()->json([
                'data' => $addition,
                'message' => 'Budget addition retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Budget addition not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update a budget addition
     */
    public function update(Request $request, int $taskId, string $additionId): JsonResponse
    {
        \Log::info('BudgetAdditionController::update - Starting', [
            'taskId' => $taskId,
            'additionId' => $additionId,
            'requestData' => $request->all(),
            'userId' => auth()->id()
        ]);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'materials' => 'sometimes|array',
            'labour' => 'sometimes|array',
            'expenses' => 'sometimes|array',
            'logistics' => 'sometimes|array',
            'status' => 'sometimes|in:draft,pending_approval,approved,rejected'
        ]);

        if ($validator->fails()) {
            \Log::warning('BudgetAdditionController::update - Validation failed', [
                'errors' => $validator->errors()
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle virtual additions (from materials task) - create draft record if editing
            if (str_starts_with($additionId, 'materials_additional_')) {
                \Log::info('BudgetAdditionController::update - Handling virtual addition', [
                    'additionId' => $additionId,
                    'additionIdLength' => strlen($additionId)
                ]);
                return $this->updateVirtualAddition($request, $taskId, $additionId);
            }

            \Log::info('BudgetAdditionController::update - Handling regular database addition');

            // Handle regular database additions
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                \Log::warning('BudgetAdditionController::update - Budget data not found', [
                    'taskId' => $taskId
                ]);
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            \Log::info('BudgetAdditionController::update - Budget data found', [
                'budgetDataId' => $budgetData->id
            ]);

            $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

            \Log::info('BudgetAdditionController::update - Addition found', [
                'additionId' => $addition->id,
                'currentStatus' => $addition->status
            ]);

            $updateData = $request->only([
                'title', 'description', 'materials', 'labour', 'expenses', 'logistics', 'status'
            ]);

            \Log::info('BudgetAdditionController::update - Updating addition', [
                'updateData' => $updateData
            ]);

            $addition->update($updateData);

            \Log::info('BudgetAdditionController::update - Addition updated successfully');

            $freshAddition = $addition->fresh(['creator', 'approver']);

            \Log::info('BudgetAdditionController::update - Fresh addition loaded', [
                'freshAdditionId' => $freshAddition->id
            ]);

            return response()->json([
                'data' => $freshAddition,
                'message' => 'Budget addition updated successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('BudgetAdditionController::update - Exception occurred', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update budget addition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve or reject a budget addition
     */
    public function approve(Request $request, int $taskId, string $additionId): JsonResponse
    {
        \Log::info('BudgetAdditionController::approve - Request received', [
            'taskId' => $taskId,
            'additionId' => $additionId,
            'action' => $request->action,
            'user' => auth()->id(),
            'requestData' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            \Log::warning('BudgetAdditionController::approve - Validation failed', [
                'errors' => $validator->errors(),
                'taskId' => $taskId,
                'additionId' => $additionId
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            \Log::info('BudgetAdditionController::approve - Processing approval', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'action' => $request->action,
                'isVirtualAddition' => str_starts_with($additionId, 'materials_additional_')
            ]);

            if ($request->action === 'approve') {
                $addition = $this->budgetAdditionService->approve($taskId, $additionId, $request->notes);
                $message = 'Budget addition approved successfully';
            } else {
                $addition = $this->budgetAdditionService->reject($taskId, $additionId, $request->notes);
                $message = 'Budget addition rejected';
            }

            \Log::info('BudgetAdditionController::approve - Approval processed successfully', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'action' => $request->action,
                'returnedAdditionId' => $addition->id ?? null,
                'returnedAdditionStatus' => $addition->status ?? null
            ]);

            return response()->json([
                'data' => $addition,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            \Log::error('BudgetAdditionController::approve - Approval failed', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'action' => $request->action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to process approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create budget addition from materials task material
     */
    public function createFromMaterial(Request $request, int $taskId): JsonResponse
    {
        \Log::info('BudgetAdditionController::createFromMaterial called', [
            'taskId' => $taskId,
            'request_data' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'material' => 'required|array',
            'material.id' => 'required|string',
            'material.description' => 'required|string',
            'material.unitOfMeasurement' => 'required|string',
            'material.quantity' => 'required|numeric|min:0',
            'budget_type' => 'required|in:main,supplementary'
        ]);

        if ($validator->fails()) {
            \Log::warning('BudgetAdditionController::createFromMaterial validation failed', [
                'errors' => $validator->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            \Log::info('BudgetAdditionController::createFromMaterial calling service', [
                'taskId' => $taskId,
                'material' => $request->material,
                'budget_type' => $request->budget_type
            ]);

            $addition = $this->budgetAdditionService->createFromMaterial(
                $taskId,
                $request->material,
                $request->budget_type
            );

            \Log::info('BudgetAdditionController::createFromMaterial success', [
                'addition_id' => $addition->id,
                'taskId' => $taskId
            ]);

            return response()->json([
                'data' => $addition,
                'message' => 'Budget addition created from material successfully'
            ], 201);

        } catch (\Exception $e) {
            \Log::error('BudgetAdditionController::createFromMaterial failed', [
                'taskId' => $taskId,
                'material' => $request->material,
                'budget_type' => $request->budget_type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create budget addition from material',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a budget addition
     */
    public function destroy(int $taskId, string $additionId): JsonResponse
    {
        try {
            // Handle virtual additions - they can't be deleted directly
            if (str_starts_with($additionId, 'materials_additional_')) {
                return response()->json([
                    'message' => 'Cannot delete auto-detected additions. You can only approve or reject them.'
                ], 422);
            }

            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

            // Only allow deletion of draft additions
            if ($addition->status !== 'draft') {
                return response()->json([
                    'message' => 'Cannot delete addition that has been submitted for approval'
                ], 422);
            }

            $addition->delete();

            return response()->json([
                'message' => 'Budget addition deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete budget addition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a virtual addition by creating a draft record
     */
    private function updateVirtualAddition(Request $request, int $taskId, string $additionId): JsonResponse
    {
        \Log::info('BudgetAdditionController::updateVirtualAddition - Starting', [
            'taskId' => $taskId,
            'additionId' => $additionId,
            'additionIdLength' => strlen($additionId),
            'requestData' => $request->all(),
            'userId' => Auth::id()
        ]);

        try {
            \Log::info('BudgetAdditionController::updateVirtualAddition - Getting budget data');
            $budgetData = $this->budgetAdditionService->getOrCreateBudgetData($taskId);
            \Log::info('BudgetAdditionController::updateVirtualAddition - Budget data retrieved', [
                'budgetDataId' => $budgetData->id
            ]);

            \Log::info('BudgetAdditionController::updateVirtualAddition - Getting materials additions');
            // Get the virtual addition data
            $materialsAdditions = $this->budgetAdditionService->getMaterialsAdditions($budgetData->id);
            $virtualAddition = collect($materialsAdditions)->firstWhere('id', $additionId);
            \Log::info('BudgetAdditionController::updateVirtualAddition - Virtual addition lookup result', [
                'additionId' => $additionId,
                'virtualAdditionFound' => $virtualAddition ? true : false,
                'materialsAdditionsCount' => count($materialsAdditions)
            ]);

            if (!$virtualAddition) {
                \Log::warning('BudgetAdditionController::updateVirtualAddition - Virtual addition not found', [
                    'additionId' => $additionId,
                    'availableIds' => collect($materialsAdditions)->pluck('id')->toArray()
                ]);
                return response()->json(['message' => 'Virtual addition not found'], 404);
            }

            // Extract material ID from virtual addition ID
            $materialId = str_replace('materials_additional_', '', $additionId);
            \Log::info('BudgetAdditionController::updateVirtualAddition - Extracted material ID', [
                'additionId' => $additionId,
                'materialId' => $materialId
            ]);

            if (empty($materialId) || !is_numeric($materialId)) {
                \Log::error('BudgetAdditionController::updateVirtualAddition - Material ID is empty or invalid', [
                    'additionId' => $additionId,
                    'materialId' => $materialId
                ]);
                return response()->json(['message' => 'Invalid virtual addition ID'], 400);
            }

            $materialId = (int) $materialId;

            \Log::info('BudgetAdditionController::updateVirtualAddition - Checking for existing draft');
            // Check if a draft record already exists for this material
            $existingDraft = BudgetAddition::where('task_budget_data_id', $budgetData->id)
                ->where('source_type', 'materials_additional')
                ->where('source_material_id', $materialId)
                ->where('status', 'draft')
                ->first();
            \Log::info('BudgetAdditionController::updateVirtualAddition - Existing draft check result', [
                'existingDraftFound' => $existingDraft ? true : false,
                'existingDraftId' => $existingDraft ? $existingDraft->id : null
            ]);

            if ($existingDraft) {
                \Log::info('BudgetAdditionController::updateVirtualAddition - Updating existing draft');
                // Prepare updated data
                $updatedData = [
                    'title' => $request->title ?? $existingDraft->title,
                    'description' => $request->description ?? $existingDraft->description,
                    'materials' => $request->materials ?? $existingDraft->materials,
                    'labour' => $request->labour ?? $existingDraft->labour,
                    'expenses' => $request->expenses ?? $existingDraft->expenses,
                    'logistics' => $request->logistics ?? $existingDraft->logistics,
                ];
                \Log::info('BudgetAdditionController::updateVirtualAddition - Updated data prepared', [
                    'updatedData' => $updatedData
                ]);

                // Update existing draft
                $existingDraft->update($updatedData);
                \Log::info('BudgetAdditionController::updateVirtualAddition - Existing draft updated');

                // Recalculate total amount using the updated data directly
                $totalAmount = $this->budgetAdditionService->calculateTotalAmount($updatedData);
                $existingDraft->update(['total_amount' => $totalAmount]);
                \Log::info('BudgetAdditionController::updateVirtualAddition - Total amount recalculated', [
                    'totalAmount' => $totalAmount
                ]);

                return response()->json([
                    'data' => $existingDraft->fresh(['creator', 'approver']),
                    'message' => 'Virtual addition updated successfully'
                ]);
            } else {
                \Log::info('BudgetAdditionController::updateVirtualAddition - Creating new draft record');
                // Create new draft record
                try {
                    $totalAmount = $this->budgetAdditionService->calculateTotalAmount($request->all());
                    $totalAmount = is_numeric($totalAmount) ? (float) $totalAmount : 0.0;
                    \Log::info('BudgetAdditionController::updateVirtualAddition - Total amount calculated for new record', [
                        'totalAmount' => $totalAmount
                    ]);
                } catch (\Exception $e) {
                    \Log::error('BudgetAdditionController::updateVirtualAddition - Failed to calculate total amount', [
                        'error' => $e->getMessage(),
                        'requestData' => $request->all()
                    ]);
                    $totalAmount = 0.0;
                }

                // Validate and sanitize the materials data
                $materials = $request->materials ?? $virtualAddition['materials'] ?? [];
                if (!is_array($materials)) {
                    $materials = [];
                }

                // Ensure materials have required fields and proper types
                $sanitizedMaterials = [];
                foreach ($materials as $material) {
                    if (!is_array($material)) continue;

                    $sanitizedMaterials[] = [
                        'id' => isset($material['id']) ? (string) $material['id'] : null,
                        'description' => isset($material['description']) ? (string) $material['description'] : '',
                        'quantity' => isset($material['quantity']) ? (float) $material['quantity'] : 0,
                        'unit_price' => isset($material['unit_price']) ? (float) $material['unit_price'] : 0,
                        'total_price' => isset($material['total_price']) ? (float) $material['total_price'] : 0,
                    ];
                }

                $createData = [
                    'task_budget_data_id' => $budgetData->id,
                    'title' => $request->title ?? $virtualAddition['title'] ?? '',
                    'description' => $request->description ?? $virtualAddition['description'] ?? null,
                    'materials' => $sanitizedMaterials,
                    'labour' => $request->labour ?? $virtualAddition['labour'] ?? [],
                    'expenses' => $request->expenses ?? $virtualAddition['expenses'] ?? [],
                    'logistics' => $request->logistics ?? $virtualAddition['logistics'] ?? [],
                    'status' => 'draft',
                    'budget_type' => 'supplementary',
                    'source_type' => 'materials_additional',
                    'source_material_id' => $materialId,
                    'total_amount' => $totalAmount,
                    'created_by' => Auth::id(),
                ];

                \Log::info('BudgetAdditionController::updateVirtualAddition - Creating new BudgetAddition', [
                    'createData' => $createData,
                    'authId' => Auth::id(),
                    'authCheck' => Auth::check(),
                    'user' => Auth::user() ? Auth::user()->only(['id', 'name', 'email']) : null
                ]);

                if (!Auth::id()) {
                    \Log::error('BudgetAdditionController::updateVirtualAddition - Auth::id() is null!');
                    throw new \Exception('User not authenticated');
                }

                try {
                    $newAddition = BudgetAddition::create($createData);
                    \Log::info('BudgetAdditionController::updateVirtualAddition - BudgetAddition created successfully', [
                        'newAdditionId' => $newAddition->id
                    ]);
                } catch (\Exception $e) {
                    \Log::error('BudgetAdditionController::updateVirtualAddition - Failed to create BudgetAddition', [
                        'createData' => $createData,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
                \Log::info('BudgetAdditionController::updateVirtualAddition - New draft record created', [
                    'newAdditionId' => $newAddition->id
                ]);

                return response()->json([
                    'data' => $newAddition->load(['creator', 'approver']),
                    'message' => 'Virtual addition updated successfully'
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('BudgetAdditionController::updateVirtualAddition - Exception occurred', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update virtual addition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
