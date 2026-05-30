<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Requests\StoreMaterialRequest;
use App\Modules\MaterialsLibrary\Requests\UpdateMaterialRequest;
use App\Modules\MaterialsLibrary\Resources\LibraryMaterialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    /**
     * Display a listing of the materials.
     */
    public function index(Request $request): JsonResponse
    {
        $materials = $this->buildMaterialQuery($request)->paginate($request->get('per_page', 50));
        return response()->json($materials);
    }

    /**
     * Display materials for a specific workstation.
     */
    public function byWorkstation($workstationId, Request $request): JsonResponse
    {
        $materials = $this->buildMaterialQuery($request, $workstationId)
            ->paginate($request->get('per_page', 50));

        return response()->json($materials);
    }

    /**
     * Shared query builder used by index() and byWorkstation().
     */
    private function buildMaterialQuery(Request $request, ?int $workstationId = null)
    {
        $query = LibraryMaterial::with(['workstation', 'stock']);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        } else {
            $query->active();
        }

        if ($workstationId) {
            $query->where('workstation_id', $workstationId);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('subcategory')) {
            $query->where('subcategory', $request->subcategory);
        }

        if ($request->filled('material_type')) {
            $query->where('material_type', $request->material_type);
        }

        if ($request->filled('search')) {
            $query->search((string) $request->search);
        }

        return $query->latest();
    }

    /**
     * Store a newly created material in storage.
     */
    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        // Wrap attributes in 'attributes' key for JSON column if not already
        if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $material = LibraryMaterial::create($data);
        $material->load('stock');

        return response()->json([
            'message' => 'Material created successfully',
            'data' => new LibraryMaterialResource($material)
        ], 201);
    }

    /**
     * Display the specified material.
     */
    public function show($id): JsonResponse
    {
        $material = LibraryMaterial::with(['workstation', 'stock'])->findOrFail($id);
        return response()->json([
            'data' => new LibraryMaterialResource($material)
        ]);
    }

    /**
     * Update the specified material in storage.
     */
    public function update(UpdateMaterialRequest $request, $id): JsonResponse
    {
        $material = LibraryMaterial::findOrFail($id);
        
        $data = $request->validated();
        $data['updated_by'] = auth()->id();

         // Wrap attributes in 'attributes' key for JSON column if not already
         if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
            // Merge with existing or overwrite? simpler to overwrite structure
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $material->update($data);
        $material->load('stock');

        return response()->json([
            'message' => 'Material updated successfully',
            'data' => new LibraryMaterialResource($material)
        ]);
    }

    /**
     * Remove the specified material from storage.
     */
    public function destroy($id): JsonResponse
    {
        $material = LibraryMaterial::findOrFail($id);
        $material->delete();

        return response()->json([
            'message' => 'Material deleted successfully'
        ]);
    }

    /**
     * Display a listing of trashed materials.
     */
    public function trashed(Request $request): JsonResponse
    {
        $query = LibraryMaterial::onlyTrashed()->with(['workstation', 'stock']);

        if ($request->has('search')) {
            $query->search((string) $request->search);
        }

        $materials = $query->latest()->get();

        return response()->json([
            'data' => LibraryMaterialResource::collection($materials)
        ]);
    }

    /**
     * Restore a trashed material.
     */
    public function restore($id): JsonResponse
    {
        $material = LibraryMaterial::withTrashed()->findOrFail($id);
        $material->restore();

        return response()->json([
            'message' => 'Material restored successfully',
            'data' => new LibraryMaterialResource($material)
        ]);
    }

    /**
     * Permanently delete a material.
     */
    public function forceDelete($id): JsonResponse
    {
        $material = LibraryMaterial::withTrashed()->findOrFail($id);
        $material->forceDelete();

        return response()->json([
            'message' => 'Material permanently deleted'
        ]);
    }

}
