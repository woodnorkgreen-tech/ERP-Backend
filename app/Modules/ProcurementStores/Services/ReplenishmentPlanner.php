<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\CostCollector\Services\MaterialExpenseCodeResolver;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * What needs buying, worked out rather than remembered.
 *
 * Every requisition in this system begins with a person deciding, unaided, that
 * something is running out — `Requisition::create` has exactly one production
 * caller and it is a form. Stores could already see, on the demand forecast,
 * which materials approved jobs would exhaust; nothing turned that into a
 * purchase, and `min_stock_level` had been a badge on a listing since the
 * table was created. So the two facts that should drive buying were both
 * visible and neither was actionable.
 *
 * This produces the arithmetic. It does not produce the decision: a suggestion
 * becomes a DRAFT requisition that a buyer confirms, edits or discards. Nothing
 * here orders anything, and nothing here writes to stock.
 *
 * ## The one calculation
 *
 *   free      = on hand − held by board requests
 *   projected = free + incoming (open POs) − demand (approved, unissued jobs)
 *   suggested = max(0, min_stock_level − projected)
 *
 * `projected` is the position once every commitment already made has played
 * out. Reading the minimum against it, rather than against today's shelf, is
 * what stops the two triggers double-counting: a material short for jobs is
 * already below its minimum by definition, so one subtraction answers both and
 * the reason merely says which side of zero it landed on.
 */
class ReplenishmentPlanner
{
    /** Orders that can still deliver. Mirrors the demand forecast's view of incoming. */
    private const OPEN_PO_EXCLUDED = ['cancelled', 'rejected', 'completed'];

    public function __construct(
        private ProjectMaterialDemand $demand,
        private MaterialExpenseCodeResolver $expenseCodes,
    ) {}

    /**
     * Every material whose projected position falls short, worst first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suggestions(): Collection
    {
        $demandByMaterial = $this->demand->pendingByMaterial();

        // Two populations, because there are two reasons to buy: something a
        // job is waiting for, and something the store is meant to keep. A
        // material can qualify under either without qualifying under the other.
        $candidateIds = collect($demandByMaterial)->keys()
            ->merge($this->materialsHeldToAMinimum())
            ->unique()->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        $materials = LibraryMaterial::with(['stock', 'baseUom', 'purchaseUom'])
            ->whereIn('id', $candidateIds)
            ->get();

        $incoming = $this->incomingByMaterial($candidateIds->all());
        $boardsAvailable = $this->boardsAvailableFor($materials);

        return $materials->map(function (LibraryMaterial $material) use ($demandByMaterial, $incoming, $boardsAvailable) {
            $reserved = (float) ($material->stock?->quantity_reserved ?? 0);

            // A board-tracked material's truth is the count of physical boards
            // marked Available, not the stock row — the same substitution the
            // demand forecast makes, so the two screens cannot disagree about
            // what is on the shelf.
            $onHand = $material->isBoardTrackable()
                ? (float) ($boardsAvailable[$material->id] ?? 0)
                : (float) ($material->stock?->quantity_on_hand ?? 0);

            $free = max(0.0, $onHand - $reserved);
            $demand = (float) ($demandByMaterial[$material->id] ?? 0);
            $incomingQuantity = (float) ($incoming[$material->id] ?? 0);
            $minimum = (float) ($material->stock?->min_stock_level ?? 0);

            $projected = $free + $incomingQuantity - $demand;
            $suggested = max(0.0, $minimum - $projected);

            if ($suggested <= 0) {
                return null;
            }

            return [
                'material_id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'unit' => $material->purchaseUom?->code ?? $material->baseUom?->code ?? $material->unit_of_measure,
                'on_hand' => round($onHand, 4),
                'reserved' => round($reserved, 4),
                'pending_demand' => round($demand, 4),
                'incoming' => round($incomingQuantity, 4),
                'free_stock' => round($free, 4),
                'min_stock_level' => round($minimum, 4),
                'projected_position' => round($projected, 4),
                'suggested_quantity' => round($suggested, 4),
                // Below zero means jobs already approved cannot all be served,
                // even counting what is on order. At or above zero the store
                // simply drops under the buffer it is meant to hold.
                'reason' => $projected < 0 ? 'job_shortfall' : 'below_minimum',
                'urgency' => $projected < 0 ? 'urgent' : 'normal',
                'unit_price' => round($this->indicativePrice($material), 2),
                'expense_code_id' => $this->expenseCodeIdFor($material),
            ];
        })
            ->filter()
            ->sortBy('projected_position')
            ->values();
    }

    /**
     * Materials Stores has said it keeps a buffer of. A zero minimum is not a
     * policy of holding none — it is the default nobody has set, and treating
     * it as a target would suggest buying every catalogue item ever stocked.
     *
     * @return Collection<int, int>
     */
    private function materialsHeldToAMinimum(): Collection
    {
        return LibraryMaterial::query()
            ->governed()
            ->whereHas('stock', fn ($query) => $query->where('min_stock_level', '>', 0))
            ->pluck('id');
    }

    /**
     * Ordered and not yet received. Deliberately counts the unreceived balance
     * of a part-delivered line rather than the whole order, so a delivery that
     * already landed is not counted twice — once in stock and once as incoming.
     *
     * @param  array<int, int>  $materialIds
     * @return array<int, float>
     */
    private function incomingByMaterial(array $materialIds): array
    {
        return PurchaseOrderItem::query()
            ->whereIn('material_id', $materialIds)
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', self::OPEN_PO_EXCLUDED))
            ->withSum('goodsReceiptNoteItems as received_total', 'received_quantity')
            ->get(['id', 'material_id', 'quantity'])
            ->groupBy('material_id')
            ->map(fn (Collection $rows) => (float) $rows->sum(
                fn ($row) => max(0, (float) $row->quantity - (float) ($row->received_total ?? 0))
            ))
            ->all();
    }

    /** @return array<int, int> */
    private function boardsAvailableFor(Collection $materials): array
    {
        $boardIds = $materials->filter(fn (LibraryMaterial $material) => $material->isBoardTrackable())
            ->pluck('id');

        if ($boardIds->isEmpty()) {
            return [];
        }

        return Board::query()
            ->whereIn('library_material_id', $boardIds)
            ->where('status', 'Available')
            ->selectRaw('library_material_id, COUNT(*) AS quantity')
            ->groupBy('library_material_id')
            ->pluck('quantity', 'library_material_id')
            ->all();
    }

    /**
     * What the line is priced at before anyone sources it.
     *
     * The moving average first, because it is what this company has actually
     * been paying; the catalogue default only when nothing has been bought yet.
     * A draft priced at zero would total zero and read as free.
     */
    private function indicativePrice(LibraryMaterial $material): float
    {
        return (float) $material->unit_cost > 0
            ? (float) $material->unit_cost
            : (float) ($material->default_unit_cost ?? 0);
    }

    /**
     * Classified by the same resolver the picker and the Stores issue use, so a
     * suggested line and a hand-typed one for the same material reach the cost
     * ledger under the same code. `allowFallback: false` — a material the
     * catalogue cannot classify is left for the buyer to code rather than
     * guessed at.
     */
    private function expenseCodeIdFor(LibraryMaterial $material): ?int
    {
        $code = $this->expenseCodes->resolve($material, allowFallback: false);

        return $code
            ? \App\Modules\Finance\CostCollector\Models\ExpenseCode::where('code', $code)->value('id')
            : null;
    }
}
