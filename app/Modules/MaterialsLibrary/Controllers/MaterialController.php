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
        $query = LibraryMaterial::with('workstation');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        } else {
            $query->active();
        }

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('search')) {
            $query->search((string) $request->search);
        }

        $materials = $query->latest()->paginate($request->get('per_page', 50));
        
        return response()->json($materials); // Pagination response
    }

    /**
     * Display materials for a specific workstation.
     */
    public function byWorkstation($workstationId, Request $request): JsonResponse
    {
        try {
            $query = LibraryMaterial::with('workstation')
                ->where('workstation_id', $workstationId);

            if ($request->boolean('with_trashed')) {
                $query->withTrashed();
            } else {
                $query->active();
            }

            if ($request->has('search')) {
                $query->search((string) $request->search);
            }

            $materials = $query->latest()->get(); // Or paginate

            return response()->json([
                'data' => LibraryMaterialResource::collection($materials)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching materials',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
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
        $material = LibraryMaterial::with('workstation')->findOrFail($id);
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
        $query = LibraryMaterial::onlyTrashed()->with('workstation');

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
