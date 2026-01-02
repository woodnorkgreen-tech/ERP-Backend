<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Resources\WorkstationResource;
use Illuminate\Http\JsonResponse;

use App\Modules\MaterialsLibrary\Services\MaterialSchemaService;

class WorkstationController extends Controller
{
    protected $schemaService;

    public function __construct(MaterialSchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Display a listing of the workstations.
     */
    public function index(): JsonResponse
    {
        $workstations = Workstation::active()->ordered()->get();
        return response()->json([
            'data' => WorkstationResource::collection($workstations)
        ]);
    }

    /**
     * Get the schema for a workstation.
     */
    public function schema($id): JsonResponse
    {
        $workstation = Workstation::findOrFail($id);
        $schema = $this->schemaService->getSchema($workstation);
        
        return response()->json([
            'data' => $schema
        ]);
    }

    /**
     * Display the specified workstation.
     */
    public function show($id): JsonResponse
    {
        $workstation = Workstation::findOrFail($id);
        return response()->json([
            'data' => new WorkstationResource($workstation)
        ]);
    }
}
