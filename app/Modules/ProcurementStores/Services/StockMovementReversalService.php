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
            // A count adjustment is one line of an approved stock count, and the
            // count posted one entry for all its lines. Backing out a single
            // line here would restore the quantity and leave that entry
            // standing. A count is corrected by another count, which posts the
            // opposite entry and keeps both on record (Report 76A Part 2 §8).
            if ($original->type === 'adjustment') {
                throw ValidationException::withMessages(['movement' => 'A stock-count adjustment is corrected by recording a new stock count, not by reversing one of its lines. The new count posts the opposite entry and both stay on record.']);
            }
            if (! in_array($original->type, ['check_in', 'check_out', 'issue', 'consumption', 'defective'], true)) {
                throw ValidationException::withMessages(['movement' => "Movement type '{$original->type}' is not reversible."]);
            }
            if ($original->type === 'defective' && $original->material?->isBoardTrackable()) {
                throw ValidationException::withMessages(['movement' => 'A scrapped board is not brought back by reversing its write-off: the board itself stays scrapped. Register the board again as a stock adjustment if it is sound.']);
            }
            if ($original->type === 'defective' && $original->material?->isConsumableUnit()) {
                throw ValidationException::withMessages(['movement' => 'A write-off from a roll is corrected through a reviewed count of that roll.']);
            }

            $stock = Stock::where('material_id', $original->material_id)->lockForUpdate()->first();
            if (! $stock) {
                throw ValidationException::withMessages(['movement' => 'The material no longer has a stock balance to reverse.']);
            }

            $isReceipt = $original->type === 'check_in';
            $isIssue = in_array($original->type, ['check_out', 'issue', 'consumption'], true);
            // A receipt comes back out; an issue or a write-off goes back in.
            $quantity = $isReceipt ? -abs((float) $original->quantity) : abs((float) $original->quantity);

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

            // Report 76A Part 2 §8: a movement that owns a ledger entry of its
            // own — company use, damage, waste, or stock taken in without a
            // purchase — has that entry reversed with it: Inventory restored,
            // the expense or adjustment backed out. Accounts Payable is not
            // involved and no project cost is created or removed.
            $this->reverseOwnJournal($original, $actorId, $reason);
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

    private function reverseOwnJournal(InventoryLog $original, int $actorId, string $reason): void
    {
        $posting = \App\Modules\ProcurementStores\Models\StoresFinancePosting::where('inventory_log_id', $original->id)
            ->whereIn('posting_type', [...array_values(InventoryLog::NON_PROJECT_POSTINGS), InventoryLog::POSTING_EXCEPTIONAL_RECEIPT])
            ->lockForUpdate()->first();
        $journalId = $posting?->journal_entry_id;

        if ($posting && ! $journalId && in_array($posting->status, ['pending', 'processing', 'failed'], true)) {
            // The stock is back; the entry it would have needed is no longer owed.
            $posting->update(['status' => 'not_applicable', 'last_error' => 'Movement reversed before its entry was posted.', 'processing_started_at' => null]);

            return;
        }
        if (! $journalId) {
            return;
        }

        $entry = \App\Modules\Finance\Models\JournalEntry::find($journalId);
        if (! $entry || $entry->status !== 'posted') {
            return;
        }

        try {
            app(\App\Modules\Finance\Services\JournalPostingService::class)
                ->reverseEntry($entry, $actorId, "Stock movement #{$original->id} reversed: {$reason}");
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['movement' => 'The movement could not be reversed in the books: '.$exception->getMessage()]);
        }
    }
}
