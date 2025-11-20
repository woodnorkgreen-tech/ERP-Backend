<?php

namespace App\Http\Controllers;

use App\Models\TaskProcurementData;
use App\Services\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Procurement",
 *     description="Procurement management endpoints"
 * )
 */
class ProcurementController extends Controller
{
    private ProcurementService $procurementService;

    public function __construct(ProcurementService $procurementService)
    {
        $this->procurementService = $procurementService;
    }

    /**
     * Get procurement data for a task
     *
     * @OA\Get(
     *     path="/api/projects/tasks/{taskId}/procurement",
     *     summary="Get procurement data",
     *     tags={"Procurement"},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Procurement data retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/TaskProcurementData")
     *     ),
     *     @OA\Response(response=404, description="Procurement data not found")
     * )
     */
    public function getProcurementData(int $taskId): JsonResponse
    {
        try {
            $procurementData = $this->procurementService->getProcurementData($taskId);

            if (!$procurementData) {
                return response()->json([
                    'message' => 'Procurement data not found'
                ], 404);
            }

            // Transform to match frontend expectations (camelCase)
            $response = [
                'projectInfo' => $procurementData->project_info,
                'budgetImported' => $procurementData->budget_imported,
                'procurementItems' => $procurementData->procurement_items ?? [],
                'budgetSummary' => $procurementData->budget_summary,
                'lastImportDate' => $procurementData->last_import_date
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Procurement data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve procurement data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save procurement data for a task
     *
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/procurement",
     *     summary="Save procurement data",
     *     tags={"Procurement"},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/TaskProcurementData")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Procurement data saved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/TaskProcurementData")
     *     ),
     *     @OA\Response(response=400, description="Invalid data provided")
     * )
     */
    public function saveProcurementData(Request $request, int $taskId): JsonResponse
    {
        try {
            $data = $request->validate([
                'projectInfo' => 'sometimes|array',
                'budgetImported' => 'sometimes|boolean',
                'procurementItems' => 'required|array',
                'procurementItems.*.budgetItemId' => 'required|string',
                'procurementItems.*.description' => 'required|string',
                'procurementItems.*.availabilityStatus' => 'sometimes|in:available,ordered,received,hired,cancelled',
                'budgetSummary' => 'sometimes|array',
                'lastImportDate' => 'sometimes|date'
            ]);

            $procurementData = $this->procurementService->saveProcurementData($taskId, $data);

            // Transform to match frontend expectations (camelCase)
            $response = [
                'projectInfo' => $procurementData->project_info,
                'budgetImported' => $procurementData->budget_imported,
                'procurementItems' => $procurementData->procurement_items ?? [],
                'budgetSummary' => $procurementData->budget_summary,
                'lastImportDate' => $procurementData->last_import_date
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Procurement data saved successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save procurement data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import budget data for procurement
     *
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/procurement/import-budget",
     *     summary="Import budget data for procurement",
     *     tags={"Procurement"},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Procurement Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Budget data imported successfully",
     *         @OA\JsonContent(ref="#/components/schemas/TaskProcurementData")
     *     ),
     *     @OA\Response(response=404, description="Budget task not found")
     * )
     */
    public function importBudgetData(int $taskId): JsonResponse
    {
        try {
            $procurementData = $this->procurementService->importBudgetData($taskId);

            // Transform to match frontend expectations (camelCase)
            $response = [
                'projectInfo' => $procurementData['project_info'] ?? $procurementData->project_info,
                'budgetImported' => $procurementData['budget_imported'] ?? $procurementData->budget_imported,
                'procurementItems' => $procurementData['procurement_items'] ?? $procurementData->procurement_items ?? [],
                'budgetSummary' => $procurementData['budget_summary'] ?? $procurementData->budget_summary,
                'lastImportDate' => $procurementData['last_import_date'] ?? $procurementData->last_import_date
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Budget data imported successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor suggestions for a material
     *
     * @OA\Get(
     *     path="/api/projects/procurement/vendor-suggestions",
     *     summary="Get vendor suggestions",
     *     tags={"Procurement"},
     *     @OA\Parameter(
     *         name="description",
     *         in="query",
     *         required=true,
     *         description="Material description",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vendor suggestions retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="suggestions", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function getVendorSuggestions(Request $request): JsonResponse
    {
        $description = $request->query('description', '');

        $suggestions = $this->procurementService->getVendorSuggestions($description);

        return response()->json([
            'suggestions' => $suggestions,
            'message' => 'Vendor suggestions retrieved successfully'
        ]);
    }
}
