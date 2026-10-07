<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Requests\ImportMaterialRequest;
use App\Modules\MaterialsLibrary\Services\MaterialImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MaterialImportController extends Controller
{
    protected $importService;

    public function __construct(MaterialImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Handle the Excel import.
     */
    public function import(ImportMaterialRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $workstationId = $request->workstation_id;

            $results = $this->importService->import($file, $workstationId);

            return response()->json([
                'message' => $results['total'] === 0 ? 'The workbook has no material rows.'
                    : ($results['success'] === 0 ? 'No materials were imported. Check the row errors.'
                    : (count($results['errors']) ? 'Import completed with row errors.' : 'Import completed successfully.')),
                'data' => $results,
                'status' => $results['success'] === 0 ? 'failed' : (count($results['errors']) ? 'partial' : 'success'),
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed: '.$e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }
}
