<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reverses one goods-receipt line — never deletes it (Report 76A Part 2 §8).
 *
 * A receipt recorded in error used to have no way back: the note could not be
 * edited, and deleting it was refused the moment it reached the cost ledger.
 * Reversing the stock movement put the goods back "awaiting Stores" but left
 * the accrual — Dr Inventory / Cr Accrued Expenses — standing for goods that
 * had never arrived.
 *
 * What this reverses is exactly what the receipt created, and nothing else:
 *
 *   stocked line    the accrual and its journal  (Dr Accrued / Cr Inventory)
 *   non-stock line  the actual cost and its journal (Dr Accrued / Cr WIP or expense)
 *   both            the order line's commitment is restated for what is
 *                   outstanding again
 *
 * It is refused wherever something downstream already rests on the receipt:
 *
 *   - its stock is in Stores (reverse the stock receipt first — that movement,
 *     and anything issued or returned from it, has its own reversal rules);
 *   - a supplier invoice already covers it (the accrual it would remove has
 *     been cleared to Accounts Payable; reversing would leave the invoice
 *     matched against goods the books say never came).
 *
 * The line, its note and both journals stay on record. One reversal per line.
 */
class GoodsReceiptReversalService
{
    public function __construct(
        private readonly CostVerificationService $verification,
        private readonly JournalPostingService $journals,
        private readonly ProcurementCostProducer $producer,
    ) {}

    public function reverse(GoodsReceiptNoteItem $item, string $reason, User $actor): GoodsReceiptNoteItem
    {
        return DB::transaction(function () use ($item, $reason, $actor) {
            $item = GoodsReceiptNoteItem::with(['goodsReceiptNote.purchaseOrder.items', 'purchaseOrderItem'])
                ->lockForUpdate()->findOrFail($item->id);
            $note = $item->goodsReceiptNote;
            $order = $note?->purchaseOrder;

            if ($item->reversed_at) {
                return $item;   // already reversed: say so by doing nothing
            }
            if (! $item->accepted) {
                $this->refuse('This line was not accepted at receipt, so there is nothing to reverse.');
            }
            if ($item->inventory_log_id || $item->stock_status === 'posted') {
                $this->refuse('The goods on this line are in Stores. Reverse the stock receipt first; then this receipt can be reversed.');
            }

            $lines = CostLine::where('source_type', GoodsReceiptNoteItem::class)->where('source_id', $item->id)
                ->whereIn('source_ref', CostLine::RECEIPT_LIABILITY_REFS)->lockForUpdate()->get();
            $value = $lines->reduce(fn ($sum, CostLine $line) => bcadd($sum, (string) $line->net_amount, 2), '0.00');

            if ($order) {
                // What invoices have already cleared may not exceed what would
                // still be received once this line is gone.
                $itemIds = $order->items->pluck('id');
                $received = (string) CostLine::where('source_type', GoodsReceiptNoteItem::class)
                    ->whereIn('source_ref', CostLine::RECEIPT_LIABILITY_REFS)
                    ->whereIn('details->purchase_order_item_id', $itemIds)
                    ->whereHas('journalEntry', fn ($journal) => $journal->where('status', 'posted'))
                    ->sum('net_amount');
                if (bccomp(bcsub($received, $value, 2), $order->totalBilled(), 2) < 0) {
                    $this->refuse('A supplier invoice already covers this receipt. Correct the invoice first; a receipt that has been invoiced cannot be reversed underneath it.');
                }
            }

            $reversalReason = "Receipt {$note?->grn_number} reversed: {$reason}";
            foreach ($lines as $line) {
                try {
                    if ($line->status === CostLine::STATUS_VERIFIED) {
                        $this->verification->reverse($line, $actor, $reversalReason, viaPayment: true);
                    } elseif ($line->journal_entry_id && $line->journalEntry?->status === 'posted') {
                        // An accrual a Stores issue had already retired from the
                        // project figure still has its journal to back out.
                        $this->journals->reverseCostLine($line, $actor->id, $reversalReason);
                    }
                } catch (CostValidationException|InvalidArgumentException $exception) {
                    $this->refuse('The receipt could not be reversed in the books: '.$exception->getMessage());
                }
            }

            $item->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->producer->restoreCommitmentAfterReceiptReversal($item);
            $note?->closeOutIfFullyConfirmed();

            GovernanceAuditLog::create([
                'user_id' => $actor->id,
                'gate_type' => 'goods_receipt_reversed',
                'action_status' => 'recorded',
                'model_type' => GoodsReceiptNoteItem::class,
                'model_id' => $item->id,
                'message' => "Receipt {$note?->grn_number} line {$item->id} reversed (value {$value}): {$reason}",
                'context' => [
                    'grn_number' => $note?->grn_number,
                    'purchase_order_id' => $order?->id,
                    'received_quantity' => (string) $item->received_quantity,
                    'value' => $value,
                    'cost_line_ids' => $lines->pluck('id')->all(),
                    'reason' => $reason,
                ],
                'ip_address' => request()->ip(),
            ]);

            return $item->fresh();
        });
    }

    /** @return never */
    private function refuse(string $message): void
    {
        throw ValidationException::withMessages(['receipt' => $message]);
    }
}
