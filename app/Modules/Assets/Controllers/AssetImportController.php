<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Requests\ImportAssetRequest;
use App\Modules\Assets\Services\AssetImportService;
use Illuminate\Http\JsonResponse;

class AssetImportController extends Controller
{
    protected $importService;

    public function __construct(AssetImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Handle the bulk CSV/XLSX import.
     */
    public function import(ImportAssetRequest $request): JsonResponse
    {
        try {
            $results = $this->importService->import($request->file('file'));

            return response()->json([
                'message' => 'Import processed successfully',
                'data' => $results,
                'status' => 'success',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage(),
                'status' => 'error',
            ], 500);
        }
    }
}
