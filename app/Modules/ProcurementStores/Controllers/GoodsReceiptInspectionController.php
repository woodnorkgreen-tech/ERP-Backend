<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Events\GoodsReceiptRecorded;
use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Models\GoodsReceiptInspection;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\ProcurementOperationalSyncService;

class GoodsReceiptInspectionController extends Controller
{
    private function authorizeStores(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin']), 403, 'Only Stores team members can manage receipt inspections.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStores();
        $pending = GoodsReceiptNoteItem::query()->where('stock_status', 'awaiting_inspection')
            ->with(['goodsReceiptNote.purchaseOrder.supplier', 'purchaseOrderItem.uom', 'purchaseOrderItem.material.baseUom'])->latest()->get();
        $resolved = GoodsReceiptInspection::with(['inspector:id,name', 'item.goodsReceiptNote.purchaseOrder.supplier', 'item.purchaseOrderItem.uom', 'item.purchaseOrderItem.material.baseUom'])
            ->latest('inspected_at')->limit(100)->get();
        return response()->json(['data' => ['pending' => $pending, 'resolved' => $resolved]]);
    }

    public function resolve(Request $request, GoodsReceiptNoteItem $item): JsonResponse
    {
        $this->authorizeStores();
        $validated = $request->validate([
            'accepted_quantity' => 'required|numeric|min:0', 'rejected_quantity' => 'required|numeric|min:0',
            'quarantined_quantity' => 'required|numeric|min:0',
            'outcome' => 'required|in:accepted,accepted_with_conditions,quarantined,return_to_supplier,replacement_requested',
            'findings' => 'required|string|min:5|max:2000', 'condition_notes' => 'nullable|string|max:2000',
            'supplier_action' => 'nullable|in:none,credit_note,replacement,collection',
            'supplier_action_due_on' => 'nullable|date|after_or_equal:today', 'supplier_reference' => 'nullable|string|max:120',
        ]);

        $total = (float) $validated['accepted_quantity'] + (float) $validated['rejected_quantity'] + (float) $validated['quarantined_quantity'];
        if (abs($total - (float) $item->received_quantity) > 0.000001) {
            throw ValidationException::withMessages(['quantities' => "Accepted, rejected and quarantined quantities must total {$item->received_quantity}."]);
        }
        if ((float) $validated['accepted_quantity'] <= 0 && in_array($validated['outcome'], ['accepted', 'accepted_with_conditions'], true)) {
            throw ValidationException::withMessages(['accepted_quantity' => 'An acceptance outcome requires an accepted quantity.']);
        }

        DB::transaction(function () use ($item, $validated) {
            $locked = GoodsReceiptNoteItem::with(['goodsReceiptNote', 'purchaseOrderItem.material.uomConversions', 'purchaseOrderItem.material.materialCategory.parent'])
                ->lockForUpdate()->findOrFail($item->id);
            if ($locked->stock_status !== 'awaiting_inspection' || $locked->inspection()->exists()) {
                throw ValidationException::withMessages(['inspection' => 'This GRN line has already been resolved.']);
            }
            $inspection = $locked->inspection()->create(array_merge($validated, [
                'inspected_quantity' => $locked->received_quantity, 'status' => 'resolved',
                'inspected_by' => auth()->id(), 'inspected_at' => now(),
            ]));
            $accepted = (float) $validated['accepted_quantity'];
            $material = $locked->purchaseOrderItem?->material;
            if ($accepted > 0 && $material) {
                if (($material->item_status ?? 'Active') !== 'Active' || ! $material->base_uom_id) {
                    $locked->update(['stock_status' => 'awaiting_material_setup']);
                } elseif ($material->isBoardTrackable() || $material->is_serialized || $material->is_batch_controlled || $material->is_expiry_controlled) {
                    $locked->update(['stock_status' => 'awaiting_stores_details']);
                } else {
                    $uomId = (int) ($locked->purchaseOrderItem?->uom_id ?: $material->purchase_uom_id ?: $material->base_uom_id);
                    $hasConversion = $uomId === (int) $material->base_uom_id
                        || $material->uomConversions->contains(fn ($conversion) => (int) $conversion->from_uom_id === $uomId
                            && (int) $conversion->to_uom_id === (int) $material->base_uom_id
                            && (float) $conversion->factor > 0);
                    if (! $hasConversion) {
                        $locked->update(['stock_status' => 'awaiting_unit_setup']);
                        return;
                    }
                    $log = app(InventoryService::class)->adjustStock($material->id, $accepted, 'check_in', [
                        'entered_uom_id' => $uomId, 'expected_entered_uom_id' => $uomId,
                        'receipt_unit_cost' => (float) $locked->receipt_unit_cost,
                        'location' => $locked->goodsReceiptNote?->store_location, 'reference_no' => $locked->goodsReceiptNote?->grn_number,
                        'notes' => "Accepted after inspection #{$inspection->id}", 'logged_at' => now(),
                    ]);
                    $locked->update(['entered_uom_id' => $uomId, 'stock_quantity' => abs((float) $log->quantity), 'inventory_log_id' => $log->id, 'stock_status' => 'posted']);
                }
            } else {
                $status = (float) $validated['quarantined_quantity'] > 0 ? 'quarantined' : ($validated['outcome'] === 'replacement_requested' ? 'replacement_requested' : 'return_to_supplier');
                $locked->update(['stock_status' => $status]);
            }
        });
        GoodsReceiptRecorded::dispatch($item->goods_receipt_note_id);
        try {
            app(ProcurementOperationalSyncService::class)->syncGoodsReceiptNote($item->goods_receipt_note_id);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement after receipt inspection', ['item_id' => $item->id, 'error' => $exception->getMessage()]);
        }
        return response()->json(['message' => 'Inspection decision recorded. Only the accepted quantity can enter available stock.']);
    }
}
