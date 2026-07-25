<?php

namespace App\Http\Controllers;

use App\Models\TaskProcurementData;
use App\Services\ProcurementService;
use App\Modules\Projects\Actions\AutoSyncTaskStateAction;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @OA\Tag(
 *     name="Procurement",
 *     description="Procurement management endpoints"
 * )
 */
class ProcurementController extends Controller
{
    private ProcurementService $procurementService;

    public function __construct(
        ProcurementService $procurementService,
        private readonly AutoSyncTaskStateAction $autoSyncTaskState
    ) {
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
            $user = $request->user();
            $isSystemAdmin = $user->hasRole(['Admin', 'Super Admin']);
            $isStores = $user->hasRole(['Stores', 'Store Keeper']);
            $isProcurement = $user->hasRole(['Procurement', 'Procurement Officer']);

            // Only designated roles can save procurement data
            if (!$isSystemAdmin && !$isStores && !$isProcurement) {
                return response()->json([
                    'message' => 'Unauthorized: Only Stores, Procurement, or Admin users can save procurement data.'
                ], 403);
            }

            // Validate the request data
            $data = $request->validate([
                'projectInfo' => 'sometimes|array',
                'budgetImported' => 'sometimes|boolean',
                'procurementItems' => 'required|array',
                'procurementItems.*.budgetItemId' => 'required|string',
                'procurementItems.*.description' => 'required|string',
                'procurementItems.*.stockStatus' => 'sometimes|in:in_stock,partial_stock,out_of_stock,pending_check',
                'procurementItems.*.stockQuantity' => 'sometimes|numeric|min:0',
                'procurementItems.*.procurementStatus' => 'sometimes|in:not_needed,pending,ordered,received,cancelled',
                'procurementItems.*.purchaseQuantity' => 'sometimes|numeric|min:0',
                'procurementItems.*.purchaseOrderNumber' => 'sometimes|nullable|string',
                'procurementItems.*.expectedDeliveryDate' => 'sometimes|nullable|date',
                'procurementItems.*.availabilityStatus' => 'sometimes|string',
                'procurementItems.*.vendorName' => 'sometimes|nullable|string',
                'procurementItems.*.procurementNotes' => 'sometimes|nullable|string',
                'procurementItems.*.budgetElementPersistentId' => 'sometimes|nullable|string',
                'procurementItems.*.budgetItemPersistentId' => 'sometimes|nullable|string',
                'procurementItems.*.budgetDataId' => 'sometimes|nullable|integer',
                'procurementItems.*.procurementLinks' => 'sometimes|array',
                'procurementItems.*.operationalSync' => 'sometimes|nullable|array',
                'procurementItems.*.operationalStage' => 'sometimes|nullable|string',
                'budgetSummary' => 'sometimes|array',
                'budgetSummary.operationalSync' => 'sometimes|nullable|array',
                'lastImportDate' => 'sometimes|date'
            ]);

            // Get existing data for comparison
            $existingData = $this->procurementService->getProcurementData($taskId);
            $existingItems = collect($existingData ? $existingData->procurement_items : [])->keyBy('budgetItemId');

            // Field-level checks removed as per user requirement to allow both roles to save procurement data.
            // We still only allow authorized roles to hit the save logic at the top.
            
            $procurementData = $this->procurementService->saveProcurementData($taskId, $data);

            if ($task = EnquiryTask::find($taskId)) {
                $this->autoSyncTaskState->execute($task);
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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Procurement task not found',
                'error' => $e->getMessage()
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 409);
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
    /**
     * Download procurement list as PDF
     *
     * @OA\Get(
     *     path="/api/projects/tasks/{taskId}/procurement/pdf",
     *     summary="Download procurement PDF",
     *     tags={"Procurement"},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="PDF generated and downloaded"),
     *     @OA\Response(response=404, description="Procurement data not found")
     * )
     */
    public function downloadPdf(int $taskId)
    {
        try {
            $task = \App\Modules\Projects\Models\EnquiryTask::with('enquiry.client')->findOrFail($taskId);
            
            // Use service to get data to ensure sync with budget
            $procurementData = $this->procurementService->getProcurementData($taskId);
            
            if (!$procurementData) {
                return response()->json(['message' => 'Procurement data not found'], 404);
            }

            // Load view with data
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.procurement', [
                'procurementData' => $procurementData,
                'enquiry' => $task->enquiry
            ]);

            $fileName = 'procurement-' . ($task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? $taskId) . '.pdf';
            
            return $pdf->download($fileName);
        } catch (\Exception $e) {
            \Log::error('Failed to generate procurement PDF', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to generate PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

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
