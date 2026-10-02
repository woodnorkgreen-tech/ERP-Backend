<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementReversalService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly StoresCostProducer $costProducer,
    ) {}

    public function reverse(InventoryLog $log, string $reason, int $actorId): InventoryLog
    {
        return DB::transaction(function () use ($log, $reason, $actorId) {
            \App\Modules\MaterialsLibrary\Models\LibraryMaterial::whereKey($log->material_id)->lockForUpdate()->firstOrFail();
            $original = InventoryLog::with(['material.materialCategory.parent', 'allocations'])
                ->whereKey($log->id)->lockForUpdate()->firstOrFail();

            if ($original->type === 'reversal' || $original->reversal_of_log_id) {
                throw ValidationException::withMessages(['movement' => 'A reversal movement cannot itself be reversed.']);
            }
            if (InventoryLog::where('reversal_of_log_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'This movement has already been reversed.']);
            }
            if (! in_array($original->type, ['check_in', 'check_out', 'issue', 'consumption', 'adjustment'], true)) {
                throw ValidationException::withMessages(['movement' => "Movement type '{$original->type}' is not reversible."]);
            }

            $stock = Stock::where('material_id', $original->material_id)->lockForUpdate()->first();
            if (! $stock) {
                throw ValidationException::withMessages(['movement' => 'The material no longer has a stock balance to reverse.']);
            }

            $isReceipt = $original->type === 'check_in';
            $isIssue = in_array($original->type, ['check_out', 'issue', 'consumption'], true);
            $quantity = $isReceipt
                ? -abs((float) $original->quantity)
                : ($isIssue ? abs((float) $original->quantity) : -(float) $original->quantity);

            if ($original->material->isConsumableUnit()) $quantity = $isReceipt ? '-'.ltrim($original->quantity, '-') : ltrim($original->quantity, '-');

            if ($isIssue) {
                $returned = (float) InventoryLog::where('original_issue_log_id', $original->id)
                    ->where('type', 'return')->sum('quantity');
                if ($returned > 0.00001) {
                    throw ValidationException::withMessages([
                        'movement' => 'This issue has already been returned in whole or in part. Reverse the return workflow instead.',
                    ]);
                }
            }

            if (($original->material?->is_batch_controlled || $original->material?->is_serialized)
                && ! $original->material?->isBoardTrackable()) {
                throw ValidationException::withMessages([
                    'movement' => 'Controlled lot and serial movements require an instance-level correction and cannot use generic reversal.',
                ]);
            }

            $boards = collect();
            if ($original->material?->isBoardTrackable()) {
                $boards = $isReceipt
                    ? Board::where('batch_number', $original->batch_number)->lockForUpdate()->get()
                    : Board::where('original_issue_log_id', $original->id)->lockForUpdate()->get();
                $requiredStatus = $isReceipt ? 'Available' : 'Allocated';
                if ($boards->isEmpty() || $boards->contains(fn (Board $board) => $board->status !== $requiredStatus)) {
                    throw ValidationException::withMessages([
                        'movement' => $isReceipt
                            ? 'A board receipt can only be reversed while every board in its batch is still Available in Stores.'
                            : 'A board issue can only be reversed before any issued board leaves its Allocated state.',
                    ]);
                }
            }

            $reversal = $this->inventory->adjustStock($original->material_id, $quantity, 'reversal', [
                'user_id' => $actorId,
                'batch_number' => 'REV-'.$original->id.'-'.now()->format('YmdHis'),
                'lot_number' => $original->lot_number,
                'expiry_date' => $original->expiry_date,
                'inventory_lot_id' => $original->inventory_lot_id,
                'inventory_serial_item_id' => $original->inventory_serial_item_id,
                'skip_controlled_inventory' => true,
                'receipt_unit_cost' => $original->receipt_unit_cost,
                'project_id' => $original->project_id,
                'project_material_id' => $original->project_material_id,
                'original_issue_log_id' => $isIssue ? $original->id : null,
                'reversal_of_log_id' => $original->id,
                'supplier_id' => $original->supplier_id,
                'reference_no' => 'REV-'.($original->reference_no ?: $original->id),
                'unit_price' => $original->unit_price,
                'recipient_name' => $original->recipient_name,
                'notes' => "Reversal of movement #{$original->id}: {$reason}",
            ]);

            foreach ($boards as $board) {
                $board->transitionTo(
                    $isReceipt ? 'Scrapped' : 'Available',
                    $actorId,
                    "Movement #{$original->id} reversed: {$reason}",
                    null,
                    null,
                    $isReceipt ? 'receipt_reversal' : null,
                );
            }

            if ($isIssue) {
                $this->costProducer->postStockIssueReversal($reversal);
            }

            if ($isReceipt) {
                $items = GoodsReceiptNoteItem::where('inventory_log_id', $original->id)->lockForUpdate()->get();
                foreach ($items as $item) {
                    $item->update([
                        'stock_status' => 'awaiting_stores_details',
                        'inventory_log_id' => null,
                        'stock_quantity' => null,
                        'store_status' => 'pending',
                        'confirmed_by' => null,
                        'confirmed_at' => null,
                    ]);
                    $item->goodsReceiptNote?->update(['store_status' => 'pending_confirmation']);
                }
            }

            return $reversal->fresh(['material', 'user', 'reversedMovement']);
        });
    }
}
