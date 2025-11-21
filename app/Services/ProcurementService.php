<?php

namespace App\Services;

use App\Models\TaskBudgetData;
use App\Models\TaskProcurementData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Collection;

/**
 * Procurement Service
 * Handles procurement data management and budget import functionality
 */
class ProcurementService
{
    /**
     * Import budget data for a procurement task
     */
    public function importBudgetData(int $taskId): array
    {
        // Find the procurement task
        $task = EnquiryTask::findOrFail($taskId);

        if ($task->type !== 'procurement') {
            throw new \InvalidArgumentException('Task must be a procurement task');
        }

        // Find the budget task for this enquiry
        $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'budget')
            ->first();

        if (!$budgetTask) {
            throw new \Exception('Budget task not found for this enquiry');
        }

        \Log::info("Found budget task for procurement import", [
            'procurement_task_id' => $taskId,
            'budget_task_id' => $budgetTask->id,
            'enquiry_id' => $task->project_enquiry_id
        ]);

        // Get budget data
        $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

        if (!$budgetData) {
            throw new \Exception('Budget data not found. Please complete the budget task first.');
        }

        // Check if budget has materials
        if (empty($budgetData->materials_data) || !is_array($budgetData->materials_data)) {
            throw new \Exception('No materials found in budget data. Please import materials into the budget task first.');
        }

        \Log::info("Retrieved budget data", [
            'budget_data_id' => $budgetData->id,
            'has_materials_data' => !empty($budgetData->materials_data),
            'materials_count' => is_array($budgetData->materials_data) ? count($budgetData->materials_data) : 0
        ]);

        // Transform budget data to procurement format
        $procurementItems = $this->transformBudgetToProcurement($budgetData);

        \Log::info("Transformed procurement items", [
            'procurement_items_count' => count($procurementItems)
        ]);

        // Create or update procurement data
        $procurementData = TaskProcurementData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $this->extractProjectInfo($task),
                'budget_imported' => true,
                'procurement_items' => $procurementItems,
                'budget_summary' => $this->createBudgetSummary($procurementItems),
                'last_import_date' => now()
            ]
        );

        return $procurementData->toArray();
    }

    /**
     * Transform budget data to procurement items
     */
    private function transformBudgetToProcurement(TaskBudgetData $budgetData): array
    {
        $procurementItems = [];
        $materialsData = $budgetData->materials_data ?? [];

        \Log::info("Transforming budget data to procurement", [
            'budget_data_id' => $budgetData->id,
            'materials_data_type' => gettype($materialsData),
            'materials_data_count' => is_array($materialsData) ? count($materialsData) : 'not_array'
        ]);

        if (!is_array($materialsData) || empty($materialsData)) {
            \Log::warning("No materials data found in budget", [
                'budget_data_id' => $budgetData->id,
                'materials_data' => $materialsData
            ]);
            return $procurementItems;
        }

        foreach ($materialsData as $elementIndex => $element) {
            \Log::info("Processing element", [
                'element_index' => $elementIndex,
                'element_name' => $element['name'] ?? 'unknown',
                'element_type' => gettype($element)
            ]);

            $elementMaterials = $element['materials'] ?? [];

            if (!is_array($elementMaterials)) {
                \Log::warning("Element materials is not an array", [
                    'element_index' => $elementIndex,
                    'element_materials_type' => gettype($elementMaterials)
                ]);
                continue;
            }

            \Log::info("Element has materials", [
                'element_index' => $elementIndex,
                'materials_count' => count($elementMaterials)
            ]);

            foreach ($elementMaterials as $materialIndex => $material) {
                \Log::info("Processing material", [
                    'element_index' => $elementIndex,
                    'material_index' => $materialIndex,
                    'material_id' => $material['id'] ?? 'unknown',
                    'material_description' => $material['description'] ?? 'unknown'
                ]);

                $procurementItems[] = [
                    // Original budget data (preserved)
                    'budgetId' => $material['id'] ?? '',
                    'elementName' => $element['name'] ?? '',
                    'description' => $material['description'] ?? '',
                    'quantity' => $material['quantity'] ?? 0,
                    'unitOfMeasurement' => $material['unitOfMeasurement'] ?? 'Pcs',
                    'budgetUnitPrice' => $material['unitPrice'] ?? 0,
                    'budgetTotalPrice' => $material['totalPrice'] ?? 0,
                    'category' => 'materials',

                    // Procurement-specific data (initialized with defaults)
                    'vendorName' => '',
                    'availabilityStatus' => 'available',
                    'procurementNotes' => '',
                    'lastUpdated' => now()->toISOString(),

                    // Reference data for traceability
                    'budgetElementId' => $element['id'] ?? '',
                    'budgetItemId' => $material['id'] ?? ''
                ];
            }
        }

        \Log::info("Completed transformation", [
            'total_procurement_items' => count($procurementItems)
        ]);

        return $procurementItems;
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
     * Create budget summary from procurement items
     */
    private function createBudgetSummary(array $procurementItems): array
    {
        $totalBudget = collect($procurementItems)->sum('budgetTotalPrice');

        return [
            'materialsTotal' => $totalBudget,
            'totalItems' => count($procurementItems),
            'importedAt' => now()->toISOString()
        ];
    }

    /**
     * Save procurement data
     */
    public function saveProcurementData(int $taskId, array $data): TaskProcurementData
    {
        // Validate data
        $this->validateProcurementData($data);

        // Update or create procurement data
        $procurementData = TaskProcurementData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $data['projectInfo'] ?? [],
                'budget_imported' => $data['budgetImported'] ?? false,
                'procurement_items' => $data['procurementItems'] ?? [],
                'budget_summary' => $data['budgetSummary'] ?? [],
                'last_import_date' => isset($data['lastImportDate']) ? $data['lastImportDate'] : now()
            ]
        );

        return $procurementData;
    }

    /**
     * Get procurement data for a task
     */
    public function getProcurementData(int $taskId): ?TaskProcurementData
    {
        // Sync with budget data before returning
        $this->syncProcurementWithBudget($taskId);
        
        return TaskProcurementData::where('enquiry_task_id', $taskId)->first();
    }

    /**
     * Sync procurement data with current budget data
     */
    private function syncProcurementWithBudget(int $taskId): void
    {
        try {
            $procurementData = TaskProcurementData::where('enquiry_task_id', $taskId)->first();
            
            // If no procurement data exists yet, nothing to sync
            if (!$procurementData || !$procurementData->budget_imported) {
                return;
            }

            // Find the budget task
            $task = EnquiryTask::find($taskId);
            if (!$task) return;

            $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) return;

            // Get latest budget data
            $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            if (!$budgetData || empty($budgetData->materials_data)) return;

            // Transform current budget data to fresh procurement items
            $freshItems = $this->transformBudgetToProcurement($budgetData);
            
            // Index existing items by budgetItemId for easy lookup
            $existingItemsMap = collect($procurementData->procurement_items ?? [])
                ->keyBy('budgetItemId');

            // Merge existing user-entered data into fresh items
            $syncedItems = array_map(function ($item) use ($existingItemsMap) {
                $existingItem = $existingItemsMap->get($item['budgetItemId']);
                
                if ($existingItem) {
                    // Preserve user-entered fields
                    $item['vendorName'] = $existingItem['vendorName'] ?? '';
                    $item['availabilityStatus'] = $existingItem['availabilityStatus'] ?? 'available';
                    $item['procurementNotes'] = $existingItem['procurementNotes'] ?? '';
                    // Keep the original lastUpdated if it exists, or use now
                    $item['lastUpdated'] = $existingItem['lastUpdated'] ?? now()->toISOString();
                }
                
                return $item;
            }, $freshItems);

            // Update the procurement data
            $procurementData->procurement_items = $syncedItems;
            $procurementData->budget_summary = $this->createBudgetSummary($syncedItems);
            $procurementData->save();

            \Log::info("Synced procurement data with budget for task {$taskId}");

        } catch (\Exception $e) {
            \Log::error("Failed to sync procurement data with budget: " . $e->getMessage());
            // We don't throw here to allow getProcurementData to return what it has even if sync fails
        }
    }

    /**
     * Validate procurement data
     */
    private function validateProcurementData(array $data): void
    {
        if (!isset($data['procurementItems']) || !is_array($data['procurementItems'])) {
            throw new \InvalidArgumentException('Procurement items must be an array');
        }

        foreach ($data['procurementItems'] as $item) {
            if (!isset($item['budgetItemId']) || empty($item['budgetItemId'])) {
                throw new \InvalidArgumentException('Each procurement item must have a budgetItemId');
            }

            if (!isset($item['description']) || empty($item['description'])) {
                throw new \InvalidArgumentException('Each procurement item must have a description');
            }

            if (!in_array($item['availabilityStatus'] ?? '', ['available', 'ordered', 'received', 'hired', 'cancelled'])) {
                throw new \InvalidArgumentException('Invalid availability status for procurement item');
            }
        }
    }

    /**
     * Get vendor suggestions for a material description
     */
    public function getVendorSuggestions(string $description): array
    {
        $suggestions = [
            // Stage & Structures
            'stage' => ['Stage Masters Ltd', 'Event Structures Co.', 'Pro Stage Solutions', 'Nairobi Stage Works'],
            'platform' => ['Stage Masters Ltd', 'Event Structures Co.', 'Pro Stage Solutions', 'Steel & Wood Creations'],
            'rostra' => ['Stage Masters Ltd', 'Event Structures Co.', 'Pro Stage Solutions'],
            'podium' => ['Stage Masters Ltd', 'Executive Furniture Ltd', 'Event Structures Co.'],

            // Lighting
            'lighting' => ['Light & Sound Pro', 'LED Solutions Ltd', 'Stage Lighting Corp', 'Illumina Events'],
            'led' => ['LED Solutions Ltd', 'Light & Sound Pro', 'Illumina Events', 'Bright Lights Kenya'],
            'spotlight' => ['Light & Sound Pro', 'Stage Lighting Corp', 'Illumina Events'],
            'par' => ['Light & Sound Pro', 'LED Solutions Ltd', 'Stage Lighting Corp'],
            'follow' => ['Light & Sound Pro', 'Stage Lighting Corp', 'Illumina Events'],

            // Sound & Audio
            'sound' => ['Audio Tech Ltd', 'Sound Systems Pro', 'Pro Audio Solutions', 'Echo Sound Systems'],
            'speaker' => ['Audio Tech Ltd', 'Sound Systems Pro', 'Pro Audio Solutions', 'Echo Sound Systems'],
            'microphone' => ['Audio Tech Ltd', 'Sound Systems Pro', 'Pro Audio Solutions'],
            'mixer' => ['Audio Tech Ltd', 'Sound Systems Pro', 'Pro Audio Solutions', 'Echo Sound Systems'],
            'amplifier' => ['Audio Tech Ltd', 'Sound Systems Pro', 'Pro Audio Solutions'],

            // Furniture
            'chair' => ['Executive Furniture Ltd', 'Comfort Seating Co.', 'Event Chairs Kenya', 'Royal Furniture'],
            'table' => ['Executive Furniture Ltd', 'Event Tables Ltd', 'Royal Furniture', 'Modern Furniture Co.'],
            'furniture' => ['Executive Furniture Ltd', 'Royal Furniture', 'Comfort Seating Co.'],

            // Decor & Backdrops
            'backdrop' => ['Creative Backdrops Ltd', 'Event Decor Kenya', 'Silk & Satin Designs', 'Elegant Events'],
            'banner' => ['Print & Display Ltd', 'Creative Backdrops Ltd', 'Event Decor Kenya'],
            'curtain' => ['Silk & Satin Designs', 'Event Decor Kenya', 'Elegant Events'],
            'decor' => ['Event Decor Kenya', 'Elegant Events', 'Creative Backdrops Ltd'],

            // Signage
            'signage' => ['Print & Display Ltd', 'Sign Masters Kenya', 'Creative Signs Ltd'],
            'sign' => ['Print & Display Ltd', 'Sign Masters Kenya', 'Creative Signs Ltd'],
            'banner' => ['Print & Display Ltd', 'Creative Backdrops Ltd', 'Event Decor Kenya'],

            // Carpets & Flooring
            'carpet' => ['Flooring Solutions Ltd', 'Carpet Masters Kenya', 'Elegant Floors'],
            'rug' => ['Flooring Solutions Ltd', 'Carpet Masters Kenya', 'Elegant Floors'],

            // General Equipment
            'generator' => ['Power Solutions Ltd', 'Generator Experts Kenya', 'Reliable Power Co.'],
            'cable' => ['Electrical Supplies Ltd', 'Cable Masters Kenya', 'Tech Cables Co.'],
            'extension' => ['Electrical Supplies Ltd', 'Cable Masters Kenya', 'Tech Cables Co.'],
        ];

        $lowerDesc = strtolower($description);
        foreach ($suggestions as $key => $vendors) {
            if (str_contains($lowerDesc, $key)) {
                return $vendors;
            }
        }

        // Default suggestions for unmatched items
        return ['General Suppliers Ltd', 'Equipment Rental Co.', 'Tech Solutions Ltd', 'Event Services Kenya'];
    }
}
