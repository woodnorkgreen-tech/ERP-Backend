<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Services\InventoryService;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Http\Resources\GoodsReceiptNoteResource;
use App\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\ProcurementOperationalSyncService;
use Barryvdh\DomPDF\Facade\Pdf;

class GoodsReceiptNoteController extends Controller
{
    /**
     * Credits Stock for a GRN item once Stores has confirmed it — matched
     * (or created) the material and priced it. This is the ONLY place stock
     * gets moved for a GRN; it no longer happens automatically when
     * Procurement first submits the GRN (see store() below) — Stores has to
     * confirm each item first via confirmItem().
     *
     * Called once per item, only from confirmItem(), guarded there by
     * store_status so an item can never be credited twice.
     */
    private function creditStockForAcceptedItem(GoodsReceiptNote $grn, array $item, float $unitPrice): void
    {
        $materialId = $item['material_id'] ?? null;
        $quantity   = (float) ($item['received_quantity'] ?? 0);

        if (!$materialId || $quantity <= 0) {
            return;
        }

        $material = LibraryMaterial::find($materialId);
        if (!$material) {
            return;
        }

        $service = new InventoryService();
        $log = $service->adjustStock($materialId, $quantity, 'check_in', [
            'warehouse_code' => $grn->store_location,
            'reference_no'   => $grn->grn_number,
            'supplier_id'    => $grn->purchaseOrder?->supplier_id,
            'unit_price'     => $unitPrice,
            'notes'          => "Store-confirmed via GRN {$grn->grn_number}",
        ]);

        // Reusable (board-tracked) materials also need board records, same
        // as the manual Check-In flow.
        if ($material->material_type === 'reusable') {
            try {
                $registration = new BoardRegistrationService();
                $registration->validateMaterial($material);
                $registration->createBoardRecords(
                    material:    $material,
                    quantity:    (int) $quantity,
                    batchNumber: $log->batch_number,
                    userId:      auth()->id(),
                );
                $log->update(['usage_type' => 'reusable']);
            } catch (\InvalidArgumentException) {
                // Not board-eligible — plain consumable check-in is enough
            }
        }
    }

    private function syncProjectProcurement(GoodsReceiptNote|int $goodsReceiptNote): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncGoodsReceiptNote($goodsReceiptNote);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from goods receipt note', [
                'goods_receipt_note' => $goodsReceiptNote instanceof GoodsReceiptNote ? $goodsReceiptNote->id : $goodsReceiptNote,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function syncProjectProcurementFromPurchaseOrder(int $purchaseOrderId): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncPurchaseOrder($purchaseOrderId);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from goods receipt purchase order', [
                'purchase_order_id' => $purchaseOrderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Download GRN as PDF
     */
    public function downloadPdf($id)
    {
        $grn = GoodsReceiptNote::with([
            'items.purchaseOrderItem.material',
            'purchaseOrder.supplier',
            'receivedByUser'
        ])->findOrFail($id);
        
        $pdf = Pdf::loadView('reports.procurement.grn', [
            'grn' => $grn,
        ]);

        $filename = 'GRN-' . $grn->grn_number . '.pdf';
        
        return $pdf->download($filename);
    }
    public function index(Request $request)
    {
        $query = GoodsReceiptNote::with([
            'items.purchaseOrderItem.material',
            'purchaseOrder.supplier',
            'receivedByUser'
        ]);

        // Date filtering
        if ($request->has('date_filter')) {
            $dateFilter = $request->input('date_filter');

            if ($dateFilter === 'today') {
                $query->whereDate('date', today());
            } elseif ($dateFilter === 'past_7_days') {
                $query->whereDate('date', '>=', now()->subDays(7));
            } elseif ($dateFilter === 'past_30_days') {
                $query->whereDate('date', '>=', now()->subDays(30));
            } elseif ($dateFilter === 'this_month') {
                $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
            } elseif ($dateFilter === 'custom' && $request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            }
        }

        // Quality check filtering
        if ($request->has('quality_check')) {
            $query->where('quality_check', $request->quality_check);
        }

        // Store location filtering
        if ($request->has('store_location')) {
            $query->where('store_location', $request->store_location);
        }

        $grns = $query->orderBy('created_at', 'desc')->paginate(20);

        return GoodsReceiptNoteResource::collection($grns)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $grns = GoodsReceiptNote::with([
            'items.purchaseOrderItem.material',
            'purchaseOrder.supplier',
            'receivedByUser'
        ])
            ->where(function ($query) use ($searchTerm) {
                $query->where('grn_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('batch_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('purchaseOrder', function ($q) use ($searchTerm) {
                        $q->where('po_number', 'LIKE', '%' . $searchTerm . '%');
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return GoodsReceiptNoteResource::collection($grns)->preserveQuery();
    }

    public function show($id)
    {
        $grn = GoodsReceiptNote::with([
            'items.purchaseOrderItem.material',
            'purchaseOrder.supplier',
            'receivedByUser'
        ])->findOrFail($id);

        return new GoodsReceiptNoteResource($grn);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'store_location' => 'required|in:Karen Village Store,Matasia Store,Mombasa Store,Gichagi Store',
            'quality_check' => 'required|in:pass,fail',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.material_id' => 'nullable|integer',
            'items.*.ordered_quantity' => 'required|integer|min:1',
            'items.*.received_quantity' => 'required|integer|min:0',
            'items.*.condition' => 'required|in:good,fair,damaged,for_repair',
            'items.*.accepted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Check if this PO already has a GRN
            $existingGrn = GoodsReceiptNote::where('purchase_order_id', $request->purchase_order_id)->first();
            if ($existingGrn) {
                DB::rollBack();
                return response()->json([
                    'message' => 'This Purchase Order already has a Goods Receipt Note.'
                ], 422);
            }

            $grn = GoodsReceiptNote::create([
                'grn_number' => GoodsReceiptNote::generateGrnNumber(),
                'date' => now()->format('Y-m-d'),
                'purchase_order_id' => $request->purchase_order_id,
                'batch_number' => GoodsReceiptNote::generateBatchNumber(),
                'store_location' => $request->store_location,
                'quality_check' => $request->quality_check,
                'received_by' => auth()->id(),
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                // store_status defaults to 'pending' — Stock is NOT touched
                // here. It only moves once Stores confirms the item via
                // confirmItem() below, with a material match/create and a
                // price. This just records what physically arrived.
                $grn->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'material_id' => $item['material_id'] ?? null,
                    'ordered_quantity' => $item['ordered_quantity'],
                    'received_quantity' => $item['received_quantity'],
                    'condition' => $item['condition'],
                    'accepted' => $item['accepted'],
                ]);
            }

            DB::commit();

            $this->syncProjectProcurement($grn);

            return new GoodsReceiptNoteResource($grn->load([
                'items.purchaseOrderItem.material',
                'purchaseOrder.supplier',
                'receivedByUser'
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating GRN: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $grn = GoodsReceiptNote::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'store_location' => 'required|in:Karen Village Store,Matasia Store,Mombasa Store,Gichagi Store',
            'quality_check' => 'required|in:pass,fail',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'sometimes|exists:goods_receipt_note_items,id',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.material_id' => 'nullable|integer',
            'items.*.ordered_quantity' => 'required|integer|min:1',
            'items.*.received_quantity' => 'required|integer|min:0',
            'items.*.condition' => 'required|in:good,fair,damaged,for_repair',
            'items.*.accepted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $grn->update([
                'store_location' => $request->store_location,
                'quality_check' => $request->quality_check,
                'notes' => $request->notes,
            ]);

            // Delete existing items and create new ones.
            // NOTE: stock is intentionally NOT re-credited here — it was
            // already credited once when the GRN was first created (see
            // creditStockForAcceptedItem in store()). Re-crediting on every
            // edit would double-count received quantities. If a correction
            // needs to change what's on the shelf, adjust Stock directly via
            // Check-In/Check-Out instead of editing an already-received GRN.
            $grn->items()->delete();

            foreach ($request->items as $item) {
                $grn->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'material_id' => $item['material_id'] ?? null,
                    'ordered_quantity' => $item['ordered_quantity'],
                    'received_quantity' => $item['received_quantity'],
                    'condition' => $item['condition'],
                    'accepted' => $item['accepted'],
                ]);
            }

            DB::commit();

            $this->syncProjectProcurement($grn);

            return new GoodsReceiptNoteResource($grn->load([
                'items.purchaseOrderItem.material',
                'purchaseOrder.supplier',
                'receivedByUser'
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating GRN: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $grn = GoodsReceiptNote::findOrFail($id);
            $purchaseOrderId = $grn->purchase_order_id;
            $grn->delete();

            if ($purchaseOrderId) {
                $this->syncProjectProcurementFromPurchaseOrder((int) $purchaseOrderId);
            }

            return response()->json(['message' => 'GRN deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error deleting GRN: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get purchase orders that don't have a GRN yet
     */
    public function getAvailablePurchaseOrders()
    {
        $purchaseOrders = PurchaseOrder::with(['items.material', 'supplier'])
            ->whereDoesntHave('goodsReceiptNote')
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get();

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    /**
     * Lightweight count for dashboard badges — avoids pulling the full
     * paginated queue just to show a number.
     */
    public function pendingConfirmationsCount()
    {
        $count = GoodsReceiptNoteItem::where('accepted', true)
            ->where('store_status', 'pending')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Stores' confirmation queue — GRNs that have items dock-accepted by
     * Procurement but not yet matched/priced into Stock.
     */
    public function pendingConfirmations()
    {
        $grns = GoodsReceiptNote::with([
                'items.purchaseOrderItem.material',
                'purchaseOrder.supplier',
                'receivedByUser',
            ])
            ->where('store_status', 'pending_confirmation')
            ->whereHas('items', function ($q) {
                $q->where('accepted', true)->where('store_status', 'pending');
            })
            ->orderBy('date', 'asc')
            ->paginate(20);

        return GoodsReceiptNoteResource::collection($grns)->preserveQuery();
    }

    /**
     * Stores confirms a single GRN item: matches it to an existing library
     * material (or creates a new one), records the price it came in at, and
     * credits Stock. This is the only place stock actually moves for a GRN.
     */
    public function confirmItem(Request $request, $grnItemId)
    {
        $validator = Validator::make($request->all(), [
            'material_id' => 'required_without:new_material|nullable|integer|exists:library_materials,id',
            'new_material' => 'required_without:material_id|nullable|array',
            'new_material.material_name' => 'required_with:new_material|string|max:255',
            'new_material.material_code' => 'nullable|string|max:100',
            'new_material.unit_of_measure' => 'required_with:new_material|string|max:50',
            'new_material.material_type' => 'required_with:new_material|string|max:50',
            'new_material.category' => 'nullable|string|max:100',
            'unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $grnItem = GoodsReceiptNoteItem::with('goodsReceiptNote')->find($grnItemId);

        if (!$grnItem) {
            return response()->json(['message' => 'GRN item not found.'], 404);
        }

        if (!$grnItem->accepted) {
            return response()->json(['message' => 'This item was not accepted at the dock and cannot be confirmed into stock.'], 422);
        }

        if ($grnItem->store_status === 'confirmed') {
            return response()->json(['message' => 'This item has already been confirmed.'], 422);
        }

        try {
            DB::beginTransaction();

            $materialId = $request->input('material_id');

            if (!$materialId) {
                $material = LibraryMaterial::create($request->input('new_material'));
                $materialId = $material->id;
            }

            $grnItem->update([
                'material_id'  => $materialId,
                'unit_price'   => $request->input('unit_price'),
                'store_status' => 'confirmed',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

            $grn = $grnItem->goodsReceiptNote;

            $this->creditStockForAcceptedItem($grn, [
                'material_id'       => $materialId,
                'received_quantity' => $grnItem->received_quantity,
            ], (float) $request->input('unit_price'));

            // Once every accepted item on this GRN is confirmed, close it out.
            $stillPending = $grn->items()
                ->where('accepted', true)
                ->where('store_status', 'pending')
                ->exists();

            if (!$stillPending) {
                $grn->update(['store_status' => 'confirmed']);
            }

            DB::commit();

            $this->syncProjectProcurement($grn);

            return new GoodsReceiptNoteResource($grn->load([
                'items.purchaseOrderItem.material',
                'purchaseOrder.supplier',
                'receivedByUser'
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error confirming item: ' . $e->getMessage()], 500);
        }
    }
}