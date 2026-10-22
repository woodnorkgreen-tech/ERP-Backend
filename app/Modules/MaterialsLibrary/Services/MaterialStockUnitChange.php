<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the unit a material is counted in after stock has moved.
 *
 * Every stock-unit quantity recorded for the material is a count in the old
 * unit, so the unit cannot simply be swapped — 37 boxes would silently become
 * 37 pieces. Instead the same physical stock is re-measured: one old unit is
 * `factor` new units, every stock-unit quantity is multiplied by it and every
 * per-stock-unit cost divided by it, in one transaction. Movement values and
 * the ledger are unchanged and nothing is posted; the catalogue average cost
 * is held to cents, so stock value can move by that rounding — both values are
 * recorded on the change.
 *
 * Movement history keeps what was typed: a movement entered in the old unit
 * records that entry (entered_quantity, entered_uom_id) and the factor that
 * reads it in the new stock unit. The old unit stays readable everywhere
 * through a conversion, so open orders and material lists in it still work.
 *
 * Items with per-piece identity (boards, serial numbers, rolls) are refused:
 * a sheet or a serial number cannot be re-measured into fractions.
 */
class MaterialStockUnitChange
{
    private const OPEN_COUNT_STATUSES = ['draft', 'submitted', 'in_progress', 'counting', 'pending_review'];

    /**
     * Open counts are restated with everything else (system, counted and
     * variance quantities alike), so a count in progress keeps its meaning;
     * the caller is told which ones, since counters may be holding paper
     * sheets in the old unit.
     *
     * @return array<int, string>
     */
    public function openCountsFor(int $materialId): array
    {
        return DB::table('stock_count_items as i')->join('stock_counts as c', 'c.id', '=', 'i.stock_count_id')
            ->where('i.material_id', $materialId)->whereIn('c.status', self::OPEN_COUNT_STATUSES)
            ->distinct()->pluck('c.count_number')->all();
    }

    /**
     * @return array{material: LibraryMaterial, change_id: int, on_hand_before: string, on_hand_after: string, value_before: string, value_after: string}
     */
    public function change(LibraryMaterial $material, int $toUomId, string $factor, string $reason, int $userId): array
    {
        return DB::transaction(function () use ($material, $toUomId, $factor, $reason, $userId) {
            $material = LibraryMaterial::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $from = UnitOfMeasure::find($material->base_uom_id);
            $to = UnitOfMeasure::findOrFail($toUomId);
            $this->assertChangeable($material, $from, $to, $factor);
            $f = $this->decimal($factor);

            $stock = DB::table('stocks')->where('material_id', $material->id)->lockForUpdate()->first();
            $before = $this->decimal($stock->quantity_on_hand ?? 0);
            $value = bcmul($before, $this->decimal($material->unit_cost ?? 0), 2);
            $restated = [];

            $restated['stocks'] = DB::table('stocks')->where('material_id', $material->id)->update([
                'quantity_on_hand' => DB::raw("quantity_on_hand * {$f}"),
                'quantity_reserved' => DB::raw("quantity_reserved * {$f}"),
                'min_stock_level' => DB::raw("min_stock_level * {$f}"),
                'updated_at' => now(),
            ]);
            $restated['inventory_lots'] = DB::table('inventory_lots')->where('material_id', $material->id)->update([
                'quantity_on_hand' => DB::raw("quantity_on_hand * {$f}"),
                'quantity_reserved' => DB::raw("quantity_reserved * {$f}"),
            ]);

            // Keep what was typed, then restate the stock-unit reading of it.
            DB::table('inventory_logs')->where('material_id', $material->id)->whereNull('entered_uom_id')->update([
                'entered_quantity' => DB::raw('quantity'), // signed, like every movement's entry
                'entered_uom_id' => $from->id,
                'uom_conversion_factor' => 1,
            ]);
            $restated['inventory_logs'] = DB::table('inventory_logs')->where('material_id', $material->id)->update([
                'quantity' => DB::raw("quantity * {$f}"),
                'balance_after' => DB::raw("balance_after * {$f}"),
                'uom_conversion_factor' => DB::raw("uom_conversion_factor * {$f}"),
                'receipt_unit_cost' => DB::raw("receipt_unit_cost / {$f}"),
            ]);
            $restated['inventory_movement_allocations'] = DB::table('inventory_movement_allocations')
                ->whereIn('inventory_log_id', DB::table('inventory_logs')->select('id')->where('material_id', $material->id))
                ->update(['quantity' => DB::raw("quantity * {$f}")]);

            DB::table('goods_receipt_note_items')->where('material_id', $material->id)->whereNull('entered_uom_id')
                ->update(['entered_uom_id' => $from->id]);
            $restated['goods_receipt_note_items'] = DB::table('goods_receipt_note_items')->where('material_id', $material->id)
                ->whereNotNull('stock_quantity')->update(['stock_quantity' => DB::raw("stock_quantity * {$f}")]);

            $restated['stock_count_items'] = DB::table('stock_count_items')->where('material_id', $material->id)->update([
                'system_quantity' => DB::raw("system_quantity * {$f}"),
                'counted_quantity' => DB::raw("counted_quantity * {$f}"),
                'variance_quantity' => DB::raw("variance_quantity * {$f}"),
                'opening_unit_cost' => DB::raw("opening_unit_cost / {$f}"),
            ]);

            // Buying documents carry their own unit; one left blank meant
            // "the buying unit, else the stock unit" — pin it to what it meant.
            $buyingId = $material->purchase_uom_id ?: $from->id;
            foreach (['requisition_items', 'purchase_order_items'] as $table) {
                DB::table($table)->where('material_id', $material->id)->whereNull('uom_id')->update(['uom_id' => $buyingId]);
            }

            $this->restateConversions($material, $from, $to, $f);
            $restated['project_lines'] = $this->restateRequirementReadings($material, $from, $to, $f);

            $material->forceFill([
                'base_uom_id' => $to->id,
                'unit_of_measure' => $to->code,
                'purchase_uom_id' => $buyingId,
                'issue_uom_id' => ! $material->issue_uom_id || (int) $material->issue_uom_id === (int) $from->id ? $to->id : $material->issue_uom_id,
                'unit_cost' => $material->unit_cost !== null ? bcdiv($this->decimal($material->unit_cost), $f, 6) : null,
                'default_unit_cost' => $material->default_unit_cost !== null ? bcdiv($this->decimal($material->default_unit_cost), $f, 6) : null,
                'updated_by' => $userId,
            ])->save();

            $after = $this->decimal(DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand') ?? 0);
            $valueAfter = bcmul($after, $this->decimal($material->fresh()->unit_cost ?? 0), 2);
            $changeId = DB::table('material_stock_unit_changes')->insertGetId([
                'material_id' => $material->id, 'from_uom_id' => $from->id, 'to_uom_id' => $to->id,
                'factor' => $f, 'on_hand_before' => $before, 'on_hand_after' => $after,
                'stock_value_before' => $value, 'stock_value_after' => $valueAfter,
                'restated' => json_encode($restated), 'reason' => $reason, 'changed_by' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['material' => $material->fresh(['uomConversions', 'baseUom']), 'change_id' => $changeId, 'on_hand_before' => $before, 'on_hand_after' => $after, 'value_before' => $value, 'value_after' => $valueAfter];
        });
    }

    private function assertChangeable(LibraryMaterial $material, ?UnitOfMeasure $from, UnitOfMeasure $to, string $factor): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $from) {
            $fail('to_uom_id', 'This material has no stock unit yet — just choose one in the form.');
        }
        if ((int) $from->id === (int) $to->id) {
            $fail('to_uom_id', "It is already counted in {$to->name}.");
        }
        if (bccomp($this->decimal($factor), '0', 6) <= 0) {
            $fail('factor', "Enter how many {$to->code} are in one {$from->code}.");
        }
        if ($material->isBoardTrackable() || $material->is_serialized || $material->isConsumableUnit()
            || in_array($material->tracking_mode, ['serialized_item', 'dimension_piece', 'consumable_unit'], true)) {
            $fail('to_uom_id', 'Boards, serial-numbered items and rolls are tracked piece by piece, so their stock unit cannot be re-measured.');
        }
        if (DB::table('inventory_logs')->where('material_id', $material->id)->where('finance_sync_status', 'failed')->exists()) {
            $fail('to_uom_id', 'A movement of this item failed to reach Finance. Resolve it first, so its value is posted as recorded.');
        }
    }

    /** Conversions into the old unit now lead into the new one; the old unit stays readable. */
    private function restateConversions(LibraryMaterial $material, UnitOfMeasure $from, UnitOfMeasure $to, string $f): void
    {
        $rows = DB::table('material_uom_conversions')->where('material_id', $material->id)->get();
        DB::table('material_uom_conversions')->where('material_id', $material->id)->delete();

        $conversions = [(int) $from->id => $f];
        foreach ($rows as $row) {
            $source = (int) $row->from_uom_id;
            if ($source === (int) $to->id || $source === (int) $from->id) {
                continue;
            }
            $conversions[$source] = bcmul($this->decimal($row->factor), $f, 6);
        }
        foreach ($conversions as $source => $factor) {
            DB::table('material_uom_conversions')->insert([
                'material_id' => $material->id, 'from_uom_id' => $source, 'to_uom_id' => $to->id,
                'factor' => $factor, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Approved readings of project lines move to the new stock unit with the same meaning. */
    private function restateRequirementReadings(LibraryMaterial $material, UnitOfMeasure $from, UnitOfMeasure $to, string $f): int
    {
        $lines = DB::table('element_materials')->where('library_material_id', $material->id)
            ->whereNotNull('requirement_base_factor')->get(['id', 'requirement_base_factor', 'requirement_unit_basis']);
        foreach ($lines as $line) {
            $basis = json_decode((string) $line->requirement_unit_basis, true) ?: [];
            $factor = bcmul($this->decimal($line->requirement_base_factor), $f, 6);
            $basis = array_merge($basis, ['to' => $to->code, 'factor' => $factor, 'restated_from' => ['unit' => $from->code, 'factor' => $basis['factor'] ?? null, 'by' => $f]]);
            DB::table('element_materials')->where('id', $line->id)->update([
                'requirement_base_uom_id' => $to->id,
                'requirement_base_factor' => $factor,
                'requirement_unit_basis' => json_encode($basis),
            ]);
        }

        return $lines->count();
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
