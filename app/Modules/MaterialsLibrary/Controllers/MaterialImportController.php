<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Requests\ImportMaterialRequest;
use App\Modules\MaterialsLibrary\Services\MaterialImportService;
use Illuminate\Http\JsonResponse;

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
                'message' => 'Import processed successfully',
                'data' => $results,
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }
}
