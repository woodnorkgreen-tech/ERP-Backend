<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Events\GoodsReceiptRecorded;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
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

            $purchaseOrder = PurchaseOrder::with('items')->lockForUpdate()
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
                $grn->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'],
                    'material_id' => $item['material_id'] ?? null,
                    'ordered_quantity' => $purchaseOrder->items
                        ->firstWhere('id', (int) $item['purchase_order_item_id'])->quantity,
                    'received_quantity' => $item['received_quantity'],
                    'condition' => $item['condition'],
                    'accepted' => $item['accepted'],
                ]);
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
                'items.material', 'items.goodsReceiptNoteItems', 'supplier'
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
}
