<?php

namespace App\Services;

use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Modules\Projects\Models\EnquiryTask;

/**
 * Budget Service
 * Handles budget data management and materials import functionality
 */
class BudgetService
{
    /**
     * Get budget data for a task
     */
    public function getBudgetData(int $taskId): ?TaskBudgetData
    {
        return TaskBudgetData::where('enquiry_task_id', $taskId)->first();
    }

    /**
     * Save budget data for a task
     */
    public function saveBudgetData(int $taskId, array $data): TaskBudgetData
    {
        // Validate task exists and is a budget task
        $task = EnquiryTask::findOrFail($taskId);

        if ($task->type !== 'budget') {
            throw new \InvalidArgumentException('Task must be a budget task');
        }

        // Retrieve existing budget data if any
        $existing = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        // Merge incoming data with existing data, preserving existing values when not provided
        $projectInfo = $data['projectInfo'] ?? ($existing ? $existing->project_info : []);
        $materials = $data['materials'] ?? ($existing ? $existing->materials_data : []);
        $labour = $data['labour'] ?? ($existing ? $existing->labour_data : []);
        $expenses = $data['expenses'] ?? ($existing ? $existing->expenses_data : []);
        $logistics = $data['logistics'] ?? ($existing ? $existing->logistics_data : []);
        $budgetSummary = $data['budgetSummary'] ?? ($existing ? $existing->budget_summary : []);
        $lastImportDate = $data['lastImportDate'] ?? ($existing ? $existing->last_import_date : now());

        // Update or create budget data with merged values
        $budgetData = TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $projectInfo,
                'materials_data' => $materials,
                'labour_data' => $labour,
                'expenses_data' => $expenses,
                'logistics_data' => $logistics,
                'budget_summary' => $budgetSummary,
                'last_import_date' => $lastImportDate,
            ]
        );

        return $budgetData;
    }

    /**
     * Import materials from materials task into budget
     * Uses intelligent merge to preserve existing pricing data
     */
    public function importMaterials(int $taskId): TaskBudgetData
    {
        // Find the budget task
        $task = EnquiryTask::findOrFail($taskId);

        if ($task->type !== 'budget') {
            throw new \InvalidArgumentException('Task must be a budget task');
        }

        // Find the materials task for this enquiry
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) {
            throw new \Exception('Materials task not found for this enquiry');
        }

        // Get materials data
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();

        if (!$materialsData) {
            throw new \Exception('Materials data not found. Please complete the materials task first.');
        }

        // Check approval status - ONLY import if fully approved
        $projectInfo = $materialsData->project_info ?? [];
        $approvalStatus = $projectInfo['approval_status'] ?? [];
        $isApproved = $approvalStatus['all_approved'] ?? false;

        if (!$isApproved) {
            throw new \Exception('Materials must be fully approved by all departments before importing into budget.');
        }

        // Get existing budget data (if any)
        $existingBudget = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        $existingMaterials = $existingBudget?->materials_data ?? [];

        // Transform NEW materials data to budget format
        $newBudgetMaterials = $this->transformMaterialsToBudget($materialsData);

        // SMART MERGE: Preserve pricing data from existing materials
        $mergedMaterials = $this->mergeMaterialsData($existingMaterials, $newBudgetMaterials);

        // Create or update budget data
        $budgetData = TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $this->extractProjectInfo($task),
                'materials_data' => $mergedMaterials,
                'budget_summary' => $this->createBudgetSummary($mergedMaterials),
                'last_import_date' => now()
            ]
        );

        return $budgetData;
    }

    /**
     * Intelligently merge existing budget materials with new materials from import
     * Preserves: unit prices, total prices
     * Updates: quantities, descriptions
     * Adds: new materials
     * Marks: obsolete materials (removed from source)
     */
    private function mergeMaterialsData(array $existingMaterials, array $newMaterials): array
    {
        // Create a lookup map of existing materials by description for fast matching
        $existingMap = $this->createMaterialsLookupMap($existingMaterials);
        
        $merged = [];
        $processedKeys = [];

        // Process each new element
        foreach ($newMaterials as $newElement) {
            $elementKey = $this->getElementKey($newElement);
            $mergedElement = $newElement;

            // Process materials within this element
            foreach ($newElement['materials'] ?? [] as $materialIndex => $newMaterial) {
                $materialKey = $this->getMaterialKey($newMaterial);
                $fullKey = $elementKey . '::' . $materialKey;
                
                // Check if this material existed before
                if (isset($existingMap[$fullKey])) {
                    $existingMaterial = $existingMap[$fullKey];
                    
                    \Log::debug('Material matched', [
                        'key' => $fullKey,
                        'description' => $newMaterial['description'] ?? 'N/A',
                        'old_qty' => $existingMaterial['quantity'] ?? 0,
                        'new_qty' => $newMaterial['quantity'] ?? 0,
                        'preserved_unit_price' => $existingMaterial['unitPrice'] ?? 0
                    ]);
                    
                    // PRESERVE pricing data
                    $mergedElement['materials'][$materialIndex]['unitPrice'] = $existingMaterial['unitPrice'] ?? 0;
                    $mergedElement['materials'][$materialIndex]['totalPrice'] = $existingMaterial['totalPrice'] ?? 0;
                    
                    // Track if quantity changed (for user awareness)
                    $oldQty = $existingMaterial['quantity'] ?? 0;
                    $newQty = $newMaterial['quantity'] ?? 0;
                    
                    if ($oldQty != $newQty) {
                        $mergedElement['materials'][$materialIndex]['_quantityChanged'] = true;
                        $mergedElement['materials'][$materialIndex]['_oldQuantity'] = $oldQty;
                        
                        // Recalculate total price with new quantity
                        $unitPrice = $existingMaterial['unitPrice'] ?? 0;
                        $mergedElement['materials'][$materialIndex]['totalPrice'] = $unitPrice * $newQty;
                    }
                    
                    // Mark as processed
                    $processedKeys[] = $fullKey;
                } else {
                    \Log::debug('New material detected', [
                        'key' => $fullKey,
                        'description' => $newMaterial['description'] ?? 'N/A',
                        'quantity' => $newMaterial['quantity'] ?? 0
                    ]);
                }
                // else: New material - keep default unitPrice = 0 from transform
            }

            $merged[] = $mergedElement;
        }

        // Handle obsolete materials (existed in old budget but not in new import)
        foreach ($existingMaterials as $oldElement) {
            $elementKey = $this->getElementKey($oldElement);
            
            foreach ($oldElement['materials'] ?? [] as $oldMaterial) {
                $materialKey = $this->getMaterialKey($oldMaterial);
                $fullKey = $elementKey . '::' . $materialKey;
                
                // If not processed, it means it was removed from materials task
                if (!in_array($fullKey, $processedKeys)) {
                    // Find or create the element in merged array
                    $elementFound = false;
                    foreach ($merged as &$mergedElement) {
                        if ($this->getElementKey($mergedElement) === $elementKey) {
                            // Add obsolete material to this element with flag
                            $obsoleteMaterial = $oldMaterial;
                            $obsoleteMaterial['_isObsolete'] = true;
                            $obsoleteMaterial['_obsoleteNote'] = 'Removed from Materials list';
                            $mergedElement['materials'][] = $obsoleteMaterial;
                            $elementFound = true;
                            break;
                        }
                    }
                    
                    // If element itself was removed, create it as obsolete
                    if (!$elementFound && isset($oldMaterial['unitPrice']) && $oldMaterial['unitPrice'] > 0) {
                        $obsoleteMaterial = $oldMaterial;
                        $obsoleteMaterial['_isObsolete'] = true;
                        $obsoleteMaterial['_obsoleteNote'] = 'Element removed from Materials';
                        
                        $merged[] = [
                            'id' => $oldElement['id'] ?? 'elem_obsolete_' . uniqid(),
                            'elementType' => $oldElement['elementType'] ?? 'custom',
                            'name' => $oldElement['name'] ?? 'Obsolete Element',
                            'category' => $oldElement['category'] ?? 'general',
                            'isIncluded' => $oldElement['isIncluded'] ?? true,
                            '_isObsolete' => true,
                            'materials' => [$obsoleteMaterial]
                        ];
                    }
                }
            }
        }

        \Log::info('Materials merge completed', [
            'existing_count' => count($existingMaterials),
            'new_count' => count($newMaterials),
            'merged_count' => count($merged),
            'processed_materials' => count($processedKeys)
        ]);

        return $merged;
    }

    /**
     * Create a lookup map of existing materials
     * Key format: "elementKey::materialKey"
     */
    private function createMaterialsLookupMap(array $materials): array
    {
        $map = [];
        
        foreach ($materials as $element) {
            $elementKey = $this->getElementKey($element);
            
            foreach ($element['materials'] ?? [] as $material) {
                $materialKey = $this->getMaterialKey($material);
                $fullKey = $elementKey . '::' . $materialKey;
                $map[$fullKey] = $material;
                
                \Log::debug('Indexed existing material', [
                    'full_key' => $fullKey,
                    'element' => $element['name'] ?? 'N/A',
                    'description' => $material['description'] ?? 'N/A',
                    'unit_price' => $material['unitPrice'] ?? 0
                ]);
            }
        }
        
        \Log::info('Created materials lookup map', [
            'total_materials_indexed' => count($map)
        ]);
        
        return $map;
    }

    /**
     * Generate a unique key for an element
     * ALWAYS uses name + category for matching (IDs change between imports)
     */
    private function getElementKey(array $element): string
    {
        // DO NOT use ID - it changes between imports!
        // ALWAYS use name + category for stable matching
        $name = $element['name'] ?? 'unknown';
        $category = $element['category'] ?? 'general';
        $elementType = $element['elementType'] ?? 'custom';
        
        // Normalize for matching
        $normalizedName = strtolower(trim($name));
        $normalizedName = preg_replace('/\s+/', '_', $normalizedName);
        $normalizedCategory = strtolower(trim($category));
        
        return $normalizedName . '::' . $normalizedCategory . '::' . $elementType;
    }

    /**
     * Generate a unique key for a material
     * ALWAYS uses description + unit for matching (IDs change between imports)
     */
    private function getMaterialKey(array $material): string
    {
        // DO NOT use ID - it changes between imports!
        // ALWAYS use description + unit for stable matching
        $description = $material['description'] ?? 'unknown';
        $unit = $material['unitOfMeasurement'] ?? '';
        
        // Normalize description for matching
        $normalized = strtolower(trim($description));
        $normalized = preg_replace('/\s+/', '_', $normalized);
        
        // Remove special characters that might vary
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized);
        
        $normalizedUnit = strtolower(trim($unit));
        
        return $normalized . '::' . $normalizedUnit;
    }

    /**
     * Check if materials have been updated since last import
     */
    public function checkMaterialsUpdate(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);

        if ($task->type !== 'budget') {
            throw new \InvalidArgumentException('Task must be a budget task');
        }

        // Find the materials task
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) {
            return [
                'hasUpdate' => false,
                'message' => 'Materials task not found'
            ];
        }

        // Get materials data
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        if (!$materialsData) {
            return [
                'hasUpdate' => false,
                'message' => 'No materials data found'
            ];
        }

        if (!$budgetData) {
            return [
                'hasUpdate' => true,
                'message' => 'Budget has not imported materials yet'
            ];
        }

        // Compare last update times
        $materialsUpdated = $materialsData->updated_at;
        $budgetImported = $budgetData->last_import_date ?? $budgetData->created_at;

        return [
            'hasUpdate' => $materialsUpdated > $budgetImported,
            'materialsLastUpdated' => $materialsUpdated,
            'budgetLastImported' => $budgetImported,
            'message' => $materialsUpdated > $budgetImported
                ? 'Materials have been updated since last import'
                : 'Budget is up to date'
        ];
    }

    /**
     * Submit budget for approval
     */
    public function submitForApproval(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);

        if ($task->type !== 'budget') {
            throw new \InvalidArgumentException('Task must be a budget task');
        }

        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        if (!$budgetData) {
            throw new \Exception('Budget data not found. Please save budget data first.');
        }

        // Update task status to indicate submission
        $task->update([
            'status' => 'pending_approval'
        ]);

        return [
            'success' => true,
            'message' => 'Budget submitted for approval',
            'taskId' => $taskId,
            'status' => 'pending_approval'
        ];
    }

    /**
     * Transform materials data to budget format
     */
    private function transformMaterialsToBudget(TaskMaterialsData $materialsData): array
    {
        $budgetMaterials = [];

        // Load elements with their materials using the relationship
        $elements = $materialsData->elements()->with('materials')->get();

        if ($elements->isEmpty()) {
            \Log::warning('No elements found for materials data', [
                'materials_data_id' => $materialsData->id,
                'task_id' => $materialsData->enquiry_task_id
            ]);
            return $budgetMaterials;
        }

        foreach ($elements as $element) {
            $elementMaterials = $element->materials;

            $budgetElement = [
                'id' => 'elem_' . $element->id,
                'elementType' => $element->element_type ?? 'custom',
                'name' => $element->name ?? '',
                'category' => $element->category ?? 'general',
                'isIncluded' => true,
                'materials' => []
            ];

            foreach ($elementMaterials as $material) {
                $budgetElement['materials'][] = [
                    'id' => 'mat_' . $material->id,
                    'description' => $material->description ?? '',
                    'unitOfMeasurement' => $material->unit_of_measurement ?? 'Pcs',
                    'quantity' => $material->quantity ?? 0,
                    'unitPrice' => 0, // To be filled in budget task
                    'totalPrice' => 0, // To be calculated
                    'isIncluded' => true
                ];
            }

            $budgetMaterials[] = $budgetElement;
        }

        \Log::info('Transformed materials to budget', [
            'elements_count' => count($budgetMaterials),
            'materials_data_id' => $materialsData->id
        ]);

        return $budgetMaterials;
    }

    /**
     * Extract project information from task
     */
    private function extractProjectInfo(EnquiryTask $task): array
    {
        $enquiry = $task->enquiry;

        return [
            'projectId' => $enquiry ? 'ENQ-' . $enquiry->id : 'ENQ-' . $task->project_enquiry_id,
            'enquiryTitle' => $enquiry ? $enquiry->title : 'Untitled Project',
            'clientName' => $enquiry ? ($enquiry->client?->full_name ?? $enquiry->contact_person ?? 'Unknown Client') : 'Unknown Client',
            'eventVenue' => $enquiry ? $enquiry->venue : 'TBC',
            'setupDate' => $enquiry ? $enquiry->expected_delivery_date : 'TBC',
            'setDownDate' => 'TBC'
        ];
    }

    /**
     * Create budget summary from materials
     */
    private function createBudgetSummary(array $materials): array
    {
        $materialsTotal = 0;

        foreach ($materials as $element) {
            foreach ($element['materials'] ?? [] as $material) {
                $materialsTotal += $material['totalPrice'] ?? 0;
            }
        }

        return [
            'materialsTotal' => $materialsTotal,
            'labourTotal' => 0,
            'expensesTotal' => 0,
            'logisticsTotal' => 0,
            'grandTotal' => $materialsTotal
        ];
    }
}
