<?php

namespace App\Services;

use App\Models\BudgetAddition;
use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BudgetAdditionService
{
    /**
     * Get budget additions for a specific task
     */
    public function getForTask(int $taskId): array
    {
        // Find the budget data for this task
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        if (!$budgetData) {
            return [];
        }

        // First, get manually created additions
        $manualAdditions = BudgetAddition::where('task_budget_data_id', $budgetData->id)
            ->with(['creator', 'approver'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Then, get materials marked as "additionals" from the materials task
        $materialsAdditions = $this->getMaterialsAdditions($budgetData->id);

        // Filter out materials additions that have already been processed (approved or rejected)
        // but keep draft records for editing
        $filteredMaterialsAdditions = collect($materialsAdditions)->filter(function ($materialsAddition) use ($manualAdditions) {
            // Check if there's already a database record for this materials addition (approved or rejected)
            $existingProcessed = $manualAdditions->first(function ($manualAddition) use ($materialsAddition) {
                // Extract the material ID from the virtual addition ID
                $virtualMaterialId = str_replace('materials_additional_', '', $materialsAddition['id']);

                // Check if this database addition contains the same material
                $dbMaterials = $manualAddition->materials ?? [];
                foreach ($dbMaterials as $dbMaterial) {
                    if (isset($dbMaterial['id']) && $dbMaterial['id'] == $virtualMaterialId) {
                        // Found matching material - check if it's approved/rejected (keep drafts)
                        return in_array($manualAddition->status, ['approved', 'rejected']);
                    }
                }

                // Also check by source_material_id for materials_additional type
                if ($manualAddition->source_type === 'materials_additional' &&
                    $manualAddition->source_material_id == $virtualMaterialId &&
                    in_array($manualAddition->status, ['approved', 'rejected'])) {
                    return true;
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

        return $allAdditions->toArray();
    }

    /**
     * Create a new budget addition
     */
    public function create(int $taskId, array $data): BudgetAddition
    {
        \Log::info('BudgetAdditionService::create - Starting', [
            'taskId' => $taskId,
            'data' => $data,
            'userId' => Auth::id()
        ]);

        $this->validateCreateData($data);
        \Log::info('BudgetAdditionService::create - Validation passed');

        // Get or create the budget data for this task
        $budgetData = $this->getOrCreateBudgetData($taskId);
        \Log::info('BudgetAdditionService::create - Budget data retrieved', [
            'budgetDataId' => $budgetData->id
        ]);

        // Calculate total amount
        $totalAmount = $this->calculateTotalAmount($data);
        \Log::info('BudgetAdditionService::create - Total amount calculated', [
            'totalAmount' => $totalAmount
        ]);

        // Determine initial status based on budget type
        $status = $this->determineInitialStatus($data['budget_type'] ?? 'supplementary');
        \Log::info('BudgetAdditionService::create - Status determined', [
            'status' => $status,
            'budgetType' => $data['budget_type'] ?? 'supplementary'
        ]);

        $createData = [
            'task_budget_data_id' => $budgetData->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'materials' => $data['materials'] ?? [],
            'labour' => $data['labour'] ?? [],
            'expenses' => $data['expenses'] ?? [],
            'logistics' => $data['logistics'] ?? [],
            'status' => $status,
            'budget_type' => $data['budget_type'] ?? 'supplementary',
            'total_amount' => $totalAmount,
            'created_by' => Auth::id(),
            'approved_by' => $status === 'approved' ? Auth::id() : null,
            'approved_at' => $status === 'approved' ? now() : null,
        ];

        \Log::info('BudgetAdditionService::create - Attempting to create BudgetAddition', [
            'createData' => $createData
        ]);

        try {
            $addition = BudgetAddition::create($createData);
            \Log::info('BudgetAdditionService::create - BudgetAddition created successfully', [
                'additionId' => $addition->id
            ]);

            $loadedAddition = $addition->load(['creator', 'approver']);
            \Log::info('BudgetAdditionService::create - Relationships loaded successfully');

            return $loadedAddition;
        } catch (\Exception $e) {
            \Log::error('BudgetAdditionService::create - Failed to create BudgetAddition', [
                'createData' => $createData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Approve a budget addition
     */
    public function approve(int $taskId, string $additionId, ?string $notes = null): BudgetAddition
    {
        // Handle virtual additions (from materials task) - they don't exist in DB yet
        if (str_starts_with($additionId, 'materials_additional_')) {
            return $this->approveVirtualAddition($taskId, $additionId, $notes);
        }

        // Handle regular database additions
        $budgetData = $this->getOrCreateBudgetData($taskId);

        $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

        if (!$addition->approve(Auth::id(), $notes)) {
            throw new \Exception('Failed to approve budget addition');
        }

        return $addition->fresh(['creator', 'approver']);
    }

    /**
     * Reject a budget addition
     */
    public function reject(int $taskId, string $additionId, ?string $reason = null): BudgetAddition
    {
        // Handle virtual additions (from materials task) - they don't exist in DB yet
        if (str_starts_with($additionId, 'materials_additional_')) {
            return $this->rejectVirtualAddition($taskId, $additionId, $reason);
        }

        // Handle regular database additions
        $budgetData = $this->getOrCreateBudgetData($taskId);

        $addition = BudgetAddition::where('task_budget_data_id', $budgetData->id)->findOrFail($additionId);

        if (!$addition->reject(Auth::id(), $reason)) {
            throw new \Exception('Failed to reject budget addition');
        }

        return $addition->fresh(['creator', 'approver']);
    }

    /**
     * Validate data for creating a budget addition
     */
    private function validateCreateData(array $data): void
    {
        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'budget_type' => 'required|in:main,supplementary',
            'materials' => 'nullable|array',
            'materials.*.description' => 'required|string',
            'materials.*.quantity' => 'required|numeric|min:0',
            'materials.*.unit_price' => 'required|numeric|min:0',
            'materials.*.total_price' => 'required|numeric|min:0',
            'labour' => 'nullable|array',
            'labour.*.description' => 'required|string',
            'labour.*.quantity' => 'required|numeric|min:0',
            'labour.*.unit_price' => 'required|numeric|min:0',
            'labour.*.total_price' => 'required|numeric|min:0',
            'expenses' => 'nullable|array',
            'expenses.*.description' => 'required|string',
            'expenses.*.quantity' => 'required|numeric|min:0',
            'expenses.*.unit_price' => 'required|numeric|min:0',
            'expenses.*.total_price' => 'required|numeric|min:0',
            'logistics' => 'nullable|array',
            'logistics.*.description' => 'required|string',
            'logistics.*.quantity' => 'required|numeric|min:0',
            'logistics.*.unit_price' => 'required|numeric|min:0',
            'logistics.*.total_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Calculate total amount from all budget components
     */
    public function calculateTotalAmount(array $data): float
    {
        $total = 0;

        // Calculate materials total
        if (isset($data['materials']) && is_array($data['materials'])) {
            foreach ($data['materials'] as $material) {
                $total += (float) ($material['total_price'] ?? 0);
            }
        }

        // Calculate labour total
        if (isset($data['labour']) && is_array($data['labour'])) {
            foreach ($data['labour'] as $labour) {
                $total += (float) ($labour['total_price'] ?? 0);
            }
        }

        // Calculate expenses total
        if (isset($data['expenses']) && is_array($data['expenses'])) {
            foreach ($data['expenses'] as $expense) {
                $total += (float) ($expense['total_price'] ?? 0);
            }
        }

        // Calculate logistics total
        if (isset($data['logistics']) && is_array($data['logistics'])) {
            foreach ($data['logistics'] as $logistic) {
                $total += (float) ($logistic['total_price'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Determine initial status based on budget type
     */
    private function determineInitialStatus(string $budgetType): string
    {
        return $budgetType === 'main' ? 'approved' : 'draft';
    }

    /**
     * Approve a virtual addition from materials task
     */
    private function approveVirtualAddition(int $taskId, string $additionId, ?string $notes): BudgetAddition
    {
        \Log::info('BudgetAdditionService::approveVirtualAddition - Starting', [
            'taskId' => $taskId,
            'additionId' => $additionId,
            'notes' => $notes
        ]);

        $budgetData = $this->getOrCreateBudgetData($taskId);

        \Log::info('BudgetAdditionService::approveVirtualAddition - Budget data retrieved', [
            'budgetDataId' => $budgetData->id
        ]);

        // Extract material ID from virtual addition ID
        $materialId = str_replace('materials_additional_', '', $additionId);

        // Check if there's already a draft record for this material that we can approve
        $existingDraft = BudgetAddition::where('task_budget_data_id', $budgetData->id)
            ->where('source_type', 'materials_additional')
            ->where('source_material_id', $materialId)
            ->where('status', 'draft')
            ->first();

        if ($existingDraft) {
            \Log::info('BudgetAdditionService::approveVirtualAddition - Found existing draft record, approving it', [
                'existingDraftId' => $existingDraft->id,
                'materialId' => $materialId
            ]);

            // Approve the existing draft record
            if (!$existingDraft->approve(Auth::id(), $notes)) {
                throw new \Exception('Failed to approve budget addition');
            }

            return $existingDraft->fresh(['creator', 'approver']);
        }

        // Fallback: Create new record if no draft exists (shouldn't happen in new workflow)
        \Log::info('BudgetAdditionService::approveVirtualAddition - No draft found, creating new record', [
            'materialId' => $materialId
        ]);

        // Get the virtual addition data
        $materialsAdditions = $this->getMaterialsAdditions($budgetData->id);
        $virtualAddition = collect($materialsAdditions)->firstWhere('id', $additionId);

        if (!$virtualAddition) {
            \Log::error('BudgetAdditionService::approveVirtualAddition - Virtual addition not found', [
                'additionId' => $additionId,
                'availableIds' => collect($materialsAdditions)->pluck('id')->toArray()
            ]);
            throw new \Exception('Virtual addition not found');
        }

        // Calculate total amount for the virtual addition
        $totalAmount = $this->calculateTotalAmount($virtualAddition);

        \Log::info('BudgetAdditionService::approveVirtualAddition - Creating database record', [
            'materialId' => $materialId,
            'totalAmount' => $totalAmount,
            'virtualAdditionData' => $virtualAddition
        ]);

        // Create actual database record for approved virtual addition
        $newAddition = BudgetAddition::create([
            'task_budget_data_id' => $budgetData->id,
            'title' => $virtualAddition['title'],
            'description' => $virtualAddition['description'],
            'materials' => $virtualAddition['materials'],
            'labour' => $virtualAddition['labour'] ?? [],
            'expenses' => $virtualAddition['expenses'] ?? [],
            'logistics' => $virtualAddition['logistics'] ?? [],
            'status' => 'approved',
            'budget_type' => 'supplementary', // Virtual additions are always supplementary
            'source_type' => 'materials_additional', // Required for frontend filtering
            'source_material_id' => $materialId, // Link to the original material
            'total_amount' => $totalAmount,
            'created_by' => Auth::id(), // System generated
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);

        \Log::info('BudgetAdditionService::approveVirtualAddition - Database record created successfully', [
            'newAdditionId' => $newAddition->id,
            'newAdditionStatus' => $newAddition->status
        ]);

        return $newAddition->load(['creator', 'approver']);
    }

    /**
     * Reject a virtual addition from materials task
     */
    private function rejectVirtualAddition(int $taskId, string $additionId, ?string $reason): BudgetAddition
    {
        // For rejection of virtual additions, we create a rejected record
        $budgetData = $this->getOrCreateBudgetData($taskId);

        // Get the virtual addition data
        $materialsAdditions = $this->getMaterialsAdditions($budgetData->id);
        $virtualAddition = collect($materialsAdditions)->firstWhere('id', $additionId);

        if (!$virtualAddition) {
            throw new \Exception('Virtual addition not found');
        }

        // Calculate total amount for the virtual addition
        $totalAmount = $this->calculateTotalAmount($virtualAddition);

        // Extract material ID from virtual addition ID
        $materialId = str_replace('materials_additional_', '', $additionId);

        // Create rejected database record
        $newAddition = BudgetAddition::create([
            'task_budget_data_id' => $budgetData->id,
            'title' => $virtualAddition['title'],
            'description' => $virtualAddition['description'],
            'materials' => $virtualAddition['materials'],
            'labour' => $virtualAddition['labour'] ?? [],
            'expenses' => $virtualAddition['expenses'] ?? [],
            'logistics' => $virtualAddition['logistics'] ?? [],
            'status' => 'rejected',
            'budget_type' => 'supplementary', // Virtual additions are always supplementary
            'source_type' => 'materials_additional', // Required for frontend filtering
            'source_material_id' => $materialId, // Link to the original material
            'total_amount' => $totalAmount,
            'created_by' => Auth::id(), // System generated
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $newAddition->load(['creator', 'approver', 'rejector']);
    }

    /**
     * Create a budget addition from a materials task material
     */
    public function createFromMaterial(int $taskId, array $materialData, string $budgetType): BudgetAddition
    {
        \Log::info('BudgetAdditionService::createFromMaterial called', [
            'taskId' => $taskId,
            'materialData' => $materialData,
            'budgetType' => $budgetType
        ]);

        // Get or create the budget data for this task
        $budgetData = $this->getOrCreateBudgetData($taskId);

        \Log::info('BudgetAdditionService::createFromMaterial - Budget data found', [
            'budgetDataId' => $budgetData->id,
            'taskId' => $taskId
        ]);

        // Determine initial status based on budget type
        $status = $budgetType === 'main' ? 'approved' : 'draft';

        // Prepare the data for creation
        $createData = [
            'task_budget_data_id' => $budgetData->id,
            'title' => 'Material: ' . $materialData['description'],
            'description' => 'Created from Materials Task',
            'materials' => [
                [
                    'id' => $materialData['id'],
                    'description' => $materialData['description'],
                    'unitOfMeasurement' => $materialData['unitOfMeasurement'],
                    'quantity' => (float) $materialData['quantity'],
                    'unitPrice' => 0, // To be set in budget task
                    'totalPrice' => 0,
                    'isAddition' => true
                ]
            ],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'status' => $status,
            'budget_type' => $budgetType,
            'total_amount' => 0, // Will be calculated when prices are set
            'created_by' => Auth::id(),
            'approved_by' => $status === 'approved' ? Auth::id() : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'source_type' => 'materials_additional',
            'source_material_id' => $materialData['id'],
        ];

        \Log::info('BudgetAdditionService::createFromMaterial - Creating addition', [
            'createData' => $createData
        ]);

        try {
            // Create the budget addition using provided material data
            $addition = BudgetAddition::create($createData);

            \Log::info('BudgetAdditionService::createFromMaterial - Addition created successfully', [
                'additionId' => $addition->id,
                'taskId' => $taskId
            ]);

            return $addition->load(['creator', 'approver']);
        } catch (\Exception $e) {
            \Log::error('BudgetAdditionService::createFromMaterial - Failed to create addition', [
                'taskId' => $taskId,
                'createData' => $createData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get materials marked as "additionals" from the materials task
     */
    public function getMaterialsAdditions(int $budgetId): array
    {
        try {
            \Log::info('BudgetAdditionService::getMaterialsAdditions - Starting', [
                'budgetId' => $budgetId
            ]);

            // Get the budget data to find the enquiry
            $budgetData = TaskBudgetData::find($budgetId);
            if (!$budgetData) {
                \Log::warning('BudgetAdditionService::getMaterialsAdditions - No budget data found', [
                    'budgetId' => $budgetId
                ]);
                return [];
            }

            \Log::info('BudgetAdditionService::getMaterialsAdditions - Budget data found', [
                'budgetDataId' => $budgetData->id,
                'enquiryTaskId' => $budgetData->enquiry_task_id
            ]);

            // Find the materials task for this enquiry
            $materialsTask = EnquiryTask::where('project_enquiry_id', $budgetData->enquiry_task->project_enquiry_id)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                \Log::warning('BudgetAdditionService::getMaterialsAdditions - No materials task found', [
                    'projectEnquiryId' => $budgetData->enquiry_task->project_enquiry_id,
                    'budgetDataId' => $budgetId
                ]);
                return [];
            }

            \Log::info('BudgetAdditionService::getMaterialsAdditions - Materials task found', [
                'materialsTaskId' => $materialsTask->id,
                'materialsTaskTitle' => $materialsTask->title
            ]);

            // Get materials data
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData) {
                \Log::warning('BudgetAdditionService::getMaterialsAdditions - No materials data found', [
                    'materialsTaskId' => $materialsTask->id,
                    'budgetDataId' => $budgetId
                ]);
                return [];
            }

            \Log::info('BudgetAdditionService::getMaterialsAdditions - Materials data found', [
                'materialsDataId' => $materialsData->id,
                'elementsCount' => $materialsData->elements->count()
            ]);

            $additions = [];
            $additionalMaterialsCount = 0;

            foreach ($materialsData->elements as $element) {
                \Log::info('BudgetAdditionService::getMaterialsAdditions - Processing element', [
                    'elementId' => $element->id,
                    'elementName' => $element->name,
                    'materialsCount' => $element->materials->count()
                ]);

                foreach ($element->materials as $material) {
                    \Log::info('BudgetAdditionService::getMaterialsAdditions - Checking material', [
                        'materialId' => $material->id,
                        'materialDescription' => $material->description,
                        'isAdditional' => $material->is_additional,
                        'isIncluded' => $material->is_included
                    ]);

                    // Check if this material is marked as an "additional"
                    if ($material->is_additional) {
                        $additionalMaterialsCount++;
                        \Log::info('BudgetAdditionService::getMaterialsAdditions - Found additional material', [
                            'materialId' => $material->id,
                            'materialDescription' => $material->description,
                            'quantity' => $material->quantity,
                            'unitOfMeasurement' => $material->unit_of_measurement
                        ]);

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
                            'budget_type' => 'supplementary', // Virtual additions are always supplementary
                            'created_by' => null, // System generated
                            'approved_by' => null,
                            'created_at' => $material->created_at,
                            'updated_at' => $material->updated_at,
                            'is_materials_additional' => true, // Flag to identify source
                            'source_type' => 'materials_additional', // Required for frontend filtering
                            'source_element' => $element->name,
                            'source_task' => $materialsTask->title
                        ];
                    }
                }
            }

            \Log::info('BudgetAdditionService::getMaterialsAdditions - Completed', [
                'budgetId' => $budgetId,
                'additionalMaterialsCount' => $additionalMaterialsCount,
                'additionsCount' => count($additions),
                'additionIds' => collect($additions)->pluck('id')->toArray()
            ]);

            return $additions;

        } catch (\Exception $e) {
            \Log::error('BudgetAdditionService::getMaterialsAdditions - Exception occurred', [
                'budgetId' => $budgetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Get or create TaskBudgetData for a given task
     */
    public function getOrCreateBudgetData(int $taskId): TaskBudgetData
    {
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        if (!$budgetData) {
            $budgetData = TaskBudgetData::create([
                'enquiry_task_id' => $taskId,
                'project_info' => [],
                'materials_data' => [],
                'labour_data' => [],
                'expenses_data' => [],
                'logistics_data' => [],
                'budget_summary' => [],
                'status' => 'draft',
                'materials_manually_modified' => false,
                'materials_import_metadata' => []
            ]);
        }

        return $budgetData;
    }
}
