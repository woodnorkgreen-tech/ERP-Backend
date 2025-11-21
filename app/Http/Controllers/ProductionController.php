<?php

namespace App\Http\Controllers;

use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductionController extends Controller
{
    protected ProductionService $productionService;

    public function __construct(ProductionService $productionService)
    {
        $this->productionService = $productionService;
    }

    /**
     * Get production data for a task
     * 
     * @param int $taskId
     * @return JsonResponse
     */
    public function getProductionData(int $taskId): JsonResponse
    {
        try {
            $productionData = $this->productionService->getProductionData($taskId);

            if (!$productionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Production data not found. Please import materials first.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $productionData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get production data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve production data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import materials data from Materials Task
     * 
     * @param int $taskId
     * @return JsonResponse
     */
    public function importMaterialsData(int $taskId): JsonResponse
    {
        try {
            $productionData = $this->productionService->importMaterialsData($taskId);

            return response()->json([
                'success' => true,
                'message' => 'Materials imported successfully',
                'data' => $productionData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to import materials: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to import materials',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save production data (quality checkpoints, issues, completion criteria)
     * 
     * @param Request $request
     * @param int $taskId
     * @return JsonResponse
     */
    public function saveProductionData(Request $request, int $taskId): JsonResponse
    {
        try {
            $data = $request->all();
            
            $productionData = $this->productionService->saveProductionData($taskId, $data);

            return response()->json([
                'success' => true,
                'message' => 'Production data saved successfully',
                'data' => $productionData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to save production data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to save production data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate quality checkpoints from production elements
     * 
     * @param int $taskId
     * @return JsonResponse
     */
    public function generateQualityCheckpoints(int $taskId): JsonResponse
    {
        try {
            $checkpoints = $this->productionService->generateQualityCheckpoints($taskId);

            return response()->json([
                'success' => true,
                'message' => 'Quality checkpoints generated successfully',
                'data' => $checkpoints
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate quality checkpoints: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate quality checkpoints',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
