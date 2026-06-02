<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Requests\StoreMaterialRequest;
use App\Modules\MaterialsLibrary\Requests\UpdateMaterialRequest;
use App\Modules\MaterialsLibrary\Resources\LibraryMaterialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    /**
     * Display a listing of the materials.
     */
    public function index(Request $request): JsonResponse
    {
        $materials = $this->buildMaterialQuery($request)->paginate($request->get('per_page', 50));
        
        $stats = [
            'total_value' => (float) LibraryMaterial::active()
                ->join('stocks', 'library_materials.id', '=', 'stocks.material_id')
                ->sum(DB::raw('stocks.quantity_on_hand * library_materials.unit_cost')),
            'low_stock_count' => (int) LibraryMaterial::active()
                ->whereHas('stock', function ($q) {
                    $q->where('min_stock_level', '>', 0)
                      ->whereRaw('(quantity_on_hand - quantity_reserved) <= min_stock_level');
                })->count(),
            'out_of_stock_count' => (int) LibraryMaterial::active()
                ->where(function ($q) {
                    $q->whereHas('stock', function ($sq) {
                        $sq->where('quantity_on_hand', '<=', 0);
                    })->orWhereDoesntHave('stock');
                })->count(),
        ];

        return response()->json(array_merge(
            $materials->toArray(),
            ['stats' => $stats]
        ));
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

        // Advanced Filters
        if ($request->filled('stock_status')) {
            $status = $request->stock_status;
            if ($status === 'low_stock') {
                $query->whereHas('stock', function ($q) {
                    $q->where('min_stock_level', '>', 0)
                      ->whereRaw('(quantity_on_hand - quantity_reserved) <= min_stock_level');
                });
            } elseif ($status === 'out_of_stock') {
                $query->where(function ($q) {
                    $q->whereHas('stock', function ($sq) {
                        $sq->where('quantity_on_hand', '<=', 0);
                    })->orWhereDoesntHave('stock');
                });
            } elseif ($status === 'optimal') {
                $query->whereHas('stock', function ($q) {
                    $q->whereRaw('(quantity_on_hand - quantity_reserved) > min_stock_level')
                      ->orWhere('min_stock_level', '<=', 0);
                });
            }
        }

        if ($request->filled('thickness')) {
            $query->where('attributes->attributes->thickness_size', $request->thickness);
        }

        if ($request->filled('supplier')) {
            $query->where('attributes->attributes->preferred_supplier', 'like', "%{$request->supplier}%");
        }

        if ($request->filled('location_bin')) {
            $query->whereHas('stock', function ($q) use ($request) {
                $q->where('location_bin', 'like', "%{$request->location_bin}%");
            });
        }

        // Sorting
        if ($request->filled('sort_by')) {
            $sortBy = $request->sort_by;
            $sortOrder = $request->get('sort_order', 'asc') === 'desc' ? 'desc' : 'asc';
            
            if (in_array($sortBy, ['quantity_on_hand', 'available', 'min_stock_level', 'location_bin'])) {
                $query->leftJoin('stocks', 'library_materials.id', '=', 'stocks.material_id')
                      ->select('library_materials.*');
                
                if ($sortBy === 'available') {
                    $query->orderByRaw('(stocks.quantity_on_hand - stocks.quantity_reserved) ' . $sortOrder);
                } elseif ($sortBy === 'quantity_on_hand') {
                    $query->orderBy('stocks.quantity_on_hand', $sortOrder);
                } elseif ($sortBy === 'min_stock_level') {
                    $query->orderBy('stocks.min_stock_level', $sortOrder);
                } elseif ($sortBy === 'location_bin') {
                    $query->orderBy('stocks.location_bin', $sortOrder);
                }
            } else {
                $allowedColumns = ['material_name', 'material_code', 'category', 'subcategory', 'unit_cost', 'created_at'];
                if (in_array($sortBy, $allowedColumns)) {
                    $query->orderBy('library_materials.' . $sortBy, $sortOrder);
                } else {
                    $query->latest('library_materials.created_at');
                }
            }
        } else {
            $query->latest('library_materials.created_at');
        }

        return $query;
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
