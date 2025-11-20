<?php

namespace App\Http\Controllers;

use App\Models\TaskBudgetData;
use App\Services\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Budget Controller
 * Handles budget data management for tasks
 */
class BudgetController extends Controller
{
    private BudgetService $budgetService;

    public function __construct(BudgetService $budgetService)
    {
        $this->budgetService = $budgetService;
    }

    /**
     * Get budget data for a task
     */
    public function getBudgetData(int $taskId): JsonResponse
    {
        try {
            $budgetData = $this->budgetService->getBudgetData($taskId);

            if (!$budgetData) {
                return response()->json([
                    'message' => 'Budget data not found'
                ], 404);
            }

            // Transform the response to match frontend expectations
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data ?? [],
                'labour' => $budgetData->labour_data ?? [],
                'expenses' => $budgetData->expenses_data ?? [],
                'logistics' => $budgetData->logistics_data ?? [],
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status ?? 'draft',
                'materialsImportInfo' => [
                    'importedAt' => $budgetData->materials_imported_at,
                    'importedFromTask' => $budgetData->materials_imported_from_task,
                    'manuallyModified' => $budgetData->materials_manually_modified ?? false,
                    'importMetadata' => $budgetData->materials_import_metadata
                ]
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
        try {
            $data = $request->validate([
                'projectInfo' => 'sometimes|array',
                'materials' => 'required|array',
                'budgetSummary' => 'sometimes|array',
                'lastImportDate' => 'sometimes|date'
            ]);

            $budgetData = $this->budgetService->saveBudgetData($taskId, $data);

            return response()->json([
                'data' => $budgetData,
                'message' => 'Budget data saved successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit budget for approval
     */
    public function submitForApproval(int $taskId): JsonResponse
    {
        try {
            $result = $this->budgetService->submitForApproval($taskId);

            return response()->json([
                'data' => $result,
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
     * Import materials into budget
     */
    public function importMaterials(int $taskId): JsonResponse
    {
        try {
            $budgetData = $this->budgetService->importMaterials($taskId);

            // Transform the response to match frontend expectations
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data ?? [],
                'labour' => $budgetData->labour_data ?? [],
                'expenses' => $budgetData->expenses_data ?? [],
                'logistics' => $budgetData->logistics_data ?? [],
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status ?? 'draft',
                'materialsImportInfo' => [
                    'importedAt' => $budgetData->materials_imported_at,
                    'importedFromTask' => $budgetData->materials_imported_from_task,
                    'manuallyModified' => $budgetData->materials_manually_modified ?? false,
                    'importMetadata' => $budgetData->materials_import_metadata
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
            $result = $this->budgetService->checkMaterialsUpdate($taskId);

            return response()->json([
                'data' => $result,
                'message' => 'Materials update check completed'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to check materials update',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
