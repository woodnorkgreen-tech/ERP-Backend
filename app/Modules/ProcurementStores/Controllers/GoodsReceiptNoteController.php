<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Events\GoodsReceiptRecorded;
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
     * One Stores-facing list of GRN lines that still need attention, plus recent
     * completed lines for confirmation. A line is the useful unit of work here:
     * one GRN can contain both automatically stocked and controlled materials.
     */
    public function receivingQueue()
    {
        if (! auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])) {
            return response()->json(['message' => 'Only Stores team members can view the receiving queue.'], 403);
        }

        $pendingStatuses = [
            'awaiting_stores_details', 'awaiting_inspection', 'awaiting_material_setup',
            'awaiting_unit_setup', 'not_stocked',
        ];

        $baseQuery = GoodsReceiptNoteItem::query()
            ->where('accepted', true)
            ->where('received_quantity', '>', 0);

        $counts = (clone $baseQuery)
            ->selectRaw('stock_status, COUNT(*) as total')
            ->groupBy('stock_status')
            ->pluck('total', 'stock_status');

        $items = (clone $baseQuery)
            ->where(function ($query) use ($pendingStatuses) {
                $query->whereIn('stock_status', $pendingStatuses)
                    ->orWhere(function ($posted) {
                        $posted->where('stock_status', 'posted')->where('updated_at', '>=', now()->subDays(14));
                    });
            })
            ->with([
                'goodsReceiptNote:id,grn_number,date,purchase_order_id,store_location',
                'goodsReceiptNote.purchaseOrder:id,po_number,supplier_id',
                'goodsReceiptNote.purchaseOrder.supplier:id,supplier_name',
                'purchaseOrderItem:id,material_id,uom_id,unit_price',
                'purchaseOrderItem.uom:id,code,name',
                'purchaseOrderItem.material:id,material_code,material_name,item_status,base_uom_id,purchase_uom_id',
                'purchaseOrderItem.material.baseUom:id,code,name',
                'purchaseOrderItem.material.purchaseUom:id,code,name',
            ])
            ->orderByRaw("CASE WHEN stock_status = 'awaiting_stores_details' THEN 0 WHEN stock_status = 'posted' THEN 2 ELSE 1 END")
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(function (GoodsReceiptNoteItem $item) {
                $material = $item->purchaseOrderItem?->material;
                $uom = $item->purchaseOrderItem?->uom ?: $material?->purchaseUom ?: $material?->baseUom;

                return [
                    'id' => $item->id,
                    'stock_status' => $item->stock_status,
                    'received_quantity' => (float) $item->received_quantity,
                    'stock_quantity' => $item->stock_quantity !== null ? (float) $item->stock_quantity : null,
                    'receipt_unit_cost' => $item->receipt_unit_cost !== null ? (float) $item->receipt_unit_cost : null,
                    'condition' => $item->condition,
                    'inventory_log_id' => $item->inventory_log_id,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                    'material' => $material ? [
                        'id' => $material->id,
                        'code' => $material->material_code,
                        'name' => $material->material_name,
                        'item_status' => $material->item_status,
                    ] : null,
                    'buying_uom' => $uom ? ['id' => $uom->id, 'code' => $uom->code, 'name' => $uom->name] : null,
                    'grn' => $item->goodsReceiptNote ? [
                        'id' => $item->goodsReceiptNote->id,
                        'number' => $item->goodsReceiptNote->grn_number,
                        'date' => $item->goodsReceiptNote->date?->toDateString(),
                        'store_location' => $item->goodsReceiptNote->store_location,
                    ] : null,
                    'purchase_order' => $item->goodsReceiptNote?->purchaseOrder ? [
                        'id' => $item->goodsReceiptNote->purchaseOrder->id,
                        'number' => $item->goodsReceiptNote->purchaseOrder->po_number,
                        'supplier' => $item->goodsReceiptNote->purchaseOrder->supplier?->supplier_name,
                    ] : null,
                ];
            });

        return response()->json([
            'data' => $items,
            'summary' => [
                'needs_stores_details' => (int) ($counts['awaiting_stores_details'] ?? 0),
                'needs_attention' => collect(['awaiting_inspection', 'awaiting_material_setup', 'awaiting_unit_setup', 'not_stocked'])
                    ->sum(fn ($status) => (int) ($counts[$status] ?? 0)),
                'recently_completed' => $items->where('stock_status', 'posted')->count(),
            ],
        ]);
    }

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
            'items.*.ordered_quantity' => 'required|numeric|gt:0',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'items.*.condition' => 'required|in:good,fair,damaged,for_repair',
            'items.*.accepted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $purchaseOrder = PurchaseOrder::with(['items.material.materialCategory.parent', 'items.material.baseUom', 'items.material.purchaseUom', 'items.material.uomConversions'])->lockForUpdate()
                ->findOrFail($request->purchase_order_id);
            foreach ($request->items as $item) {
                $poItem = $purchaseOrder->items->firstWhere('id', (int) $item['purchase_order_item_id']);
                if (! $poItem) {
                    DB::rollBack();
                    return response()->json(['message' => 'A receipt item does not belong to this purchase order.'], 422);
                }
                $accepted = DB::table('goods_receipt_note_items')
                    ->where('purchase_order_item_id', $poItem->id)->where('accepted', true)
                    ->sum('received_quantity');
                $remaining = (float) $poItem->quantity - (float) $accepted;
                if (($item['accepted'] ?? false) && (float) $item['received_quantity'] > $remaining) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Received quantity exceeds the {$remaining} remaining on PO item {$poItem->id}.",
                    ], 422);
                }
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
                $poItem = $purchaseOrder->items->firstWhere('id', (int) $item['purchase_order_item_id']);
                $grnItem = $grn->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'material_id' => $poItem->material_id,
                    'ordered_quantity' => $poItem->quantity,
                    'received_quantity' => $item['received_quantity'],
                    'receipt_unit_cost' => $poItem->unit_price,
                    'condition' => $item['condition'],
                    'accepted' => $item['accepted'],
                ]);

                $material = $poItem->material;
                $stockStatus = 'not_received';
                if ($item['accepted'] && (float) $item['received_quantity'] > 0) {
                    if (! $material) {
                        $stockStatus = 'not_stocked';
                    } elseif ($request->quality_check !== 'pass' || ! in_array($item['condition'], ['good', 'fair'], true)) {
                        $stockStatus = 'awaiting_inspection';
                    } elseif (($material->item_status ?? 'Active') !== 'Active' || ! $material->base_uom_id) {
                        $stockStatus = 'awaiting_material_setup';
                    } elseif ($material->isBoardTrackable() || $material->is_serialized || $material->is_batch_controlled || $material->is_expiry_controlled) {
                        $stockStatus = 'awaiting_stores_details';
                    } else {
                        $enteredUomId = (int) ($material->purchase_uom_id ?: $material->base_uom_id);
                        $factor = 1.0;
                        if ($enteredUomId !== (int) $material->base_uom_id) {
                            $factor = (float) ($material->uomConversions
                                ->first(fn ($row) => (int) $row->from_uom_id === $enteredUomId
                                    && (int) $row->to_uom_id === (int) $material->base_uom_id)?->factor ?? 0);
                        }

                        if ($factor <= 0) {
                            $stockStatus = 'awaiting_unit_setup';
                        } else {
                            $log = app(InventoryService::class)->adjustStock(
                                $material->id,
                                (float) $item['received_quantity'],
                                'check_in',
                                [
                                    'entered_uom_id' => $enteredUomId,
                                    'receipt_unit_cost' => (float) $poItem->unit_price,
                                    'batch_number' => $grn->batch_number,
                                    'warehouse_code' => 'MAIN',
                                    'location' => $request->store_location,
                                    'reference_no' => $grn->grn_number,
                                    'notes' => "Accepted through GRN {$grn->grn_number}",
                                    'logged_at' => $grn->date,
                                ],
                            );
                            // Posting to Stock here IS the store confirmation
                            // for this line, so mark it confirmed too. Without
                            // this it would stay store_status='pending' and
                            // Stores could confirm it a second time through
                            // confirmItem(), crediting the same stock twice.
                            $grnItem->update([
                                'entered_uom_id' => $enteredUomId,
                                'stock_quantity' => abs((float) $log->quantity),
                                'stock_status' => 'posted',
                                'inventory_log_id' => $log->id,
                                'unit_price' => (float) $poItem->unit_price,
                                'store_status' => 'confirmed',
                                'confirmed_by' => auth()->id(),
                                'confirmed_at' => now(),
                            ]);
                            continue;
                        }
                    }
                }

                $grnItem->update(['stock_status' => $stockStatus]);
            }

            // If every accepted line went straight to Stock, there is nothing
            // left for Stores to confirm — close the GRN out rather than
            // leaving it sitting at 'pending_confirmation'.
            $stillPending = $grn->items()
                ->where('accepted', true)
                ->where('store_status', 'pending')
                ->exists();

            if (! $stillPending) {
                $grn->update(['store_status' => 'confirmed']);
            }

            DB::commit();

            GoodsReceiptRecorded::dispatch($grn->id);

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
        GoodsReceiptNote::findOrFail($id);

        return response()->json([
            'message' => 'Posted goods receipts are immutable. Record a return or a new receipt instead of rewriting receipt history.',
        ], 422);

    }

    public function destroy($id)
    {
        try {
            $grn = GoodsReceiptNote::findOrFail($id);
            $itemIds = $grn->items()->pluck('id');
            if (\App\Modules\Finance\CostCollector\Models\CostLine::where(
                'source_type', \App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem::class
            )->whereIn('source_id', $itemIds)->exists()) {
                return response()->json([
                    'message' => 'This receipt has entered the cost ledger and cannot be deleted. Use a return or reversal.',
                ], 422);
            }
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
        $purchaseOrders = PurchaseOrder::with([
                'items.material.baseUom', 'items.material.purchaseUom', 'items.material.uomConversions', 'items.material.materialCategory.parent', 'items.goodsReceiptNoteItems', 'supplier'
            ])
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn (PurchaseOrder $po) => $po->items->contains(fn ($item) =>
                (float) $item->goodsReceiptNoteItems->where('accepted', true)->sum('received_quantity')
                    < (float) $item->quantity
            ))->values();

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