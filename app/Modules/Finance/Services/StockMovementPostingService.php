<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\ProcurementStores\Models\StockCount;
use InvalidArgumentException;

/**
 * Tells the accounts what a physical stock count found.
 *
 * ## The problem this closes
 *
 * Counting stock changed a quantity and wrote no accounting entry at all. So the
 * ledger's idea of what the stock is worth and the store's idea of what is on
 * the shelf could only ever drift apart, with nothing detecting it.
 *
 * Measured on 2026-09-08, before this existed: the ledger's
 * `1200 Raw-material Inventory` stood at **negative 76,580** while Stores valued
 * the same stock at **positive 55,910**. A negative inventory asset is not a
 * rounding problem — you cannot own less than nothing. The cause was an absence:
 * stock that existed before this ledger did was never entered into it, so issues
 * were relieving an account nothing had ever added to.
 *
 * ## Two different events, one screen
 *
 * `StockCountController` runs both, and they are NOT the same accounting event:
 *
 * **Opening inventory** — the stock WNG already had on the day the books open.
 * There is no purchase behind it to credit, because the buying happened before
 * the ledger existed. The other side is equity: it is part of what the owners
 * already had.
 *
 *     Debit  Raw-material Inventory     what the stock is worth
 *     Credit Opening Balance Equity     it was already ours
 *
 * **A cycle count** — a later count that disagrees with the records. Finding less
 * than the books claim is a real loss: breakage, theft, a mis-recorded issue. A
 * loss is an expense, not a quiet edit to a number.
 *
 *     shortage:  Debit Inventory Adjustments  ·  Credit Raw-material Inventory
 *     surplus:   Debit Raw-material Inventory ·  Credit Inventory Adjustments
 *
 * Getting these the same way round would be the expensive mistake: charging
 * opening stock to an expense account would report the whole of WNG's existing
 * store as a loss in the month the books opened.
 */
class StockMovementPostingService
{
    private const INVENTORY_CODE = '1200';
    private const ADJUSTMENT_CODE = '6800';
    private const OPENING_EQUITY_CODE = '3900';

    public function __construct(private JournalPostingService $posting)
    {
    }

    /**
     * Post what an approved count found.
     *
     * Returns null when the count found nothing worth money — every item agreed,
     * or the differences were all on materials with no cost against them. That is
     * a real and common outcome, not a failure.
     *
     * Valued per item, then netted into two legs. One entry per count rather than
     * per item: a count is a single event a person signed off, and forty legs for
     * forty materials makes the inventory account unreadable without adding a
     * fact the count itself does not already record.
     */
    public function postStockCount(StockCount $count, ?int $actorId = null): ?JournalEntry
    {
        $entryNo = 'JE-STK-' . str_pad((string) $count->id, 7, '0', STR_PAD_LEFT);

        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        $isOpening = $count->mode === StockCount::MODE_OPENING;
        $net = $this->netValue($count, $isOpening);

        if (bccomp($net, '0.00', 2) === 0) {
            return null;
        }

        $inventory = $this->account(self::INVENTORY_CODE, 'Raw-material Inventory');
        $counterpart = $isOpening
            ? $this->account(self::OPENING_EQUITY_CODE, 'Opening Balance Equity')
            : $this->account(self::ADJUSTMENT_CODE, 'Inventory Adjustments & Shrinkage');

        // A surplus increases what WNG holds, so inventory is debited; a shortage
        // is the same entry with the legs swapped. Working in absolute value and
        // choosing the direction keeps both legs positive, which is what a
        // readable account statement needs.
        $stockWentUp = bccomp($net, '0.00', 2) > 0;
        $amount = ltrim($net, '-');

        $inventoryLeg = [
            'account_id' => $inventory,
            'entry_type' => $stockWentUp ? 'debit' : 'credit',
            'amount' => $amount,
            'description' => $isOpening
                ? 'Opening stock on hand'
                : ($stockWentUp ? 'Surplus found on count' : 'Shortage found on count'),
        ];

        $counterpartLeg = [
            'account_id' => $counterpart,
            'entry_type' => $stockWentUp ? 'credit' : 'debit',
            'amount' => $amount,
            'description' => $isOpening
                ? 'Stock already held when the books opened'
                : 'Difference between the count and the records',
        ];

        return $this->posting->postBalancedEntry(
            entryNo: $entryNo,
            // Dated when the count was approved, not when it was started: the
            // approval is the decision that changed the records.
            postingDate: ($count->reviewed_at ?? now())->toDateString(),
            sourceType: StockCount::class,
            sourceId: $count->id,
            sourceRef: $count->count_number,
            description: $isOpening
                ? 'Opening inventory ' . $count->count_number
                : 'Stock count adjustment ' . $count->count_number,
            legs: [$inventoryLeg, $counterpartLeg],
            createdBy: $actorId,
        );
    }

    /**
     * What the count is worth, netted across its items.
     *
     * Opening inventory is valued at the cost typed against each line, because
     * that is the whole point of the opening exercise — somebody states what the
     * stock is worth. A cycle count values the DIFFERENCE at the material's
     * current catalogue cost, because the difference is a quantity and the
     * quantity has to be priced by something.
     *
     * A material with no cost contributes nothing rather than zero-valuing the
     * whole count: it means "we do not know what this is worth", and inventing a
     * figure would put an unsupported number in the accounts. The quantity is
     * still corrected — that part of the count always stands.
     */
    private function netValue(StockCount $count, bool $isOpening): string
    {
        $count->loadMissing('items.material');
        $net = '0.00';

        foreach ($count->items as $item) {
            $quantity = $isOpening
                ? $this->decimal($item->counted_quantity)
                : $this->decimal($item->variance_quantity);

            if (bccomp($quantity, '0.000000', 6) === 0) {
                continue;
            }

            $rate = $isOpening
                ? $this->decimal($item->opening_unit_cost)
                : $this->decimal($item->material?->unit_cost);

            if (bccomp($rate, '0.000000', 6) <= 0) {
                continue;
            }

            $net = bcadd($net, $this->money(bcmul($quantity, $rate, 6)), 2);
        }

        return $net;
    }

    private function account(string $referenceCode, string $plainName): int
    {
        $localCode = ChartAccountMap::local($referenceCode);
        $id = ChartOfAccount::postable()->where('code', $localCode)->value('id');

        if (! $id) {
            throw new InvalidArgumentException(
                "This installation has no active, postable \"{$plainName}\" account "
                . "(expected chart code {$localCode}). Finance must create it, or map reference "
                . "code {$referenceCode} in config/finance_accounts.php, before a stock count "
                . 'can reach the accounts.'
            );
        }

        return (int) $id;
    }

    private function money(string|float|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function decimal(string|float|null $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
