<?php

namespace App\Modules\MaterialsLibrary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\MaterialsLibrary\Requests\StoreMaterialRequest;
use App\Modules\MaterialsLibrary\Requests\UpdateMaterialRequest;
use App\Modules\MaterialsLibrary\Resources\LibraryMaterialResource;
use App\Modules\MaterialsLibrary\Services\MaterialDefaultsService;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $query = LibraryMaterial::with(['workstation', 'stock', 'materialCategory.parent', 'itemType', 'baseUom', 'purchaseUom', 'issueUom', 'uomConversions']);

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
            // Same rule as isBoardTrackable(), expressed once on the model.
            $query->boardTrackable();
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
    public function store(StoreMaterialRequest $request, MaterialDefaultsService $defaults): JsonResponse
    {
        $data = $request->validated();
        $conversions = $data['uom_conversions'] ?? [];
        unset($data['uom_conversions']);
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        // Let the taxonomy answer what it can before anything is asked of the
        // typist. Only gaps are filled; a supplied value always wins.
        $data = $defaults->apply($data);

        $category = MaterialCategory::with('parent')->find($data['material_category_id']);
        if (blank($data['material_code'] ?? null) && $category) {
            $workstationCode = ! empty($data['workstation_id'])
                ? \App\Modules\MaterialsLibrary\Models\Workstation::whereKey($data['workstation_id'])->value('code')
                : null;
            $data['material_code'] = $defaults->suggestCode($category, $workstationCode);
        }

        $data = $this->syncControlCompatibility($data);
        $data = $this->syncUomCompatibility($data);

        // Wrap attributes in 'attributes' key for JSON column if not already
        if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        // Keep legacy string fields in sync with the FK so both lookup paths agree.
        $data = $this->syncCategoryStrings($data);

        $requestedStatus = $data['item_status'] ?? null;
        $material = new LibraryMaterial($data);
        $material->setRelation('materialCategory', $category);

        // Anything short of the full governance set is born under review:
        // searchable and editable, but refused by checkIn() and adjustStock().
        $material->item_status = MaterialCompleteness::resolveStatus($material, $requestedStatus);
        $material->is_active = $material->item_status === 'Active';
        $material->save();

        $this->syncUomConversions($material, $conversions);

        $material->load('stock', 'materialCategory.parent', 'uomConversions');

        return response()->json([
            'message' => $material->item_status === 'Active'
                ? 'Material created and ready to use.'
                : 'Material saved as a draft. Complete its setup to start receiving and issuing it.',
            'data' => new LibraryMaterialResource($material),
            'missing' => MaterialCompleteness::missing($material),
        ], 201);
    }

    /**
     * Promote a draft to Active once its governance set is complete.
     * This is the only door between "exists in the catalogue" and "may move stock".
     */
    public function activate($id): JsonResponse
    {
        $material = LibraryMaterial::with('materialCategory.parent')->findOrFail($id);
        $missing = MaterialCompleteness::missing($material);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'item_status' => 'Still missing: '.implode(', ', array_values($missing)).'.',
            ]);
        }

        $material->update([
            'item_status' => 'Active',
            'is_active' => true,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => "{$material->material_name} is now active and can be received and issued.",
            'data' => new LibraryMaterialResource($material->load('stock')),
        ]);
    }

    /**
     * Everything still short of the governance set, grouped by what it needs.
     *
     * A draft-first catalogue needs somewhere to see the drafts — and applying
     * one missing field to a whole category at once is far cheaper than opening
     * four hundred records one at a time.
     */
    public function incomplete(Request $request): JsonResponse
    {
        $query = LibraryMaterial::with(['materialCategory.parent', 'workstation', 'itemType', 'baseUom'])
            ->where(function ($scope) {
                foreach (array_keys(MaterialCompleteness::GOVERNANCE) as $field) {
                    $scope->orWhereNull($field);
                }
                $scope->orWhere('item_status', 'Under Review');
            });

        if ($request->filled('search')) {
            $query->search((string) $request->search);
        }
        if ($request->filled('material_category_id')) {
            $query->where('material_category_id', $request->material_category_id);
        }

        $materials = $query->orderBy('material_name')->paginate($request->get('per_page', 50));

        $gaps = [];
        $rows = $materials->getCollection()->map(function (LibraryMaterial $material) use (&$gaps) {
            $missing = MaterialCompleteness::missing($material);
            foreach ($missing as $field => $label) {
                $gaps[$field] = ['field' => $field, 'label' => $label, 'count' => ($gaps[$field]['count'] ?? 0) + 1];
            }

            return [
                'id' => $material->id,
                'material_name' => $material->material_name,
                'material_code' => $material->material_code,
                'item_status' => $material->item_status,
                'category' => $material->materialCategory?->name ?? $material->category,
                'category_group' => $material->materialCategory?->parent?->name,
                'material_category_id' => $material->material_category_id,
                'unit_of_measure' => $material->baseUom?->code ?? $material->unit_of_measure,
                'missing' => $missing,
                'is_ready' => $missing === [],
            ];
        });
        $materials->setCollection($rows);

        return response()->json([
            'data' => $materials,
            // Sorted so the biggest single win is first.
            'gaps' => collect($gaps)->sortByDesc('count')->values(),
        ]);
    }

    /**
     * Apply one governance decision to many materials at once — the bulk repair
     * that makes an existing unclassified library tractable.
     */
    public function bulkControls(Request $request, MaterialDefaultsService $defaults): JsonResponse
    {
        $validated = $request->validate([
            'material_ids' => 'required|array|min:1',
            'material_ids.*' => 'integer|exists:library_materials,id',
            'material_category_id' => 'nullable|integer|exists:material_categories,id',
            'item_type_id' => 'nullable|integer|exists:material_item_types,id',
            'base_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'issue_disposition' => ['nullable', Rule::in(MaterialControl::DISPOSITIONS)],
            'tracking_mode' => ['nullable', Rule::in(MaterialControl::TRACKING_MODES)],
            'activate_when_complete' => 'sometimes|boolean',
        ]);

        $assignments = collect($validated)->except(['material_ids', 'activate_when_complete'])
            ->filter(fn ($value) => filled($value))->all();

        if ($assignments === [] ) {
            throw ValidationException::withMessages(['assignments' => 'Choose at least one setting to apply.']);
        }
        if (isset($assignments['issue_disposition'], $assignments['tracking_mode'])
            && ! MaterialControl::compatible($assignments['issue_disposition'], $assignments['tracking_mode'])) {
            throw ValidationException::withMessages([
                'tracking_mode' => "Tracking mode [{$assignments['tracking_mode']}] is not compatible with [{$assignments['issue_disposition']}].",
            ]);
        }

        $updated = 0;
        $activated = 0;

        DB::transaction(function () use ($validated, $assignments, $defaults, &$updated, &$activated) {
            $materials = LibraryMaterial::with('materialCategory.parent')
                ->whereIn('id', $validated['material_ids'])->get();

            foreach ($materials as $material) {
                // An item whose tracking mode is already pinned by board history
                // must not be rewritten in bulk — that guard exists for a reason.
                if (isset($assignments['tracking_mode'])
                    && $assignments['tracking_mode'] !== 'dimension_piece'
                    && Board::where('library_material_id', $material->id)->exists()) {
                    continue;
                }
                // Base UOM is immutable once stock has moved, in bulk as much as singly.
                $changes = $assignments;
                if (isset($changes['base_uom_id'])
                    && (int) $changes['base_uom_id'] !== (int) $material->base_uom_id
                    && InventoryLog::where('material_id', $material->id)->exists()) {
                    unset($changes['base_uom_id']);
                }
                if ($changes === []) {
                    continue;
                }

                $data = $defaults->apply($changes, $material);
                $data = $this->syncControlCompatibility($data, $material);
                $data = $this->syncUomCompatibility($data, $material);
                $data = $this->syncCategoryStrings($data);
                $data['updated_by'] = auth()->id();

                $material->fill($data);
                $material->save();
                $updated++;

                if (($validated['activate_when_complete'] ?? false)
                    && $material->item_status !== 'Active'
                    && MaterialCompleteness::isComplete($material->fresh()->load('materialCategory.parent'))) {
                    $material->update(['item_status' => 'Active', 'is_active' => true]);
                    $activated++;
                }
            }
        });

        return response()->json([
            'message' => "{$updated} material(s) updated".($activated > 0 ? ", {$activated} activated." : '.'),
            'updated' => $updated,
            'activated' => $activated,
        ]);
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
        $hasConversions = array_key_exists('uom_conversions', $data);
        $conversions = $data['uom_conversions'] ?? [];
        unset($data['uom_conversions']);
        $data['updated_by'] = auth()->id();
        $data = $this->syncControlCompatibility($data, $material);
        $data = $this->syncUomCompatibility($data, $material);

        $nextTrackingMode = $data['tracking_mode'] ?? $material->tracking_mode;
        $nextDisposition = $data['issue_disposition'] ?? $material->issue_disposition;
        if (($nextTrackingMode !== 'dimension_piece' || $nextDisposition !== 'recoverable_remainder')
            && Board::where('library_material_id', $material->id)->exists()) {
            throw ValidationException::withMessages([
                'tracking_mode' => 'This item has board history and must remain a measured/recoverable board item.',
            ]);
        }

        $nextBaseUomId = $data['base_uom_id'] ?? $material->base_uom_id;
        if ((int) $nextBaseUomId !== (int) $material->base_uom_id
            && InventoryLog::where('material_id', $material->id)->exists()) {
            throw ValidationException::withMessages([
                'base_uom_id' => 'Base UOM cannot be changed after stock movements exist. Configure a purchase/issue conversion instead.',
            ]);
        }

         // Wrap attributes in 'attributes' key for JSON column if not already
         if (isset($data['attributes']) && !isset($data['attributes']['attributes'])) {
             $data['attributes'] = ['attributes' => $data['attributes']];
        }

        $data = $this->syncCategoryStrings($data);

        DB::transaction(function () use ($material, $data, $hasConversions, $conversions) {
            $material->update($data);
            if ($hasConversions) {
                $this->syncUomConversions($material, $conversions);
            }

            // An edit can change category and therefore its inherited required
            // specifications. Never leave an incomplete item marked Active:
            // keep it searchable, return it to the finishing queue, and let the
            // existing movement gates prevent ambiguous stock behaviour.
            $material->load('materialCategory.parent');
            $resolvedStatus = MaterialCompleteness::resolveStatus(
                $material,
                $data['item_status'] ?? $material->item_status,
            );
            $material->forceFill([
                'item_status' => $resolvedStatus,
                'is_active' => $resolvedStatus === 'Active',
            ])->save();

            // stocks.tracking_mode is retained for legacy board endpoints, but is
            // always projected from the governed Material Library controls.
            $material->stock?->update([
                'tracking_mode' => $material->fresh()->isBoardTrackable()
                    ? Stock::TRACK_BY_AREA
                    : Stock::TRACK_BY_COUNT,
            ]);
        });
        $material->load('stock');

        return response()->json([
            'message' => $material->fresh()->item_status === 'Active'
                ? 'Material updated successfully.'
                : 'Material updated and returned to Needs finishing because its governance data is incomplete.',
            'data' => new LibraryMaterialResource($material->fresh()->load('materialCategory.parent', 'uomConversions')),
            'missing' => MaterialCompleteness::missing($material->fresh()->load('materialCategory.parent')),
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

    /** Keep only the practical purchase/issue -> stock conversions supplied by the form. */
    private function syncUomConversions(LibraryMaterial $material, array $conversions): void
    {
        $baseUomId = (int) $material->base_uom_id;
        MaterialUomConversion::where('material_id', $material->id)->delete();

        foreach ($conversions as $conversion) {
            $fromUomId = (int) $conversion['from_uom_id'];
            if (! $baseUomId || $fromUomId === $baseUomId) {
                continue;
            }

            MaterialUomConversion::create([
                'material_id' => $material->id,
                'from_uom_id' => $fromUomId,
                'to_uom_id' => $baseUomId,
                'factor' => $conversion['factor'],
            ]);
        }
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
        $boardCount = Board::where('library_material_id', $id)->count();

        if ($boardCount > 0) {
            $activeBoards = Board::where('library_material_id', $id)
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
