<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StockCount;
use App\Modules\ProcurementStores\Models\StockCountItem;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    private function storesUser(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin']), 403, 'Only Stores team members can manage stock counts.');
    }

    private function reviewUser(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'A Manager must review stock-count variances.');
    }

    public function index(): JsonResponse
    {
        $this->storesUser();
        $rows = StockCount::with(['creator:id,name', 'reviewer:id,name'])->withCount('items')->latest()->limit(50)->get();
        return response()->json(['data' => $rows]);
    }

    public function show(StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        return response()->json(['data' => $stockCount->load([
            'creator:id,name', 'submitter:id,name', 'reviewer:id,name',
            'items.material.baseUom:id,code,name',
            'items.material.materialCategory.parent',
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->storesUser();
        $validated = $request->validate([
            'mode' => 'sometimes|string|in:cycle_count,opening_inventory',
            'warehouse_code' => 'nullable|string|max:40',
            'counted_on' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);
        $mode = $validated['mode'] ?? StockCount::MODE_CYCLE;

        if ($mode === StockCount::MODE_OPENING) {
            $open = StockCount::where('mode', StockCount::MODE_OPENING)
                ->whereIn('status', ['draft', 'submitted'])->first();
            if ($open) {
                return response()->json([
                    'message' => "Opening inventory {$open->count_number} is already {$open->status}. Complete or reject it before starting another.",
                    'stock_count_id' => $open->id,
                ], 422);
            }
            if (StockCount::where('mode', StockCount::MODE_OPENING)->where('status', 'approved')->exists()) {
                return response()->json([
                    'message' => 'Opening inventory has already been approved. Use a normal physical stock count for later reconciliations.',
                ], 422);
            }
        }

        $count = DB::transaction(function () use ($validated, $mode) {
            $prefix = $mode === StockCount::MODE_OPENING ? 'OPEN' : 'SC';
            $count = StockCount::create([
                'count_number' => $prefix.'-'.now()->format('Ymd-His').'-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'mode' => $mode,
                'warehouse_code' => $validated['warehouse_code'] ?? 'MAIN',
                'status' => 'draft',
                'counted_on' => $validated['counted_on'],
                'catalogue_snapshot_at' => $mode === StockCount::MODE_OPENING ? now() : null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            if ($mode === StockCount::MODE_OPENING) {
                $materials = $this->activeMaterialsQuery()
                    ->with(['materialCategory.parent', 'baseUom'])
                    ->orderBy('material_name')->get();

                if ($materials->isEmpty()) {
                    throw ValidationException::withMessages([
                        'materials' => 'There are no Active Material Library items to initialize.',
                    ]);
                }

                $stocks = Stock::withTrashed()->whereIn('material_id', $materials->pluck('id'))
                    ->get()->keyBy('material_id');

                foreach ($materials as $material) {
                    $stock = $stocks->get($material->id);
                    $systemQuantity = (float) ($stock?->quantity_on_hand ?? 0);
                    $entryMethod = $this->entryMethod($material);
                    $count->items()->create([
                        'material_id' => $material->id,
                        'entry_method' => $entryMethod,
                        'material_updated_at_snapshot' => $material->updated_at,
                        'system_quantity' => $systemQuantity,
                        // Controlled identities cannot be reset by typing an
                        // aggregate number. Preserve their current aggregate and
                        // use Receive Stock after opening approval to add identities.
                        'counted_quantity' => $entryMethod === 'bulk_quantity' ? null : $systemQuantity,
                        'variance_quantity' => $entryMethod === 'bulk_quantity' ? null : 0,
                        'opening_unit_cost' => $this->effectiveUnitCost($material),
                        'location_bin' => $stock?->location_bin,
                    ]);
                }
            } else {
                Stock::with('material')->where('warehouse_code', $count->warehouse_code)->get()
                    ->filter(fn (Stock $stock) => $stock->material
                        && ! $stock->material->isBoardTrackable()
                        && ! $stock->material->is_serialized
                        && ! $stock->material->is_batch_controlled
                        && ! $stock->material->is_expiry_controlled)
                    ->each(fn (Stock $stock) => $count->items()->create([
                        'material_id' => $stock->material_id,
                        'entry_method' => 'bulk_quantity',
                        'material_updated_at_snapshot' => $stock->material?->updated_at,
                        'system_quantity' => $stock->quantity_on_hand,
                        'location_bin' => $stock->location_bin,
                    ]));
            }
            return $count;
        });
        return $this->show($count);
    }

    public function update(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        if ($stockCount->status !== 'draft') return response()->json(['message' => 'Only a draft count can be edited.'], 422);
        $validated = $request->validate([
            'items' => 'required|array|min:1', 'items.*.id' => 'required|integer|distinct',
            'items.*.counted_quantity' => 'required|numeric|min:0',
            'items.*.opening_unit_cost' => 'nullable|numeric|min:0',
            'items.*.location_bin' => 'nullable|string|max:100',
            'items.*.variance_reason' => 'nullable|string|max:500',
        ]);
        DB::transaction(function () use ($stockCount, $validated) {
            if (count($validated['items']) !== $stockCount->items()->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Save every material in this count; partial opening inventory updates are not allowed.',
                ]);
            }
            foreach ($validated['items'] as $input) {
                $item = $stockCount->items()->whereKey($input['id'])->lockForUpdate()->firstOrFail();
                $counted = (float) $input['counted_quantity'];
                $system = (float) $item->system_quantity;
                $variance = $counted - $system;

                if ($stockCount->mode === StockCount::MODE_OPENING && $item->entry_method !== 'bulk_quantity'
                    && abs($variance) > 0.000001) {
                    throw ValidationException::withMessages([
                        'items' => "{$item->material?->material_name} is {$this->entryMethodLabel($item->entry_method)}. Its physical identities must be entered through Receive Stock, not as one aggregate opening quantity.",
                    ]);
                }

                $item->update([
                    'counted_quantity' => $counted,
                    'variance_quantity' => $variance,
                    'opening_unit_cost' => $input['opening_unit_cost'] ?? $item->opening_unit_cost,
                    'location_bin' => $input['location_bin'] ?? $item->location_bin,
                    'variance_reason' => $input['variance_reason'] ?? ($stockCount->mode === StockCount::MODE_OPENING && abs($variance) > 0.000001
                        ? 'Opening inventory baseline'
                        : null),
                ]);
            }
        });
        return $this->show($stockCount->fresh());
    }

    public function submit(StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        if ($stockCount->status !== 'draft') return response()->json(['message' => 'Only a draft count can be submitted.'], 422);
        if ($stockCount->items()->whereNull('counted_quantity')->exists()) throw ValidationException::withMessages(['items' => 'Enter the physical quantity for every material before submitting.']);
        if ($stockCount->mode === StockCount::MODE_OPENING) {
            $this->validateOpeningInventory($stockCount);
        } elseif ($stockCount->items()->where('variance_quantity', '!=', 0)->where(fn ($q) => $q->whereNull('variance_reason')->orWhere('variance_reason', ''))->exists()) {
            throw ValidationException::withMessages(['items' => 'Explain every difference before submitting the count.']);
        }
        $stockCount->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now()]);
        return response()->json(['message' => 'Stock count submitted for manager review.', 'data' => $stockCount]);
    }

    public function approve(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->reviewUser();
        if ($stockCount->status !== 'submitted') return response()->json(['message' => 'Only a submitted count can be approved.'], 422);
        if ((int) $stockCount->created_by === (int) auth()->id()) return response()->json(['message' => 'The person who created the count cannot approve its adjustments.'], 422);
        $validated = $request->validate(['review_notes' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($stockCount, $validated) {
            $locked = StockCount::with('items.material')->lockForUpdate()->findOrFail($stockCount->id);
            if ($locked->mode === StockCount::MODE_OPENING) {
                $this->validateOpeningInventory($locked);
            }
            foreach ($locked->items as $item) {
                $variance = (float) $item->variance_quantity;
                $stock = $locked->mode === StockCount::MODE_OPENING
                    ? $this->openingStockRow($locked, $item)
                    : Stock::where('material_id', $item->material_id)->lockForUpdate()->firstOrFail();
                if (abs((float) $stock->quantity_on_hand - (float) $item->system_quantity) > 0.000001) {
                    throw ValidationException::withMessages(['count' => 'Stock changed after counting. Start a new count so no later movement is overwritten.']);
                }

                if ($locked->mode === StockCount::MODE_OPENING && (float) $item->counted_quantity > 0
                    && (float) $item->opening_unit_cost > 0) {
                    $item->material->update(['unit_cost' => $item->opening_unit_cost]);
                }

                if (abs($variance) >= 0.000001) {
                    $log = app(InventoryService::class)->adjustStock($item->material_id, $variance, 'adjustment', [
                        'reference_no' => $locked->count_number,
                        'notes' => $locked->mode === StockCount::MODE_OPENING
                            ? 'Approved opening inventory: '.($item->variance_reason ?: 'initial physical count')
                            : 'Approved physical count variance: '.$item->variance_reason,
                        'warehouse_code' => $locked->warehouse_code,
                        'location' => $item->location_bin,
                        'receipt_unit_cost' => $locked->mode === StockCount::MODE_OPENING ? $item->opening_unit_cost : null,
                        'opening_inventory' => $locked->mode === StockCount::MODE_OPENING,
                        'logged_at' => now(),
                    ]);
                    $item->update(['adjustment_log_id' => $log->id]);
                }
            }
            $locked->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => $validated['review_notes'] ?? null]);
        });
        return response()->json(['message' => $stockCount->mode === StockCount::MODE_OPENING
            ? 'Opening inventory approved. Active catalogue items are now initialized in Stores; receive tracked lots, serials and boards through Receive Stock.'
            : 'Count approved and stock variances posted once.']);
    }

    public function reject(Request $request, StockCount $stockCount): JsonResponse
    {
        if ($stockCount->status === 'draft') {
            $this->storesUser();
            abort_unless(
                (int) $stockCount->created_by === (int) auth()->id()
                    || auth()->user()?->hasAnyRole(['Manager', 'Super Admin']),
                403,
                'Only the creator or a Manager can discard this draft.'
            );
        } else {
            $this->reviewUser();
        }
        if (! in_array($stockCount->status, ['draft', 'submitted'], true)) {
            return response()->json(['message' => 'Only a draft or submitted count can be rejected.'], 422);
        }
        $validated = $request->validate(['review_notes' => 'required|string|min:5|max:1000']);
        $wasDraft = $stockCount->status === 'draft';
        $stockCount->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => $validated['review_notes']]);
        return response()->json(['message' => $wasDraft
            ? 'Draft discarded. You can now start a fresh inventory session.'
            : 'Count rejected. Start a new count after correcting the problem.']);
    }

    public function destroy(StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        abort_unless(
            (int) $stockCount->created_by === (int) auth()->id()
                || auth()->user()?->hasAnyRole(['Manager', 'Super Admin']),
            403,
            'Only the creator or a Manager can delete this inventory session.'
        );
        if (! in_array($stockCount->status, ['draft', 'rejected'], true)) {
            return response()->json([
                'message' => 'Only a draft or discarded session can be deleted. Submitted and approved sessions are permanent records.',
            ], 422);
        }
        // An approved session is the source of its posted ledger movements.
        // Never delete a worksheet that any inventory log still traces back to.
        if ($stockCount->items()->whereNotNull('adjustment_log_id')->exists()) {
            return response()->json([
                'message' => 'This session already posted stock movements and cannot be deleted.',
            ], 422);
        }
        $number = $stockCount->count_number;
        DB::transaction(fn () => $stockCount->delete());
        return response()->json(['message' => "Inventory session {$number} deleted."]);
    }

    private function activeMaterialsQuery(): Builder
    {
        return LibraryMaterial::query()->where(function ($active) {
            $active->where('item_status', 'Active')
                ->orWhere(fn ($legacy) => $legacy->whereNull('item_status')->where('is_active', true));
        });
    }

    private function entryMethod(LibraryMaterial $material): string
    {
        if ($material->isBoardTrackable()) return 'dimension_piece';
        if ($material->is_serialized) return 'serialized_item';
        if ($material->is_batch_controlled || $material->is_expiry_controlled) return 'lot_batch';
        return 'bulk_quantity';
    }

    private function entryMethodLabel(string $method): string
    {
        return match ($method) {
            'dimension_piece' => 'an individually tracked board item',
            'serialized_item' => 'a serialized item',
            'lot_batch' => 'a lot-controlled item',
            default => 'a bulk quantity item',
        };
    }

    private function effectiveUnitCost(LibraryMaterial $material): ?float
    {
        $cost = (float) $material->unit_cost;
        if ($cost <= 0) $cost = (float) ($material->default_unit_cost ?? 0);
        return $cost > 0 ? $cost : null;
    }

    private function validateOpeningInventory(StockCount $stockCount): void
    {
        $stockCount->loadMissing('items.material');
        if ($this->activeMaterialsQuery()->count() !== $stockCount->items->count()) {
            throw ValidationException::withMessages([
                'materials' => 'The Active Material Library catalogue changed after this opening inventory started. Discard this draft and start a fresh opening inventory.',
            ]);
        }
        foreach ($stockCount->items as $item) {
            $material = $item->material;
            if (! $material || ($material->item_status ?? 'Active') !== 'Active') {
                throw ValidationException::withMessages([
                    'materials' => 'A material in this opening inventory is no longer Active. Refresh the catalogue and start the opening inventory again.',
                ]);
            }
            if (! $item->material_updated_at_snapshot || ! $material->updated_at
                || $item->material_updated_at_snapshot->toDateTimeString() !== $material->updated_at->toDateTimeString()) {
                throw ValidationException::withMessages([
                    'materials' => "{$material->material_name} changed in the Material Library after this opening inventory started. Start a fresh opening inventory so its controls are current.",
                ]);
            }
            if ($item->entry_method !== 'bulk_quantity'
                && abs((float) $item->variance_quantity) > 0.000001) {
                throw ValidationException::withMessages([
                    'items' => "{$material->material_name} uses controlled physical identities and cannot be changed by an aggregate opening quantity.",
                ]);
            }
            if ((float) $item->counted_quantity > 0 && (float) $item->opening_unit_cost <= 0) {
                throw ValidationException::withMessages([
                    'items' => "Enter an opening unit cost for {$material->material_name}; positive opening stock cannot enter Stores without a value.",
                ]);
            }
            if ($item->entry_method === 'bulk_quantity' && (float) $item->counted_quantity > 0 && blank($item->location_bin)) {
                throw ValidationException::withMessages([
                    'items' => "Enter the storage bin for {$material->material_name}.",
                ]);
            }
        }
    }

    private function openingStockRow(StockCount $count, StockCountItem $item): Stock
    {
        $stock = Stock::withTrashed()->where('material_id', $item->material_id)->lockForUpdate()->first();
        if (! $stock) {
            $stock = Stock::create([
                'material_id' => $item->material_id,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'warehouse_code' => $count->warehouse_code,
                'location_bin' => $item->location_bin,
                'tracking_mode' => $item->entry_method === 'dimension_piece' ? Stock::TRACK_BY_AREA : Stock::TRACK_BY_COUNT,
            ]);
        } elseif ($stock->trashed()) {
            $stock->restore();
        }

        if ((float) $stock->quantity_reserved > 0) {
            throw ValidationException::withMessages([
                'count' => "{$item->material?->material_name} has reserved stock. Resolve its reservation before approving opening inventory.",
            ]);
        }
        $stock->update([
            'warehouse_code' => $count->warehouse_code,
            'location_bin' => $item->location_bin ?: $stock->location_bin,
            'tracking_mode' => $item->entry_method === 'dimension_piece' ? Stock::TRACK_BY_AREA : Stock::TRACK_BY_COUNT,
        ]);

        return $stock->fresh();
    }
}
