<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillLine;
use App\Modules\ProcurementStores\Models\Stock;

/**
 * Where the difference between an invoice and its receipt belongs (Report 76A-3).
 *
 * The receipt valued the goods at the order's price. The invoice says what they
 * actually cost — a different price, or VAT that cannot be recovered. That
 * difference is real cost and has to land somewhere true:
 *
 *   STOCK STILL ON THE SHELF    Inventory. The stock is worth what was paid for
 *                               it, so Inventory is corrected and the
 *                               material's weighted-average cost moves with it.
 *                               Later issues then carry the true cost to the
 *                               jobs that use it.
 *
 *   EVERYTHING ELSE             the governed purchase-price-variance account:
 *                               stock already issued or written off (its cost
 *                               has gone to a job or an expense at the old
 *                               price and is not re-opened), goods received but
 *                               not yet on the shelf, non-stock purchases, and
 *                               boards and rolls, whose value is held unit by
 *                               unit rather than as an average.
 *
 * Stores values bulk stock at one weighted-average cost per material and keeps
 * no receipt layers, so "still on the shelf" is measured the only way that
 * method supports: the billed quantity, up to what the material has on hand.
 * The valuation method itself is unchanged.
 *
 * plan() only computes; apply() is called by the ledger posting, inside its
 * transaction, so the average cost and the Inventory account move together.
 */
class BillCostDifferenceService
{
    /**
     * @return array<int, array{line: BillLine, difference: string, to_inventory: string, to_variance: string, material_id: ?int}>
     */
    public function plan(Bill $bill, bool $lock = false): array
    {
        $bill->loadMissing(['lines.allocations.goodsReceiptNoteItem', 'lines.purchaseOrderItem.material']);
        $plan = [];
        // Several lines of one material share one shelf: what an earlier line
        // has claimed of it is not available to the next.
        $claimed = [];

        foreach ($bill->lines as $line) {
            $difference = number_format((float) $line->cost_difference, 2, '.', '');
            if (bccomp($difference, '0.00', 2) === 0) {
                continue;
            }
            [$toInventory, $toVariance] = $this->split($line, $difference, $lock, $claimed);
            $plan[] = [
                'line' => $line,
                'difference' => $difference,
                'to_inventory' => $toInventory,
                'to_variance' => $toVariance,
                'material_id' => $line->purchaseOrderItem?->material_id,
            ];
        }

        return $plan;
    }

    /**
     * Split one amount of cost on a bill line — a difference at billing, or a
     * supplier's later credit (negative) — between stock still on hand and the
     * variance account.
     *
     * @param  array<int, string>  $claimed  stock quantity earlier lines have already spoken for, by material
     * @param  array{0: string, 1: string}|null  $quantities  [billed, shelved] in stock units, when the caller measured them before changing the line's allocations
     * @return array{0: string, 1: string}  [to inventory, to variance]
     */
    public function split(BillLine $line, string $amount, bool $lock = false, array &$claimed = [], ?array $quantities = null): array
    {
        $material = $line->purchaseOrderItem?->material;
        $toInventory = '0.00';

        if ($material instanceof LibraryMaterial && ! $material->isBoardTrackable() && ! $material->isConsumableUnit()) {
            $stock = Stock::where('material_id', $material->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $onHand = bcsub(number_format((float) ($stock?->quantity_on_hand ?? 0), 6, '.', ''), $claimed[$material->id] ?? '0', 6);
            [$billed, $shelved] = $quantities ?? $this->baseQuantities($line);
            $supportable = bccomp($shelved, $onHand, 6) > 0 ? $onHand : $shelved;

            if (bccomp($billed, '0', 6) > 0 && bccomp($supportable, '0', 6) > 0) {
                $toInventory = $this->round(bcdiv(bcmul($amount, $supportable, 10), $billed, 10));
                // A reduction may not take stock below nothing.
                $carrying = $this->round(bcmul($supportable, number_format((float) $material->unit_cost, 6, '.', ''), 10));
                if (bccomp($toInventory, '0.00', 2) < 0 && bccomp(ltrim($toInventory, '-'), $carrying, 2) > 0) {
                    $toInventory = '-'.$carrying;
                }
                $claimed[$material->id] = bcadd($claimed[$material->id] ?? '0', $supportable, 6);
            }
        }

        return [$toInventory, bcsub($amount, $toInventory, 2)];
    }

    /**
     * Move an amount into (or out of) a material's stock value: the weighted
     * average changes so that quantity on hand × average changes by exactly
     * the amount the Inventory account is being posted.
     */
    public function revalue(int $materialId, string $amount): void
    {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }
        $stock = Stock::where('material_id', $materialId)->lockForUpdate()->first();
        $material = LibraryMaterial::whereKey($materialId)->lockForUpdate()->firstOrFail();
        $onHand = number_format((float) ($stock?->quantity_on_hand ?? 0), 6, '.', '');
        if (bccomp($onHand, '0', 6) <= 0) {
            throw new \InvalidArgumentException("Stock of {$material->material_name} changed while its invoice was being posted. Try again.");
        }
        $average = bcadd(number_format((float) $material->unit_cost, 6, '.', ''), bcdiv($amount, $onHand, 6), 6);
        $material->forceFill(['unit_cost' => bccomp($average, '0', 6) > 0 ? $average : '0'])->save();
    }

    /**
     * The line's billed quantity in stock units, and how much of that Stores
     * has actually put on the shelf.
     *
     * @return array{0: string, 1: string}
     */
    public function baseQuantities(BillLine $line): array
    {
        $billed = '0';
        $shelved = '0';
        foreach ($line->allocations as $allocation) {
            $receipt = $allocation->goodsReceiptNoteItem;
            $quantity = bcsub((string) $allocation->quantity, (string) $allocation->released_quantity, 6);
            $received = number_format((float) ($receipt?->received_quantity ?? 0), 6, '.', '');
            $inStockUnits = number_format((float) ($receipt?->stock_quantity ?? 0), 6, '.', '');
            $base = bccomp($inStockUnits, '0', 6) > 0 && bccomp($received, '0', 6) > 0
                ? bcdiv(bcmul($quantity, $inStockUnits, 10), $received, 6)
                : $quantity;
            $billed = bcadd($billed, $base, 6);
            if ($receipt?->inventory_log_id) {
                $shelved = bcadd($shelved, $base, 6);
            }
        }

        return [$billed, $shelved];
    }

    private function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $rounded = bcadd(ltrim($value, '-'), '0.005', 2);

        return ($negative && bccomp($rounded, '0', 2) !== 0 ? '-' : '').$rounded;
    }
}
