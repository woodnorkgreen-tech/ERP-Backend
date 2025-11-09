<?php

namespace App\Http\Controllers;

use App\Models\BudgetAddition;
use App\Models\TaskBudgetData;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class BudgetAdditionController extends Controller
{
    public function __construct()
    {
        // No permission restrictions for budget additions (for now)
    }

    /**
     * Get budget additions for a specific task
     */
    public function index(int $taskId): JsonResponse
    {
        try {
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json([
                    'data' => [],
                    'message' => 'Budget additions retrieved successfully'
                ]);
            }

            // First, get manually created additions
            $manualAdditions = BudgetAddition::where('task_budget_data_id', $budgetData->id)
                ->with(['creator', 'approver'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Then, get materials marked as "additionals" from the materials task
            $materialsAdditions = $this->getMaterialsAdditions($budgetData->id);

            // Filter out materials additions that have already been processed (approved or rejected)
            // to avoid showing duplicates
            $filteredMaterialsAdditions = collect($materialsAdditions)->filter(function ($materialsAddition) use ($manualAdditions) {
                // Check if there's already a database record for this materials addition (approved or rejected)
                $existingProcessed = $manualAdditions->first(function ($manualAddition) use ($materialsAddition) {
                    // Extract the material ID from the virtual addition ID
                    $virtualMaterialId = str_replace('materials_additional_', '', $materialsAddition['id']);

                    // Check if this database addition contains the same material
                    $dbMaterials = $manualAddition->materials ?? [];
                    foreach ($dbMaterials as $dbMaterial) {
                        if (isset($dbMaterial['id']) && $dbMaterial['id'] == $virtualMaterialId) {
                            // Found matching material - check if it's approved/rejected
                            return in_array($manualAddition->status, ['approved', 'rejected']);
                        }
                    }

                    // Also check by title and description to catch edge cases
                    if ($manualAddition->title === $materialsAddition['title'] &&
                        $manualAddition->description === $materialsAddition['description'] &&
                        in_array($manualAddition->status, ['approved', 'rejected'])) {
                        return true;
                    }

                    return false;
                });

                // Only include if no processed database record exists for this material
                return !$existingProcessed;
            })->values();

            // Combine both types of additions and remove any remaining duplicates
            $allAdditions = collect($filteredMaterialsAdditions)->merge($manualAdditions)
                ->unique(function ($addition) {
                    // Create a unique key based on title, description, and status
                    // For virtual additions, use the material ID as additional uniqueness
                    $key = $addition['title'] . '|' . ($addition['description'] ?? '') . '|' . $addition['status'];

                    // For materials additions, include the material ID to ensure uniqueness
                    if (isset($addition['is_materials_additional']) && $addition['is_materials_additional']) {
                        $materialId = str_replace('materials_additional_', '', $addition['id']);
                        $key .= '|' . $materialId;
                    }

                    return $key;
                })
                ->sortByDesc('created_at')
                ->values();

            return response()->json([
                'data' => $allAdditions,
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
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'materials' => 'nullable|array',
            'labour' => 'nullable|array',
            'expenses' => 'nullable|array',
            'logistics' => 'nullable|array',
            'status' => 'sometimes|in:draft,pending_approval'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            $addition = BudgetAddition::create([
                'task_budget_data_id' => $budgetData->id,
                'title' => $request->title,
                'description' => $request->description,
                'materials' => $request->materials ?? [],
                'labour' => $request->labour ?? [],
                'expenses' => $request->expenses ?? [],
                'logistics' => $request->logistics ?? [],
                'status' => $request->status ?? 'draft',
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'data' => $addition->load(['creator', 'approver']),
                'message' => 'Budget addition created successfully'
            ], 201);

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
    public function update(Request $request, int $taskId, int $additionId): JsonResponse
    {
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
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

            $addition->update($request->only([
                'title', 'description', 'materials', 'labour', 'expenses', 'logistics', 'status'
            ]));

            return response()->json([
                'data' => $addition->fresh(['creator', 'approver']),
                'message' => 'Budget addition updated successfully'
            ]);

        } catch (\Exception $e) {
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
        \Log::info('Budget addition approval request', [
            'taskId' => $taskId,
            'additionId' => $additionId,
            'action' => $request->action,
            'user' => auth()->id()
        ]);

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            \Log::warning('Budget addition approval validation failed', [
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
            \Log::info('Processing budget addition approval', [
                'taskId' => $taskId,
                'additionId' => $additionId,
                'action' => $request->action
            ]);
            // Handle virtual additions (from materials task) - they don't exist in DB yet
            if (str_starts_with($additionId, 'materials_additional_')) {
                \Log::info('Processing virtual materials addition', [
                    'additionId' => $additionId,
                    'action' => $request->action,
                    'taskId' => $taskId
                ]);

                if ($request->action === 'approve') {
                    // Find the budget data for this task
                    $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
                    if (!$budgetData) {
                        \Log::warning('Budget not found for task', ['taskId' => $taskId]);
                        return response()->json(['message' => 'Budget not found for this task'], 404);
                    }

                    // Create actual database record for approved virtual addition
                    $materialsAdditions = $this->getMaterialsAdditions($budgetData->id);
                    $virtualAddition = collect($materialsAdditions)->firstWhere('id', $additionId);

                    if (!$virtualAddition) {
                        \Log::warning('Virtual addition not found', ['additionId' => $additionId]);
                        return response()->json(['message' => 'Virtual addition not found'], 404);
                    }

                    \Log::info('Creating database record for approved virtual addition', [
                        'title' => $virtualAddition['title'],
                        'budgetId' => $budgetData->id
                    ]);

                    $newAddition = BudgetAddition::create([
                        'task_budget_data_id' => $budgetData->id,
                        'title' => $virtualAddition['title'],
                        'description' => $virtualAddition['description'],
                        'materials' => $virtualAddition['materials'],
                        'labour' => $virtualAddition['labour'] ?? [],
                        'expenses' => $virtualAddition['expenses'] ?? [],
                        'logistics' => $virtualAddition['logistics'] ?? [],
                        'status' => 'approved',
                        'created_by' => auth()->id(), // System generated
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                        'approval_notes' => $request->notes,
                    ]);

                    \Log::info('Virtual addition approved and database record created', [
                        'newAdditionId' => $newAddition->id
                    ]);

                    return response()->json([
                        'data' => $newAddition->load(['creator', 'approver']),
                        'message' => 'Budget addition approved successfully'
                    ]);
                } else {
                    // For rejection of virtual additions, we don't create a DB record
                    // Just return success - the virtual addition will be filtered out
                    \Log::info('Virtual addition rejected', ['additionId' => $additionId]);
                    return response()->json([
                        'data' => null,
                        'message' => 'Budget addition rejected'
                    ]);
                }
            }

            // Handle regular database additions
            // Find the budget data for this task
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'Budget not found for this task'], 404);
            }

            $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

            if ($request->action === 'approve') {
                $addition->approve(auth()->id(), $request->notes);
                $message = 'Budget addition approved successfully';
            } else {
                $addition->reject(auth()->id(), $request->notes);
                $message = 'Budget addition rejected';
            }

            return response()->json([
                'data' => $addition->fresh(['creator', 'approver']),
                'message' => $message
            ]);

        } catch (\Exception $e) {
            \Log::error('Budget addition approval failed', [
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
     * Delete a budget addition
     */
    public function destroy(int $taskId, int $additionId): JsonResponse
    {
        try {
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
     * Get materials marked as "additionals" from the materials task
     */
    private function getMaterialsAdditions(int $budgetId): array
    {
        try {
            // Get the budget data to find the enquiry
            $budgetData = \App\Models\TaskBudgetData::find($budgetId);
            if (!$budgetData) {
                return [];
            }

            // Find the materials task for this enquiry
            $materialsTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $budgetData->enquiry_task->project_enquiry_id)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                return [];
            }

            // Get materials data
            $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData) {
                return [];
            }

            $additions = [];

            foreach ($materialsData->elements as $element) {
                foreach ($element->materials as $material) {
                    // Check if this material is marked as an "additional"
                    // Look for the is_additional field in the material data
                    if ($material->is_additional) {
                        $additions[] = [
                            'id' => 'materials_additional_' . $material->id,
                            'title' => 'Additional: ' . $material->description,
                            'description' => 'Automatically created from Materials Task - Element: ' . $element->name,
                            'materials' => [
                                [
                                    'id' => (string) $material->id,
                                    'description' => $material->description,
                                    'unitOfMeasurement' => $material->unit_of_measurement,
                                    'quantity' => (float) $material->quantity,
                                    'unitPrice' => 0, // To be set in budget
                                    'totalPrice' => 0,
                                    'isAddition' => true
                                ]
                            ],
                            'labour' => [],
                            'expenses' => [],
                            'logistics' => [],
                            'status' => 'pending_approval', // Materials additions need approval
                            'created_by' => null, // System generated
                            'approved_by' => null,
                            'created_at' => $material->created_at,
                            'updated_at' => $material->updated_at,
                            'is_materials_additional' => true, // Flag to identify source
                            'source_element' => $element->name,
                            'source_task' => $materialsTask->title
                        ];
                    }
                }
            }

            return $additions;

        } catch (\Exception $e) {
            \Log::error('Failed to get materials additions: ' . $e->getMessage());
            return [];
        }
    }
}
