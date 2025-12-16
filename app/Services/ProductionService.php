<?php

namespace App\Services;
use App\Models\TaskProductionData;
use App\Models\ProductionElement;
use App\Models\ProductionQualityCheckpoint;
use App\Models\ProductionIssue;
use App\Models\ProductionCompletionCriterion;
use App\Models\TaskMaterialsData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionService
{
    /**
     * Get production data for a task
     */
    public function getProductionData(int $taskId): ?array
    {
        $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        
        $productionData = TaskProductionData::with([
            'productionElements',
            'qualityCheckpoints',
            'issues',
            'completionCriteria'
        ])->where('task_id', $taskId)->first();

        if (!$productionData) {
            return null;
        }

        return $this->formatProductionDataResponse($task, $productionData);
    }

    /**
     * Import materials from Materials Task
     */
    public function importMaterialsData(int $taskId): array
    {
        DB::beginTransaction();
        
        try {
            $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
            
            // Find the materials task for this enquiry
            $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                throw new \Exception('Materials task not found for this enquiry');
            }

            // Get materials data using the TaskMaterialsData model with correct column
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData || !$materialsData->elements || $materialsData->elements->isEmpty()) {
                throw new \Exception('No materials data found. Please complete the Materials Task first.');
            }

            $materials = $materialsData->elements;

            // Create or get production data record
            $productionData = TaskProductionData::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'materials_imported' => false,
                    'last_materials_import_date' => null
                ]
            );

            // Clear existing production elements
            ProductionElement::where('production_data_id', $productionData->id)->delete();

            // Transform materials into production elements
            $productionElements = [];
            foreach ($materials as $element) {
                if (!$element->materials || $element->materials->isEmpty()) {
                    continue;
                }

                foreach ($element->materials as $material) {
                    $productionElements[] = [
                        'production_data_id' => $productionData->id,
                        'material_id' => $material->id ?? null,
                        'category' => $this->determineCategoryFromElement($element->name ?? ''),
                        'name' => $material->description ?? 'Unnamed Material',
                        'quantity' => $material->quantity ?? 0,
                        'unit' => $material->unit_of_measurement ?? 'pcs',
                        'specifications' => isset($element->name) ? "Element: {$element->name}" : null,
                        'status' => 'pending',
                        'notes' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($productionElements)) {
                ProductionElement::insert($productionElements);
            }

            // Update production data
            $productionData->update([
                'materials_imported' => true,
                'last_materials_import_date' => now()
            ]);

            // Create default completion criteria if none exist
            if ($productionData->completionCriteria()->count() === 0) {
                $this->createDefaultCompletionCriteria($productionData->id);
            }

            DB::commit();

            // Reload with relationships
            $productionData->load([
                'productionElements',
                'qualityCheckpoints',
                'issues',
                'completionCriteria'
            ]);

            return $this->formatProductionDataResponse($task, $productionData);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to import materials data: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Save production data
     */
    public function saveProductionData(int $taskId, array $data): array
    {
        DB::beginTransaction();
        
        try {
            $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
            
            $productionData = TaskProductionData::where('task_id', $taskId)->first();
            
            if (!$productionData) {
                throw new \Exception('Production data not found. Please import materials first.');
            }

            // Update quality checkpoints
            if (isset($data['quality_control']) && is_array($data['quality_control'])) {
                $this->updateQualityCheckpoints($productionData->id, $data['quality_control']);
            }

            // Update issues
            if (isset($data['issues']) && is_array($data['issues'])) {
                $this->updateIssues($productionData->id, $data['issues']);
            }

            // Update completion criteria
            if (isset($data['completion_criteria']) && is_array($data['completion_criteria'])) {
                $this->updateCompletionCriteria($productionData->id, $data['completion_criteria']);
            }

            DB::commit();

            // Reload with relationships
            $productionData->load([
                'productionElements',
                'qualityCheckpoints',
                'issues',
                'completionCriteria'
            ]);

            return $this->formatProductionDataResponse($task, $productionData);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save production data: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate quality checkpoints from production elements
     */
    public function generateQualityCheckpoints(int $taskId): array
    {
        Log::info("Generating quality checkpoints for task {$taskId}");
        
        try {
            $productionData = TaskProductionData::where('task_id', $taskId)->firstOrFail();
            Log::info("Found production data: {$productionData->id}");
            
            // Clear existing checkpoints
            ProductionQualityCheckpoint::where('production_data_id', $productionData->id)->delete();
            Log::info("Cleared existing checkpoints");

            // Get unique categories from production elements
            $categories = ProductionElement::where('production_data_id', $productionData->id)
                ->select('category')
                ->distinct()
                ->pluck('category');
            
            Log::info("Found categories: " . $categories->toJson());

            $checkpoints = [];
            foreach ($categories as $category) {
                Log::info("Creating checkpoint for category: " . ($category ?? 'NULL'));
                
                if (empty($category)) {
                    Log::warning("Skipping empty category");
                    continue;
                }

                $checkpoint = ProductionQualityCheckpoint::create([
                    'production_data_id' => $productionData->id,
                    'category_id' => $category,
                    'category_name' => $this->formatCategoryName($category),
                    'status' => 'pending',
                    'priority' => 'medium',
                ]);

                $checkpoints[] = $checkpoint;
            }
            
            Log::info("Generated " . count($checkpoints) . " checkpoints");

            // Transform to match frontend expectations (camelCase)
            return array_map(function ($checkpoint) {
                return [
                    'id' => (string)$checkpoint->id,
                    'categoryId' => $checkpoint->category_id,
                    'categoryName' => $checkpoint->category_name,
                    'status' => $checkpoint->status,
                    'qualityScore' => $checkpoint->quality_score,
                    'checkedBy' => $checkpoint->checked_by,
                    'checkedAt' => $checkpoint->checked_at?->toISOString(),
                    'priority' => $checkpoint->priority,
                    'notes' => $checkpoint->notes,
                    'createdAt' => $checkpoint->created_at->toISOString(),
                    'updatedAt' => $checkpoint->updated_at->toISOString(),
                ];
            }, $checkpoints);
        } catch (\Exception $e) {
            Log::error("Error in generateQualityCheckpoints: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Delete all quality checkpoints for a task
     */
    public function deleteQualityCheckpoints(int $taskId): void
    {
        try {
            $productionData = TaskProductionData::where('task_id', $taskId)->first();
            
            if ($productionData) {
                ProductionQualityCheckpoint::where('production_data_id', $productionData->id)->delete();
                Log::info("Deleted all quality checkpoints for task {$taskId}");
            }
        } catch (\Exception $e) {
            Log::error("Error deleting quality checkpoints: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Format production data for API response
     */
    private function formatProductionDataResponse(EnquiryTask $task, TaskProductionData $productionData): array
    {
        $enquiry = $task->enquiry;
        
        return [
            'productionData' => [
                'id' => $productionData->id,
                'taskId' => $productionData->task_id,
                'materialsImported' => $productionData->materials_imported,
                'lastMaterialsImportDate' => $productionData->last_materials_import_date?->toISOString(),
            ],
            'projectInfo' => [
                'projectId' => $enquiry->enquiry_number ?? "ENQ-{$enquiry->id}",
                'enquiryNumber' => $enquiry->enquiry_number ?? "ENQ-{$enquiry->id}",
                'enquiryTitle' => $enquiry->title ?? 'Untitled Project',
                'clientName' => $enquiry->client->full_name ?? $enquiry->contact_person ?? 'Unknown Client',
                'eventVenue' => $enquiry->venue ?? 'TBC',
                'setupDate' => $enquiry->expected_delivery_date ?? 'TBC',
                'setDownDate' => $enquiry->set_down_date ?? 'TBC',
                'estimatedBudget' => $enquiry->estimated_budget,
                'contactPerson' => $enquiry->contact_person ?? 'TBC',
            ],
            'productionElements' => $productionData->productionElements->map(function ($element) {
                return [
                    'id' => (string)$element->id,
                    'materialId' => $element->material_id,
                    'category' => $element->category,
                    'name' => $element->name,
                    'quantity' => (float)$element->quantity,
                    'unit' => $element->unit,
                    'specifications' => $element->specifications,
                    'status' => $element->status,
                    'notes' => $element->notes,
                    'createdAt' => $element->created_at->toISOString(),
                    'updatedAt' => $element->updated_at->toISOString(),
                ];
            })->toArray(),
            'qualityControl' => $productionData->qualityCheckpoints->map(function ($checkpoint) {
                return [
                    'id' => (string)$checkpoint->id,
                    'categoryId' => $checkpoint->category_id,
                    'categoryName' => $checkpoint->category_name,
                    'status' => $checkpoint->status,
                    'qualityScore' => $checkpoint->quality_score,
                    'checkedBy' => $checkpoint->checked_by,
                    'checkedAt' => $checkpoint->checked_at?->toISOString(),
                    'priority' => $checkpoint->priority,
                    'notes' => $checkpoint->notes,
                    'checklist' => $checkpoint->checklist,
                    'createdAt' => $checkpoint->created_at->toISOString(),
                    'updatedAt' => $checkpoint->updated_at->toISOString(),
                ];
            })->toArray(),
            'issues' => $productionData->issues->map(function ($issue) {
                return [
                    'id' => (string)$issue->id,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'category' => $issue->category,
                    'status' => $issue->status,
                    'priority' => $issue->priority,
                    'reportedBy' => $issue->reported_by,
                    'reportedDate' => $issue->reported_date->toISOString(),
                    'resolvedDate' => $issue->resolved_date?->toISOString(),
                    'resolution' => $issue->resolution,
                ];
            })->toArray(),
            'completionCriteria' => $productionData->completionCriteria->map(function ($criterion) {
                return [
                    'id' => (string)$criterion->id,
                    'description' => $criterion->description,
                    'category' => $criterion->category,
                    'met' => $criterion->met,
                    'isCustom' => $criterion->is_custom,
                    'notes' => $criterion->notes,
                    'completedAt' => $criterion->completed_at?->toISOString(),
                    'createdAt' => $criterion->created_at->toISOString(),
                    'updatedAt' => $criterion->updated_at->toISOString(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Update quality checkpoints
     */
    private function updateQualityCheckpoints(int $productionDataId, array $checkpoints): void
    {
        foreach ($checkpoints as $checkpointData) {
            if (isset($checkpointData['id']) && is_numeric($checkpointData['id'])) {
                $checkpoint = ProductionQualityCheckpoint::find($checkpointData['id']);
                if ($checkpoint && $checkpoint->production_data_id === $productionDataId) {
                    $checkpoint->update([
                        'status' => $checkpointData['status'] ?? $checkpoint->status,
                        'quality_score' => $checkpointData['quality_score'] ?? $checkpoint->quality_score, // snake_case expected from input
                        'checked_by' => $checkpointData['checked_by'] ?? $checkpoint->checked_by,
                        'checked_at' => isset($checkpointData['checked_at']) ? now() : $checkpoint->checked_at,
                        'priority' => $checkpointData['priority'] ?? $checkpoint->priority,
                        'notes' => $checkpointData['notes'] ?? $checkpoint->notes,
                        'checklist' => $checkpointData['checklist'] ?? $checkpoint->checklist,
                    ]);
                }
            } else {
                // Create new checkpoint
                ProductionQualityCheckpoint::create([
                    'production_data_id' => $productionDataId,
                    'category_id' => $checkpointData['categoryId'] ?? $checkpointData['category_id'] ?? 'general-' . uniqid(), // Handle both camel and snake case, ensure not null
                    'category_name' => $checkpointData['categoryName'] ?? $checkpointData['category_name'] ?? 'General',
                    'status' => $checkpointData['status'] ?? 'pending',
                    'priority' => $checkpointData['priority'] ?? 'medium',
                    'quality_score' => $checkpointData['qualityScore'] ?? $checkpointData['quality_score'] ?? 0,
                    'checked_by' => $checkpointData['checkedBy'] ?? $checkpointData['checked_by'] ?? null,
                    'checked_at' => isset($checkpointData['checkedAt']) ? now() : null,
                    'checked_at' => isset($checkpointData['checkedAt']) ? now() : null,
                    'notes' => $checkpointData['notes'] ?? null,
                    'checklist' => $checkpointData['checklist'] ?? null,
                ]);
            }
        }
    }

    /**
     * Update issues
     */
    private function updateIssues(int $productionDataId, array $issues): void
    {
        Log::info('[DEBUG] updateIssues called', [
            'production_data_id' => $productionDataId,
            'issues_count' => count($issues),
            'issues' => $issues
        ]);

        foreach ($issues as $issueData) {
            // Only try to update if ID is numeric (from database), not temporary string IDs
            if (isset($issueData['id']) && is_numeric($issueData['id'])) {
                Log::info('[DEBUG] Updating existing issue', ['id' => $issueData['id']]);
                $issue = ProductionIssue::find($issueData['id']);
                if ($issue && $issue->production_data_id === $productionDataId) {
                    $issue->update([
                        'title' => $issueData['title'] ?? $issue->title,
                        'description' => $issueData['description'] ?? $issue->description,
                        'category' => $issueData['category'] ?? $issue->category,
                        'status' => $issueData['status'] ?? $issue->status,
                        'priority' => $issueData['priority'] ?? $issue->priority,
                        'resolved_date' => isset($issueData['resolved_date']) || isset($issueData['resolvedDate']) ? now() : $issue->resolved_date,
                        'resolution' => $issueData['resolution'] ?? $issue->resolution,
                    ]);
                    Log::info('[DEBUG] Issue updated successfully', ['id' => $issue->id]);
                }
            } else {
                // Create new issue (no ID or temporary string ID)
                Log::info('[DEBUG] Creating new issue', ['data' => $issueData]);
                $newIssue = ProductionIssue::create([
                    'production_data_id' => $productionDataId,
                    'title' => $issueData['title'],
                    'description' => $issueData['description'],
                    'category' => $issueData['category'],
                    'status' => $issueData['status'] ?? 'open',
                    'priority' => $issueData['priority'] ?? 'medium',
                    'reported_by' => $issueData['reported_by'] ?? $issueData['reportedBy'] ?? null,
                    'reported_date' => $issueData['reported_date'] ?? now(),
                ]);
                Log::info('[DEBUG] New issue created', ['id' => $newIssue->id]);
            }
        }
    }

    /**
     * Update completion criteria
     */
    private function updateCompletionCriteria(int $productionDataId, array $criteria): void
    {
        foreach ($criteria as $criterionData) {
            if (isset($criterionData['id']) && is_numeric($criterionData['id'])) {
                $criterion = ProductionCompletionCriterion::find($criterionData['id']);
                if ($criterion && $criterion->production_data_id === $productionDataId) {
                    $met = $criterionData['met'] ?? $criterion->met;
                    $criterion->update([
                        'description' => $criterionData['description'] ?? $criterion->description,
                        'category' => $criterionData['category'] ?? $criterion->category,
                        'met' => $met,
                        'notes' => $criterionData['notes'] ?? $criterion->notes,
                        'completed_at' => $met ? now() : null,
                    ]);
                }
            } else {
                // Create new criterion
                $met = $criterionData['met'] ?? false;
                ProductionCompletionCriterion::create([
                    'production_data_id' => $productionDataId,
                    'description' => $criterionData['description'] ?? 'New Criterion',
                    'category' => $criterionData['category'] ?? 'production',
                    'met' => $met,
                    'is_custom' => $criterionData['is_custom'] ?? true, // Default to true if not specified (likely user added)
                    'notes' => $criterionData['notes'] ?? null,
                    'completed_at' => $met ? now() : null,
                ]);
            }
        }
    }

    /**
     * Create default completion criteria
     */
    private function createDefaultCompletionCriteria(int $productionDataId): void
    {
        $defaultCriteria = [
            [
                'description' => 'All production elements completed according to specifications',
                'category' => 'production',
                'met' => false,
                'is_custom' => false,
            ],
            [
                'description' => 'Quality control checks completed and passed',
                'category' => 'quality',
                'met' => false,
                'is_custom' => false,
            ],
            [
                'description' => 'Production documentation completed',
                'category' => 'documentation',
                'met' => false,
                'is_custom' => false,
            ],
            [
                'description' => 'Client approval received for produced items',
                'category' => 'approval',
                'met' => false,
                'is_custom' => false,
            ],
        ];

        foreach ($defaultCriteria as $criterion) {
            ProductionCompletionCriterion::create(array_merge(
                ['production_data_id' => $productionDataId],
                $criterion
            ));
        }
    }

    /**
     * Determine category from element name
     */
    private function determineCategoryFromElement(string $elementName): string
    {
        $elementName = strtolower($elementName);
        
        $categoryMappings = [
            'stage' => 'stage',
            'skirting' => 'stage-skirting',
            'backdrop' => 'stage-backdrop',
            'entrance' => 'entrance-arc',
            'arc' => 'entrance-arc',
            'walkway' => 'walkway-dance-floor',
            'dance' => 'walkway-dance-floor',
            'floor' => 'walkway-dance-floor',
        ];

        foreach ($categoryMappings as $keyword => $category) {
            if (str_contains($elementName, $keyword)) {
                return $category;
            }
        }

        return 'general';
    }

    /**
     * Format category name for display
     */
    private function formatCategoryName(string $category): string
    {
        return strtoupper(str_replace('-', ' ', $category));
    }
}
