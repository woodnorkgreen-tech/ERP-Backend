<?php

namespace App\Modules\ProcurementStores\Controllers;

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

            // Delete existing items and create new ones
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
}
