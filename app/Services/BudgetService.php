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

        // Transform materials data to budget format
        $budgetMaterials = $this->transformMaterialsToBudget($materialsData);

        // Create or update budget data
        $budgetData = TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $this->extractProjectInfo($task),
                'materials_data' => $budgetMaterials,
                'budget_summary' => $this->createBudgetSummary($budgetMaterials),
                'last_import_date' => now()
            ]
        );

        return $budgetData;
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
