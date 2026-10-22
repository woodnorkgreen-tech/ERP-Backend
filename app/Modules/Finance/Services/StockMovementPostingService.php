<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\GovernedPostingAccount;
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
    private const INVENTORY_CODE = FinanceAccountFunctions::INVENTORY;

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
        // Report 76A-3: the counterpart is an approved, active decision or the
        // count is not approved at all — the reference chart no longer answers.
        $counterpart = $isOpening
            ? GovernedPostingAccount::require(GovernedPostingAccount::STOCK_OPENING_BALANCE, "opening inventory {$count->count_number}")
            : GovernedPostingAccount::require(GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT, "stock count {$count->count_number}");

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
     * Stock that left Stores for a reason other than a project
     * (Report 76A Part 2, Decision D).
     *
     *     Debit   the approved account for that reason
     *     Credit  Raw-material Inventory
     *
     * Company use, damage and waste each have their own governed account and
     * none has a default: with no approved account this throws, naming the
     * one that is missing, and the caller records the posting as failed.
     * Nothing here creates a cost line — these are never a project's cost.
     *
     * Valued at what the movement froze when the stock left: the roll's own
     * value, or quantity at the frozen unit cost. Stock that was carried at
     * nothing has no value to move, and returns null.
     */
    public function postNonProjectReduction(\App\Modules\ProcurementStores\Models\InventoryLog $log, ?int $actorId = null): ?JournalEntry
    {
        $entryNo = 'JE-STM-' . str_pad((string) $log->id, 7, '0', STR_PAD_LEFT);
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        [$function, $label] = match ($log->nonProjectPostingType()) {
            'internal_consumption' => [GovernedPostingAccount::STOCK_INTERNAL_CONSUMPTION, 'Stock used by the company'],
            'damage_loss' => [GovernedPostingAccount::STOCK_DAMAGE_LOSS, 'Stock written off as damaged'],
            'waste_loss' => [GovernedPostingAccount::STOCK_WASTE_LOSS, 'Stock written off as waste'],
            default => throw new InvalidArgumentException("Stock movement {$log->id} is not a company-use, damage or waste movement."),
        };

        $value = $this->movementValue($log);
        if (bccomp($value, '0.00', 2) <= 0) {
            return null;
        }

        $name = $log->material?->material_name ?? "material #{$log->material_id}";
        $debit = GovernedPostingAccount::require($function, strtolower($label)." ({$name}, movement {$log->id})");
        $inventory = $this->account(self::INVENTORY_CODE, 'Raw-material Inventory');

        $entry = $this->posting->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: ($log->logged_at ?? now())->toDateString(),
            sourceType: get_class($log),
            sourceId: $log->id,
            sourceRef: $log->reference_no ?: $log->batch_number,
            description: "{$label}: {$name}",
            legs: [
                ['account_id' => $debit, 'entry_type' => 'debit', 'amount' => $value,
                    'description' => $label . ($log->notes ? ' — ' . $log->notes : ''), 'cost_centre_id' => $log->cost_centre_id],
                ['account_id' => $inventory, 'entry_type' => 'credit', 'amount' => $value, 'description' => 'Stock relieved'],
            ],
            createdBy: $actorId ?? $log->user_id,
        );

        return $entry;
    }

    /**
     * Stock that entered Stores without a purchase (Report 76A Part 2, Decision C).
     *
     *     Debit   Raw-material Inventory
     *     Credit  Opening Balance Equity      — stock WNG held before the books opened
     *             Inventory Adjustments       — stock found, or a correction
     *
     * The same two counterparts a stock count uses for the same two facts, so
     * a roll or a board brought in this way and a bulk item counted in agree
     * about what happened. Posted inside the receipt's own transaction: stock
     * with no purchase behind it may not appear on the shelf and stay out of
     * the ledger, which is the gap Report 76 P0-5 found.
     */
    public function postExceptionalReceipt(\App\Modules\ProcurementStores\Models\InventoryLog $log, ?int $actorId = null, ?string $unitValue = null): JournalEntry
    {
        $entryNo = 'JE-STX-' . str_pad((string) $log->id, 7, '0', STR_PAD_LEFT);
        if ($existing = JournalEntry::where('entry_no', $entryNo)->first()) {
            return $existing;
        }

        $value = $this->movementValue($log);
        if (bccomp($value, '0.00', 2) <= 0 && $unitValue !== null) {
            // No price was entered on the receipt: the catalogue value Stores used.
            $value = $this->money(bcmul(ltrim($this->decimal($log->quantity), '-'), $this->decimal($unitValue), 6));
        }
        if (bccomp($value, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('Stock received without a purchase needs a value: enter what each unit is worth.');
        }

        $opening = $log->movement_reason === \App\Modules\ProcurementStores\Models\InventoryLog::REASON_OPENING_BALANCE;
        $inventory = $this->account(self::INVENTORY_CODE, 'Raw-material Inventory');
        $name = $log->material?->material_name ?? "material #{$log->material_id}";
        $counterpart = $opening
            ? GovernedPostingAccount::require(GovernedPostingAccount::STOCK_OPENING_BALANCE, "opening stock of {$name}")
            : GovernedPostingAccount::require(GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT, "the stock adjustment for {$name}");
        $label = match ($log->movement_reason) {
            \App\Modules\ProcurementStores\Models\InventoryLog::REASON_OPENING_BALANCE => 'Opening stock',
            \App\Modules\ProcurementStores\Models\InventoryLog::REASON_DISCOVERED_STOCK => 'Stock found',
            default => 'Stock correction',
        };

        $entry = $this->posting->postBalancedEntry(
            entryNo: $entryNo,
            postingDate: ($log->logged_at ?? now())->toDateString(),
            sourceType: get_class($log),
            sourceId: $log->id,
            sourceRef: $log->reference_no ?: $log->batch_number,
            description: "{$label}: {$name}",
            legs: [
                ['account_id' => $inventory, 'entry_type' => 'debit', 'amount' => $value, 'description' => "{$label} taken into Stores"],
                ['account_id' => $counterpart, 'entry_type' => 'credit', 'amount' => $value,
                    'description' => $label . ($log->notes ? ' — ' . $log->notes : '')],
            ],
            createdBy: $actorId ?? $log->user_id,
        );

        // The same record a project issue's posting leaves, so one list shows
        // every Stores movement's accounting and a reversal finds its entry.
        \App\Modules\ProcurementStores\Models\StoresFinancePosting::updateOrCreate(
            ['inventory_log_id' => $log->id, 'posting_type' => \App\Modules\ProcurementStores\Models\InventoryLog::POSTING_EXCEPTIONAL_RECEIPT],
            ['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'attempts' => 1],
        );

        return $entry;
    }

    /** What a movement is worth, as it was frozen when the stock moved. */
    private function movementValue(\App\Modules\ProcurementStores\Models\InventoryLog $log): string
    {
        if ($log->movement_value !== null) {
            return $this->money(ltrim((string) $log->movement_value, '-'));
        }

        return $this->money(bcmul(ltrim($this->decimal($log->quantity), '-'), $this->decimal($log->receipt_unit_cost), 6));
    }

    /** Uses the same installation account mapping and journal engine as material counts. */
    public function postControlledCount(\App\Modules\ProcurementStores\Models\InventoryLog $log, int $actor): array
    {
        if ($log->movement_value === null) return ['status'=>'UNVALUED','journal_entry_id'=>null];
        if (bccomp($log->movement_value,'0',2) === 0) return ['status'=>'NO_VALUE_CHANGE','journal_entry_id'=>null];
        // Report 76A-3: thrown, not returned. The roll count's quantity change
        // and this entry are one transaction; a count that cannot be posted is
        // not approved, so the shelf and the ledger never part company.
        $inventory=$this->account(self::INVENTORY_CODE,'Raw-material Inventory');
        $adjustment=GovernedPostingAccount::require(GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT, 'the roll count adjustment '.$log->reference_no);
        $up=bccomp($log->movement_value,'0',2)>0;
        $amount=ltrim($log->movement_value,'-');
        $entry=$this->posting->postBalancedEntry(
            entryNo:'JE-CU-ADJ-'.str_pad((string) $log->id,7,'0',STR_PAD_LEFT), postingDate:now()->toDateString(),
            sourceType: get_class($log), sourceId:$log->id, sourceRef:$log->reference_no,
            description:'Controlled unit count adjustment '.$log->reference_no,
            legs:[['account_id'=>$inventory,'entry_type'=>$up ? 'debit' : 'credit','amount'=>$amount,'description'=>'Controlled count variance'],
                ['account_id'=>$adjustment,'entry_type'=>$up ? 'credit' : 'debit','amount'=>$amount,'description'=>'Inventory adjustment']], createdBy:$actor,
        );
        return ['status'=>'POSTED','journal_entry_id'=>$entry->id];
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
