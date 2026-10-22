<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\GovernedPostingAccount;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillLine;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\ReceiptAccrualCloseout;
use App\Modules\ProcurementStores\Models\SupplierCreditNote;
use App\Modules\ProcurementStores\Models\SupplierCreditNoteLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Corrections to a verified supplier bill (Report 76A-3 §4).
 *
 * A verified bill is an accounting record: it is never edited and never
 * deleted. What a supplier later takes back is recorded as a document of its
 * own, with a journal of its own.
 *
 *   REVERSAL       the bill should not stand at all (wrong supplier, wrong
 *                  order, entered twice). Its journal is mirrored, the receipts
 *                  it covered are uninvoiced again, and it is owed nothing.
 *                  Only while nothing has been paid or credited against it —
 *                  a payment is reversed as a payment, first.
 *
 *   CREDIT NOTE    the supplier reduces what it charged. By line:
 *                    price credit     (quantity 0) — the goods stand, they
 *                                     cost less;
 *                    quantity credit  — the supplier un-bills units; the
 *                                     receipts they covered are uninvoiced
 *                                     again and their accrual stands.
 *                  Never more than the line, never more than is still owed.
 *
 * NEITHER MOVES STOCK. A credit is about what was charged. Goods physically
 * sent back to a supplier leave through a Stores return, which is its own
 * document with its own stock movement; recording a credit here does not and
 * must not stand in for one.
 *
 * Also here: closing out received value that will never be invoiced, since it
 * is the same accrual being settled for the same reason — the supplier's
 * documents and the receipts have been reconciled and differ.
 */
class SupplierCreditService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly BillMatchingService $matching,
        private readonly BillCostDifferenceService $differences,
        private readonly PurchaseOrderWorkflow $workflow,
    ) {}

    /* ── reversal ───────────────────────────────────────────────────────── */

    public function reverse(Bill $bill, string $reason, User $actor): SupplierCreditNote
    {
        return DB::transaction(function () use ($bill, $reason, $actor) {
            $bill = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($existing = SupplierCreditNote::where('reversal_of_bill_id', $bill->id)->first()) {
                return $existing;   // already reversed: one reversal only
            }
            $entry = $this->postedEntry($bill);
            $bill->updatePaymentStatus();
            if (bccomp((string) $bill->paid_amount, '0.00', 2) > 0) {
                $this->refuse('bill', 'This invoice has been paid in part or in full. Reverse the payment first; a paid invoice is not reversed underneath its payment.');
            }
            if (bccomp((string) ($bill->credited_amount ?? 0), '0.00', 2) > 0) {
                $this->refuse('bill', 'This invoice already carries a supplier credit. Credit the remainder instead of reversing it.');
            }

            try {
                $reversal = $this->journals->reverseEntry($entry, $actor->id, "Supplier invoice {$bill->bill_number} reversed: {$reason}");
                $this->undoRevaluation($bill, $actor, $reason);
                // A direct bill is its own project cost; take that back with it.
                foreach (CostLine::where('source_type', Bill::class)->where('source_id', $bill->id)->where('status', CostLine::STATUS_VERIFIED)->get() as $line) {
                    app(CostVerificationService::class)->reverse($line, $actor, "Supplier invoice {$bill->bill_number} reversed: {$reason}", viaPayment: true);
                }
            } catch (InvalidArgumentException|\App\Modules\Finance\CostCollector\Exceptions\CostValidationException $exception) {
                $this->refuse('bill', 'The invoice could not be reversed in the books: '.$exception->getMessage());
            }

            $released = '0.00';
            foreach ($bill->lines()->with('allocations')->get() as $line) {
                foreach ($line->allocations as $allocation) {
                    $released = bcadd($released, bcsub((string) $allocation->receipt_value, (string) $allocation->released_value, 2), 2);
                    $allocation->forceFill(['released_quantity' => $allocation->quantity, 'released_value' => $allocation->receipt_value])->save();
                }
                $line->forceFill(['status' => 'reversed', 'posted_to_inventory' => 0, 'posted_to_variance' => 0])->save();
            }
            // The receipts are received-not-invoiced again.
            CostLine::where('settled_by_bill_id', $bill->id)->update(['settled_by_bill_id' => null]);

            $note = SupplierCreditNote::create([
                'credit_number' => $this->number('REV'), 'bill_id' => $bill->id, 'supplier_id' => $bill->supplier_id,
                'kind' => SupplierCreditNote::KIND_REVERSAL, 'reversal_of_bill_id' => $bill->id,
                'credit_date' => now()->toDateString(), 'reason' => $reason,
                'net_amount' => $bill->netAmount(), 'vat_amount' => (string) ($bill->vat_amount ?? 0), 'wht_amount' => (string) ($bill->wht_amount ?? 0),
                'amount' => (string) $bill->amount, 'receipt_value_released' => $released,
                'journal_entry_id' => $reversal->id, 'created_by' => $actor->id,
            ]);

            Bill::withoutEvents(fn () => $bill->forceFill([
                'reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason,
            ])->save());
            $bill->refresh()->updatePaymentStatus();

            $this->audit($bill, $actor, 'Supplier Bill Reversed', "Supplier bill {$bill->bill_number} reversed ({$note->credit_number}): {$reason}", [
                'credit_number' => $note->credit_number, 'amount' => (string) $bill->amount, 'receipt_value_released' => $released,
                'journal_entry_id' => $reversal->id, 'reason' => $reason,
            ]);

            return $note;
        });
    }

    /**
     * Take back what the bill put into stock value. The mirrored journal has
     * already credited (or debited) Inventory; the material's average cost
     * follows where the stock is still there to carry it. Where it is not —
     * the stock has since been issued — that part of the Inventory leg has no
     * stock behind it, and is moved to the variance account instead.
     */
    private function undoRevaluation(Bill $bill, User $actor, string $reason): void
    {
        $stranded = '0.00';
        foreach ($bill->lines()->with(['allocations.goodsReceiptNoteItem', 'purchaseOrderItem.material'])->get() as $line) {
            $posted = number_format((float) $line->posted_to_inventory, 2, '.', '');
            if (bccomp($posted, '0.00', 2) === 0) {
                continue;
            }
            [$toInventory] = $this->differences->split($line, bcmul($posted, '-1', 2), lock: true);
            // split() scales by quantity on hand; it can never give back more than was posted.
            if ($line->purchaseOrderItem?->material_id && bccomp($toInventory, '0.00', 2) !== 0) {
                $this->differences->revalue((int) $line->purchaseOrderItem->material_id, $toInventory);
            }
            $stranded = bcadd($stranded, bcsub(bcmul($posted, '-1', 2), $toInventory, 2), 2);
        }
        if (bccomp($stranded, '0.00', 2) === 0) {
            return;
        }
        // $stranded is the part of the mirrored Inventory leg with no stock behind it.
        $inventory = $this->account(FinanceAccountFunctions::INVENTORY, 'Inventory');
        $variance = GovernedPostingAccount::require(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, "the reversal of supplier invoice {$bill->bill_number}");
        $up = bccomp($stranded, '0.00', 2) < 0;   // Inventory was reduced too far: put it back
        $amount = ltrim($stranded, '-');
        $this->journals->postBalancedEntry(
            entryNo: 'JE-BILLREV-ADJ-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT),
            postingDate: now()->toDateString(), sourceType: Bill::class, sourceId: $bill->id, sourceRef: $bill->bill_number,
            description: "Reversal of {$bill->bill_number}: revaluation of stock no longer on hand",
            legs: [
                ['account_id' => $inventory, 'entry_type' => $up ? 'debit' : 'credit', 'amount' => $amount, 'description' => 'Stock revaluation with no stock left to carry it'],
                ['account_id' => $variance, 'entry_type' => $up ? 'credit' : 'debit', 'amount' => $amount, 'description' => 'Purchase price variance — '.$reason],
            ],
            createdBy: $actor->id,
        );
    }

    /* ── credit note ────────────────────────────────────────────────────── */

    /**
     * @param  array{supplier_reference: string, credit_date: string, reason: string, vat_amount?: mixed, lines: array<int, array<string, mixed>>}  $data
     */
    public function credit(Bill $bill, array $data, User $actor): SupplierCreditNote
    {
        return DB::transaction(function () use ($bill, $data, $actor) {
            $bill = Bill::with(['lines.allocations.goodsReceiptNoteItem', 'lines.purchaseOrderItem.material', 'vatTreatment', 'whtCategory'])
                ->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->postedEntry($bill);
            if ($bill->isDirect() || $bill->lines->isEmpty()) {
                $this->refuse('bill', 'A supplier credit is recorded against invoice lines, and this invoice has none. Reverse it and enter it again with the corrected amount.');
            }
            $reference = trim((string) $data['supplier_reference']);
            if (SupplierCreditNote::where('bill_id', $bill->id)->where('supplier_reference', $reference)->exists()) {
                $this->refuse('supplier_reference', "Credit note {$reference} is already recorded against this invoice.");
            }

            $recoverable = (bool) $bill->vatTreatment?->is_recoverable;
            $lines = $bill->lines->keyBy('id');
            $seen = [];
            $parts = [];
            $net = '0.00';
            $proRataVat = '0.00';

            foreach (array_values($data['lines']) as $index => $input) {
                $at = "lines.{$index}";
                /** @var BillLine|null $line */
                $line = $lines->get((int) ($input['bill_line_id'] ?? 0));
                if (! $line || isset($seen[$line->id])) {
                    $this->refuse("{$at}.bill_line_id", $line ? 'That invoice line appears twice on this credit.' : 'That is not a line of this invoice.');
                }
                $seen[$line->id] = true;
                $quantity = number_format((float) ($input['quantity'] ?? 0), 6, '.', '');
                $lineNet = number_format((float) ($input['net_amount'] ?? 0), 2, '.', '');
                if (bccomp($lineNet, '0.00', 2) <= 0) {
                    $this->refuse("{$at}.net_amount", 'Enter the amount the supplier is crediting on this line, before VAT.');
                }
                if (bccomp($quantity, '0', 6) < 0 || bccomp($quantity, $line->netQuantity(), 6) > 0) {
                    $this->refuse("{$at}.quantity", 'Only '.rtrim(rtrim($line->netQuantity(), '0'), '.').' is still billed on that line; more cannot be credited.');
                }
                $remainingNet = bcsub((string) $line->net_amount, (string) $line->credited_net, 2);
                if (bccomp($lineNet, $remainingNet, 2) > 0) {
                    $this->refuse("{$at}.net_amount", 'Only '.number_format((float) $remainingNet, 2).' of that line has not already been credited.');
                }
                // Crediting every remaining unit credits the whole remaining value: no unit is left billed at nothing… or billed with nothing left.
                if (bccomp($quantity, $line->netQuantity(), 6) === 0 && bccomp($lineNet, $remainingNet, 2) !== 0) {
                    $this->refuse("{$at}.net_amount", 'Crediting the whole remaining quantity of a line credits its whole remaining value ('.number_format((float) $remainingNet, 2).').');
                }
                $share = bccomp((string) $line->net_amount, '0.00', 2) > 0
                    ? $this->round(bcdiv(bcmul((string) $line->vat_amount, $lineNet, 10), (string) $line->net_amount, 10)) : '0.00';
                $parts[] = compact('line', 'quantity', 'lineNet', 'share', 'at');
                $net = bcadd($net, $lineNet, 2);
                $proRataVat = bcadd($proRataVat, $share, 2);
            }
            if ($parts === []) {
                $this->refuse('lines', 'A credit note needs at least one line.');
            }

            // The credit note states its own VAT; it may differ from the pro-rata
            // share by rounding only.
            $vat = array_key_exists('vat_amount', $data) && is_numeric($data['vat_amount'])
                ? number_format((float) $data['vat_amount'], 2, '.', '') : $proRataVat;
            if (bccomp(ltrim(bcsub($vat, $proRataVat, 2), '-'), bcmul('0.01', (string) count($parts), 2), 2) > 0) {
                $this->refuse('vat_amount', 'VAT of '.number_format((float) $vat, 2).' does not agree with the VAT on the lines being credited ('.number_format((float) $proRataVat, 2).').');
            }
            $drift = bcsub($vat, $proRataVat, 2);
            $parts[0]['share'] = bcadd($parts[0]['share'], $drift, 2);

            $gross = bcadd($net, $vat, 2);
            // Withholding was retained in proportion to the value; so is its return.
            $wht = bccomp((string) $bill->net_amount, '0.00', 2) > 0
                ? $this->round(bcdiv(bcmul((string) ($bill->wht_amount ?? 0), $net, 10), (string) $bill->net_amount, 10)) : '0.00';
            $offPayable = bcsub($gross, $wht, 2);
            $bill->updatePaymentStatus();
            if (bccomp($offPayable, number_format((float) $bill->balance, 2, '.', ''), 2) > 0) {
                $this->refuse('lines', 'This credit takes '.number_format((float) $offPayable, 2).' off the invoice but only '
                    .number_format((float) $bill->balance, 2).' is still owed on it. What has been paid is recovered as a refund or reversed as a payment, not credited away.');
            }

            $note = SupplierCreditNote::create([
                'credit_number' => $this->number('SCN'), 'bill_id' => $bill->id, 'supplier_id' => $bill->supplier_id,
                'kind' => SupplierCreditNote::KIND_CREDIT, 'supplier_reference' => $reference,
                'credit_date' => $data['credit_date'], 'reason' => $data['reason'],
                'net_amount' => $net, 'vat_amount' => $vat, 'wht_amount' => $wht, 'amount' => $gross, 'created_by' => $actor->id,
            ]);

            $released = '0.00';
            $toInventory = '0.00';
            $toVariance = '0.00';
            $claimed = [];
            foreach ($parts as $part) {
                /** @var BillLine $line */
                $line = $part['line'];
                // Measured before the allocations are released: the credit is
                // for the goods as they were billed.
                $asBilled = $this->differences->baseQuantities($line);
                $lineReleased = $this->release($line, $part['quantity']);
                // What the credit takes off the COST of the goods: its net (and
                // VAT that was never recoverable) beyond the receipt value it
                // hands back to the accrual.
                $cost = bcsub(bcadd($part['lineNet'], $recoverable ? '0.00' : $part['share'], 2), $lineReleased, 2);
                [$inventoryPart, $variancePart] = bccomp($cost, '0.00', 2) === 0
                    ? ['0.00', '0.00']
                    : $this->differences->split($line, bcmul($cost, '-1', 2), true, $claimed, $asBilled);
                if ($line->purchaseOrderItem?->material_id && bccomp($inventoryPart, '0.00', 2) !== 0) {
                    $this->differences->revalue((int) $line->purchaseOrderItem->material_id, $inventoryPart);
                }
                SupplierCreditNoteLine::create([
                    'supplier_credit_note_id' => $note->id, 'bill_line_id' => $line->id, 'quantity' => $part['quantity'],
                    'net_amount' => $part['lineNet'], 'vat_amount' => $part['share'], 'receipt_value_released' => $lineReleased,
                    'posted_to_inventory' => $inventoryPart, 'posted_to_variance' => $variancePart,
                ]);
                $line->forceFill([
                    'credited_quantity' => bcadd((string) $line->credited_quantity, $part['quantity'], 6),
                    'credited_net' => bcadd((string) $line->credited_net, $part['lineNet'], 2),
                ])->save();
                $released = bcadd($released, $lineReleased, 2);
                $toInventory = bcadd($toInventory, $inventoryPart, 2);
                $toVariance = bcadd($toVariance, $variancePart, 2);
            }

            try {
                $entry = $this->postCredit($bill, $note, $offPayable, $wht, $recoverable ? $vat : '0.00', $released, $toInventory, $toVariance, $actor);
            } catch (InvalidArgumentException $exception) {
                $this->refuse('credit', 'The credit could not be posted: '.$exception->getMessage());
            }

            $note->forceFill(['journal_entry_id' => $entry->id, 'receipt_value_released' => $released])->save();
            if (bccomp($released, '0.00', 2) > 0) {
                CostLine::where('settled_by_bill_id', $bill->id)->update(['settled_by_bill_id' => null]);
            }
            $this->matching->recompute($bill);
            $bill->refresh();
            // The sign-off stands: this is a new document against the bill, not
            // an edit to it. The fingerprint is restated so the bill does not
            // read as "changed after verification".
            Bill::withoutEvents(fn () => $bill->forceFill([
                'credited_amount' => bcadd(number_format((float) ($bill->credited_amount ?? 0), 2, '.', ''), $offPayable, 2),
            ])->save());
            Bill::withoutEvents(fn () => $bill->forceFill(['verification_fingerprint' => $this->workflow->fingerprint($bill->fresh())])->save());
            $bill->refresh()->updatePaymentStatus();

            $this->audit($bill, $actor, 'Supplier Credit Note', "Supplier credit {$reference} ({$note->credit_number}) recorded against {$bill->bill_number}: {$data['reason']}", [
                'credit_number' => $note->credit_number, 'supplier_reference' => $reference, 'amount' => $gross,
                'receipt_value_released' => $released, 'to_inventory' => $toInventory, 'to_variance' => $toVariance, 'journal_entry_id' => $entry->id,
            ]);

            return $note->fresh('lines');
        });
    }

    /** Hand back the receipts a quantity credit un-bills, newest allocation first. Returns their accrued value. */
    private function release(BillLine $line, string $quantity): string
    {
        $released = '0.00';
        foreach ($line->allocations->sortByDesc('id') as $allocation) {
            if (bccomp($quantity, '0', 6) <= 0) {
                break;
            }
            $open = bcsub((string) $allocation->quantity, (string) $allocation->released_quantity, 6);
            if (bccomp($open, '0', 6) <= 0) {
                continue;
            }
            $openValue = bcsub((string) $allocation->receipt_value, (string) $allocation->released_value, 2);
            $take = bccomp($quantity, $open, 6) > 0 ? $open : $quantity;
            $value = bccomp($take, $open, 6) === 0
                ? $openValue
                : $this->round(bcdiv(bcmul($take, (string) $allocation->receipt_value, 10), (string) $allocation->quantity, 10));
            $allocation->forceFill([
                'released_quantity' => bcadd((string) $allocation->released_quantity, $take, 6),
                'released_value' => bcadd((string) $allocation->released_value, $value, 2),
            ])->save();
            $released = bcadd($released, $value, 2);
            $quantity = bcsub($quantity, $take, 6);
        }

        return $released;
    }

    private function postCredit(Bill $bill, SupplierCreditNote $note, string $offPayable, string $wht, string $vat, string $released, string $toInventory, string $toVariance, User $actor): JournalEntry
    {
        $legs = [[
            'account_id' => $this->account(FinanceAccountFunctions::ACCOUNTS_PAYABLE, 'Accounts Payable'),
            'entry_type' => 'debit', 'amount' => $offPayable,
            'description' => 'Supplier credit '.$note->supplier_reference.' against '.$bill->bill_number,
        ]];
        if (bccomp($wht, '0.00', 2) > 0) {
            $legs[] = ['account_id' => $bill->whtCategory?->gl_account_id ?: $this->account(FinanceAccountFunctions::WHT_PAYABLE, 'Withholding tax payable'),
                'entry_type' => 'debit', 'amount' => $wht, 'description' => 'Withholding no longer due on the credited value'];
        }
        if (bccomp($vat, '0.00', 2) > 0) {
            $legs[] = ['account_id' => $bill->vatTreatment?->gl_account_id ?: $this->account(FinanceAccountFunctions::INPUT_VAT, 'Input VAT'),
                'entry_type' => 'credit', 'amount' => $vat, 'description' => 'Input VAT given back on credit '.$note->supplier_reference];
        }
        if (bccomp($released, '0.00', 2) > 0) {
            $legs[] = ['account_id' => $this->account(FinanceAccountFunctions::ACCRUED_EXPENSES, 'Goods received, not invoiced'),
                'entry_type' => 'credit', 'amount' => $released, 'description' => 'Receipts no longer invoiced: received, awaiting invoice again'];
        }
        // $toInventory / $toVariance are signed as cost: negative = cost reduced.
        if (bccomp($toInventory, '0.00', 2) !== 0) {
            $legs[] = ['account_id' => $this->account(FinanceAccountFunctions::INVENTORY, 'Inventory'),
                'entry_type' => bccomp($toInventory, '0.00', 2) < 0 ? 'credit' : 'debit', 'amount' => ltrim($toInventory, '-'),
                'description' => 'Stock on hand revalued by supplier credit '.$note->supplier_reference];
        }
        if (bccomp($toVariance, '0.00', 2) !== 0) {
            $legs[] = ['account_id' => GovernedPostingAccount::require(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, "supplier credit {$note->supplier_reference}"),
                'entry_type' => bccomp($toVariance, '0.00', 2) < 0 ? 'credit' : 'debit', 'amount' => ltrim($toVariance, '-'),
                'description' => 'Purchase price variance — supplier credit on goods no longer in stock: '.$note->reason];
        }
        $requisition = $bill->purchaseOrder?->requisition;

        return $this->journals->postBalancedEntry(
            entryNo: 'JE-SCN-'.str_pad((string) $note->id, 7, '0', STR_PAD_LEFT),
            postingDate: $note->credit_date->toDateString(),
            sourceType: SupplierCreditNote::class, sourceId: $note->id, sourceRef: $note->credit_number,
            description: 'Supplier credit note '.$note->supplier_reference.' against '.($bill->supplier_invoice_number ?: $bill->bill_number),
            legs: array_map(fn ($leg) => $leg + ['project_id' => $requisition?->project_id], $legs),
            createdBy: $actor->id,
        );
    }

    /* ── receipt accrual close-out ──────────────────────────────────────── */

    /**
     * Release received value that will never be invoiced.
     *
     * Only what is still uninvoiced on the receipt line, only once, only while
     * no unverified invoice is waiting against the order (it might be the one
     * that covers it), and only to the account Finance approved for it.
     */
    public function closeOutReceipt(GoodsReceiptNoteItem $item, string $reason, User $actor): ReceiptAccrualCloseout
    {
        return DB::transaction(function () use ($item, $reason, $actor) {
            $item = GoodsReceiptNoteItem::with('goodsReceiptNote.purchaseOrder')->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($existing = ReceiptAccrualCloseout::where('goods_receipt_note_item_id', $item->id)->first()) {
                return $existing;
            }
            $order = $item->goodsReceiptNote?->purchaseOrder;
            if ($order && $order->bills()->where('status', '!=', 'cancelled')->whereNull('reversed_at')->whereNull('verified_at')->exists()) {
                $this->refuse('receipt', 'An invoice against this order is still waiting to be verified. Verify or cancel it first — it may be the invoice for these goods.');
            }
            $outstanding = $this->matching->outstanding($item, null, lock: true);
            if (bccomp($outstanding['quantity'], '0', 6) <= 0 || bccomp($outstanding['value'], '0.00', 2) <= 0) {
                $this->refuse('receipt', 'Nothing on this receipt line is uninvoiced, so there is nothing to close out.');
            }

            try {
                $entry = $this->journals->postBalancedEntry(
                    entryNo: 'JE-GRNI-'.str_pad((string) $item->id, 7, '0', STR_PAD_LEFT),
                    postingDate: now()->toDateString(), sourceType: GoodsReceiptNoteItem::class, sourceId: $item->id,
                    sourceRef: $item->goodsReceiptNote?->grn_number,
                    description: 'Received, never invoiced — closed out: '.$item->goodsReceiptNote?->grn_number,
                    legs: [
                        ['account_id' => $this->account(FinanceAccountFunctions::ACCRUED_EXPENSES, 'Goods received, not invoiced'),
                            'entry_type' => 'debit', 'amount' => $outstanding['value'], 'description' => 'Receipt accrual released'],
                        ['account_id' => GovernedPostingAccount::require(GovernedPostingAccount::RECEIPT_ACCRUAL_CLOSEOUT, "the close-out of receipt {$item->goodsReceiptNote?->grn_number}"),
                            'entry_type' => 'credit', 'amount' => $outstanding['value'], 'description' => $reason],
                    ],
                    createdBy: $actor->id,
                );
            } catch (InvalidArgumentException $exception) {
                $this->refuse('receipt', $exception->getMessage());
            }

            $closeout = ReceiptAccrualCloseout::create([
                'goods_receipt_note_item_id' => $item->id, 'quantity' => $outstanding['quantity'], 'value' => $outstanding['value'],
                'reason' => $reason, 'journal_entry_id' => $entry->id, 'created_by' => $actor->id,
            ]);
            GovernanceAuditLog::create([
                'user_id' => $actor->id, 'gate_type' => 'receipt_accrual_closed_out', 'action_status' => 'recorded',
                'model_type' => GoodsReceiptNoteItem::class, 'model_id' => $item->id,
                'message' => "Receipt {$item->goodsReceiptNote?->grn_number} line {$item->id}: {$outstanding['value']} received and never invoiced, closed out: {$reason}",
                'context' => ['quantity' => $outstanding['quantity'], 'value' => $outstanding['value'], 'journal_entry_id' => $entry->id, 'reason' => $reason],
                'ip_address' => request()->ip(),
            ]);

            return $closeout;
        });
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    /** The bill's posted journal, or a refusal: only a verified, posted, live bill is corrected this way. */
    private function postedEntry(Bill $bill): JournalEntry
    {
        if ($bill->reversed_at || $bill->status === 'cancelled') {
            $this->refuse('bill', 'This invoice has already been reversed or cancelled.');
        }
        $entry = JournalEntry::where('entry_no', 'JE-BILL-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT))->where('status', 'posted')->first();
        if (! $bill->verified_at || ! $entry) {
            $this->refuse('bill', 'Only a verified invoice that reached the ledger is credited or reversed. One that has not been verified is corrected or deleted by its preparer.');
        }

        return $entry;
    }

    private function account(string $reference, string $name): int
    {
        $id = ChartOfAccount::postable()->where('code', ChartAccountMap::local($reference))->value('id');
        if (! $id) {
            throw new InvalidArgumentException("ACCOUNTING CONFIGURATION REQUIRED — the {$name} account is not an active, postable account on this chart.");
        }

        return (int) $id;
    }

    private function number(string $prefix): string
    {
        $year = date('Y');
        $last = SupplierCreditNote::where('credit_number', 'like', "{$prefix}-{$year}-%")->lockForUpdate()->orderByDesc('id')->value('credit_number');
        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $next);
    }

    private function audit(Bill $bill, User $actor, string $event, string $message, array $context): void
    {
        GovernanceAuditLog::create([
            'user_id' => $actor->id, 'gate_type' => $event, 'action_status' => 'authorized',
            'model_type' => Bill::class, 'model_id' => $bill->id, 'message' => $message, 'context' => $context,
            'ip_address' => request()->ip(),
        ]);
    }

    private function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $rounded = bcadd(ltrim($value, '-'), '0.005', 2);

        return ($negative && bccomp($rounded, '0', 2) !== 0 ? '-' : '').$rounded;
    }

    /** @return never */
    private function refuse(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
