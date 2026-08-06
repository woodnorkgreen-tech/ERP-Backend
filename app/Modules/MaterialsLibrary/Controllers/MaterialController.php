<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Requests\StoreMaterialRequest;
use App\Modules\MaterialsLibrary\Requests\UpdateMaterialRequest;
use App\Modules\MaterialsLibrary\Resources\LibraryMaterialResource;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
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
        $materials->setCollection(
            $materials->getCollection()
                ->map(fn (LibraryMaterial $material) => (new LibraryMaterialResource($material))->resolve($request))
        );
        
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
        $materials->setCollection(
            $materials->getCollection()
                ->map(fn (LibraryMaterial $material) => (new LibraryMaterialResource($material))->resolve($request))
        );

        return response()->json($materials);
    }

    /**
     * Shared query builder used by index() and byWorkstation().
     */
    private function buildMaterialQuery(Request $request, ?int $workstationId = null)
    {
        $query = LibraryMaterial::with(['workstation', 'stock', 'materialCategory.parent', 'itemType', 'baseUom']);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        } else {
            $query->active();
        }

        if ($workstationId) {
            $query->where('workstation_id', $workstationId);
        }

        if ($request->filled('category')) {
            // Match on the legacy string column OR via the normalised category FK so
            // materials registered through either path are equally discoverable.
            $query->where(function ($q) use ($request) {
                $q->where('category', $request->category)
                  ->orWhereHas('materialCategory', function ($cq) use ($request) {
                      $cq->where('name', $request->category)
                         ->orWhereHas('parent', fn ($pq) => $pq->where('name', $request->category));
                  });
            });
        }

        if ($request->filled('subcategory')) {
            $query->where(function ($q) use ($request) {
                $q->where('subcategory', $request->subcategory)
                  ->orWhereHas('materialCategory', fn ($cq) => $cq->where('name', $request->subcategory));
            });
        }

        if ($request->filled('material_type')) {
            $query->where('material_type', $request->material_type);
        }

        if ($request->boolean('board_trackable')) {
            $eligible = config('boards.tracking_categories', ['Boards', 'Sheet Materials', 'Veneer']);
            $query->where('material_type', 'reusable')
                ->where(function ($q) use ($eligible) {
                    $q->whereIn('category', $eligible)
                        ->orWhereHas('materialCategory', function ($categoryQuery) use ($eligible) {
                            $categoryQuery->whereIn('name', $eligible)
                                ->orWhereHas('parent', fn ($parentQuery) => $parentQuery->whereIn('name', $eligible));
                        });
                });
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
        $data = $this->syncControlCompatibility($data);
        $data = $this->syncUomCompatibility($data);

        // Wrap attributes in 'attributes' key for JSON column if not already
        if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        // Keep legacy string fields in sync with the FK so both lookup paths agree.
        $data = $this->syncCategoryStrings($data);

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
        $data = $this->syncControlCompatibility($data, $material);
        $data = $this->syncUomCompatibility($data, $material);

         // Wrap attributes in 'attributes' key for JSON column if not already
         if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $data = $this->syncCategoryStrings($data);

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
     * When material_category_id is provided, write matching values into the legacy
     * category/subcategory string columns so both lookup paths stay consistent.
     * The root category name maps to `category`; the leaf name maps to `subcategory`.
     */
    private function syncCategoryStrings(array $data): array
    {
        if (empty($data['material_category_id'])) {
            return $data;
        }

        $cat = MaterialCategory::with('parent')->find($data['material_category_id']);
        if (!$cat) {
            return $data;
        }

        if ($cat->parent) {
            // Leaf category: parent is the root
            $data['category']    = $cat->parent->name;
            $data['subcategory'] = $cat->name;
        } else {
            // Root category: use it directly, leave subcategory untouched
            $data['category'] = $cat->name;
            $data['subcategory'] = null;
        }

        return $data;
    }

    private function syncControlCompatibility(array $data, ?LibraryMaterial $material = null): array
    {
        $disposition = $data['issue_disposition'] ?? $material?->issue_disposition ?? 'consumed';
        $data['material_type'] = MaterialControl::legacyMaterialType($disposition);

        if (isset($data['item_status'])) {
            $data['is_active'] = $data['item_status'] === 'Active';
        } elseif (array_key_exists('is_active', $data)) {
            $data['item_status'] = $data['is_active'] ? 'Active' : 'Inactive';
        }

        return $data;
    }

    private function syncUomCompatibility(array $data, ?LibraryMaterial $material = null): array
    {
        $baseUomId = $data['base_uom_id'] ?? $material?->base_uom_id;
        if ($baseUomId) {
            $baseUom = UnitOfMeasure::where('is_active', true)->findOrFail($baseUomId);
            $data['unit_of_measure'] = $baseUom->code; // keep legacy consumers synchronized
            $data['issue_uom_id'] ??= $baseUomId;
        }
        return $data;
    }

    /**
     * Permanently delete a material.
     * Restricted to Super Admin and only allowed when no active board records exist,
     * because library_materials → boards has cascadeOnDelete() which would destroy
     * the complete audit trail for every board ever made from this material.
     */
    public function forceDelete($id): JsonResponse
    {
        if (!auth()->user()?->hasRole('Super Admin')) {
            return response()->json(['message' => 'Only Super Admins can permanently delete materials.'], 403);
        }

        $material = LibraryMaterial::withTrashed()->findOrFail($id);

        // library_materials → boards is cascadeOnDelete, so a force-delete here wipes
        // EVERY board record (and its board_movements) ever made from this material —
        // including the Consumed/Scrapped history that is the asset audit trail.
        // Block while ANY board references the material, not just active ones.
        $boardCount = \App\Modules\ProcurementStores\Models\Board::where('library_material_id', $id)->count();

        if ($boardCount > 0) {
            $activeBoards = \App\Modules\ProcurementStores\Models\Board::where('library_material_id', $id)
                ->whereNotIn('status', ['Consumed', 'Scrapped'])
                ->count();

            $detail = $activeBoards > 0
                ? "{$boardCount} board(s) are linked to this material ({$activeBoards} still active)."
                : "{$boardCount} historical board record(s) are linked to this material.";

            return response()->json([
                'message' => "Cannot permanently delete — {$detail} "
                    . 'Permanently deleting would erase the board tracking history for this material. '
                    . 'Keep it soft-deleted instead.',
            ], 422);
        }

        $material->forceDelete();

        return response()->json(['message' => 'Material permanently deleted']);
    }

}
