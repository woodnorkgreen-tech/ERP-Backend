<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use Illuminate\Support\Collection;

/**
 * The one Stores inventory valuation (Report 61 §36).
 *
 * The method is the one the Stores screen has always used: a **moving
 * weighted average**. InventoryService re-averages `library_materials.unit_cost`
 * on every priced receipt, and stock on hand is valued at that average. Boards
 * are individually tracked, so a board material is valued as the sum of its
 * boards' `current_value` while in stores (Available or Quarantine).
 *
 * The Stores inventory summary and the Finance inventory position both call
 * valueOf(), so the two screens cannot value the same stock two ways.
 */
class InventoryValuationService
{
    /** A material's stock value, given its on-hand quantity and (for boards) its board counts. */
    public function valueOf(LibraryMaterial $material, float $onHand, ?object $boardCount): float
    {
        if ($material->isConsumableUnit()) return (float) (app(ConsumableUnitService::class)->summary($material)['authoritative_value'] ?? 0);
        return $material->isBoardTrackable()
            ? (float) ($boardCount?->in_stores_value ?? 0)
            : $onHand * (float) $material->unit_cost;
    }

    /** Board counts per material: in stores, available, and the value of those in stores. */
    public function boardCounts(Collection $materialIds): Collection
    {
        if ($materialIds->isEmpty()) {
            return collect();
        }

        return Board::whereIn('library_material_id', $materialIds)
            ->selectRaw("library_material_id,
                SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_cnt,
                SUM(CASE WHEN status IN ('Available', 'Quarantine') THEN 1 ELSE 0 END) AS in_stores_cnt,
                SUM(CASE WHEN status IN ('Available', 'Quarantine') THEN current_value ELSE 0 END) AS in_stores_value")
            ->groupBy('library_material_id')
            ->get()
            ->keyBy('library_material_id');
    }

    /**
     * Every stocked material with its value.
     *
     * @return array{total: string, lines: Collection<int, array<string, mixed>>}
     */
    public function valuation(): array
    {
        $materials = LibraryMaterial::query()
            ->whereHas('stock')
            ->with(['stock:id,material_id,quantity_on_hand', 'materialCategory:id,name,parent_id', 'materialCategory.parent:id,name'])
            ->get(['library_materials.id', 'library_materials.material_code', 'library_materials.material_name',
                'library_materials.material_category_id', 'library_materials.category', 'library_materials.unit_cost',
                'library_materials.material_type', 'library_materials.tracking_mode', 'library_materials.valuation_method']);
        $boards = $this->boardCounts($materials->filter(fn (LibraryMaterial $m) => $m->isBoardTrackable())->pluck('id'));

        $lines = $materials->map(function (LibraryMaterial $m) use ($boards) {
            $bc = $boards->get($m->id);
            $onHand = $m->isBoardTrackable() ? (float) ($bc?->in_stores_cnt ?? 0) : (float) ($m->stock?->quantity_on_hand ?? 0);

            return [
                'material_id' => $m->id,
                'code' => $m->material_code,
                'name' => $m->material_name,
                'category' => $m->category,
                'on_hand' => $onHand,
                'unit_cost' => number_format((float) $m->unit_cost, 2, '.', ''),
                'board_tracked' => $m->isBoardTrackable(),
                'valuation_method' => $m->valuation_method,
                'value' => round($this->valueOf($m, $onHand, $bc), 2),
            ];
        })->values();

        return ['total' => number_format((float) $lines->sum('value'), 2, '.', ''), 'lines' => $lines];
    }
}
