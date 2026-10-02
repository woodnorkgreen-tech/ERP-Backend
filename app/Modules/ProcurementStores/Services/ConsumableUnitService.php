<?php

namespace App\Modules\ProcurementStores\Services;

use App\Events\Stores\StockIssued;
use App\Events\Stores\StockReturned;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\{ConsumableUnit, ConsumableUnitCount, ConsumableUnitMovement, GoodsReceiptNoteItem, InventoryLog, Stock};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Instance-level specific identification, using the existing inventory ledger and cost events. */
class ConsumableUnitService
{
    public function summary(LibraryMaterial $material): array
    {
        $units = ConsumableUnit::where('material_id', $material->id)->orderByRaw("status = 'OPEN' DESC, status = 'UNOPENED' DESC")->orderBy('received_at')->orderBy('id')->get();
        $total = StoresDecimal::sum($units->pluck('remaining_quantity'));
        $stock = Stock::where('material_id', $material->id)->first();
        $recorded = (string) ($stock?->getRawOriginal('quantity_on_hand') ?? '0');
        $difference = bcsub($recorded, $total, 6);
        $reconciled = bccomp($difference, '0', 6) === 0;
        $missing = $units->contains(fn ($u) => bccomp($u->remaining_quantity, '0', 6) > 0 && $u->unit_cost === null);
        $classification = !$reconciled ? StoresValuationReadinessService::REQUIRES_REVIEW
            : ($missing ? StoresValuationReadinessService::UNVALUED : StoresValuationReadinessService::VALUED);
        $held = StoresDecimal::sum($units->where('status', 'HOLD_REVIEW')->pluck('remaining_quantity'));
        $available = bcsub(bcsub($total, $held, 6), (string) ($stock?->quantity_reserved ?? '0'), 6);
        return [
            'material' => $material, 'tracking_method' => 'CONSUMABLE_UNIT',
            'total_remaining' => $total, 'available_quantity' => bccomp($available, '0', 6) < 0 ? '0.000000' : $available,
            'recorded_inventory_balance' => bcadd($recorded, '0', 6), 'difference' => $difference,
            'reconciliation_status' => $reconciled ? 'RECONCILED' : 'DIFFERENCE',
            'readiness' => !$reconciled && $units->isEmpty() ? 'CONTROLLED UNIT BREAKDOWN REQUIRED' : $classification,
            'valuation_classification' => $classification,
            'authoritative_value' => $classification === StoresValuationReadinessService::VALUED ? StoresDecimal::sum($units->pluck('remaining_value'), 2) : null,
            'valued_subtotal' => StoresDecimal::sum($units->pluck('remaining_value'), 2),
            'status_counts' => collect(['UNOPENED', 'OPEN', 'DEPLETED', 'HOLD_REVIEW'])->mapWithKeys(fn ($s) => [$s => $units->where('status', $s)->count()]),
            'offcut_policy' => 'POLICY REQUIRED', 'count_adjustment_policy' => 'REQUIRES REVIEW / APPROVAL', 'units' => $units,
        ];
    }

    public function post(LibraryMaterial $material, mixed $signedQuantity, string $type, array $meta): InventoryLog
    {
        // Called under InventoryService's material lock; also safe for explicit conversions/corrections.
        return DB::transaction(function () use ($material, $signedQuantity, $type, $meta) {
            $material = LibraryMaterial::lockForUpdate()->findOrFail($material->id);
            if (!$material->isConsumableUnit()) $this->reject('material_id', 'This material is not configured for consumable units.');
            if (!in_array($type, ['check_in', 'check_out', 'return', 'defective', 'reversal'], true)) {
                $this->reject('type', 'Controlled-unit adjustments require reviewed unit-level evidence. Record a physical count for review.');
            }
            if (($material->item_status ?? 'Active') !== 'Active' && !in_array($type, ['return', 'reversal', 'defective'], true)) $this->reject('material_id', 'Only active materials can be received or issued.');
            if ($material->is_serialized || $material->is_batch_controlled || $material->isBoardTrackable()) $this->reject('tracking_mode', 'Consumable units cannot also use board, serial or lot tracking.');
            $quantity = StoresDecimal::quantity(ltrim((string) $signedQuantity, '-'));
            $enteredUom = $meta['entered_uom_id'] ?? $material->base_uom_id;
            if ($enteredUom && (int) $enteredUom !== (int) $material->base_uom_id) {
                $this->reject('entered_uom_id', 'Record actual controlled-unit quantities in the stock UOM; packaging count is descriptive.');
            }
            Stock::firstOrCreate(['material_id' => $material->id], ['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'warehouse_code' => 'MAIN', 'tracking_mode' => Stock::TRACK_BY_COUNT]);
            $stock = Stock::where('material_id', $material->id)->lockForUpdate()->firstOrFail();
            $summary = $this->summary($material);
            if ($summary['reconciliation_status'] !== 'RECONCILED') $this->reject('material_id', 'Controlled units differ from the inventory balance. Physically identify existing stock or review the variance before moving stock.');
            if ($type === 'check_in') return $this->receive($material, $stock, $quantity, $meta);

            if ($type === 'reversal' && empty($meta['original_issue_log_id'])) return $this->reverseReceipt($material, $stock, $quantity, $meta);
            $issue = null;
            if ($type === 'return' || ($type === 'reversal' && !empty($meta['original_issue_log_id']))) {
                $issue = InventoryLog::whereKey($meta['original_issue_log_id'] ?? 0)->lockForUpdate()->first();
                if (!$issue || !$issue->consumable_unit_id || (int) $issue->material_id !== (int) $material->id || !in_array($issue->type, ['check_out', 'issue', 'consumption'], true)) $this->reject('original_issue_log_id', 'Select the original issue from this controlled unit.');
                if (InventoryLog::where('reversal_of_log_id', $issue->id)->exists()) $this->reject('original_issue_log_id', 'This issue has already been reversed.');
                $returned = InventoryLog::where('original_issue_log_id', $issue->id)->whereIn('type', ['return', 'reversal'])->sum('quantity');
                if (bccomp(bcadd((string) $returned, $quantity, 6), ltrim($issue->quantity, '-'), 6) > 0) $this->reject('quantity', 'Return exceeds the unreturned original issue quantity.');
                if (!empty($meta['consumable_unit_id']) && (int) $meta['consumable_unit_id'] !== (int) $issue->consumable_unit_id) $this->reject('consumable_unit_id', 'Return to the original roll or create an offcut child.');
                $meta['project_id'] = $issue->project_id;
                $meta['project_material_id'] = $issue->project_material_id;
                $meta['consumable_unit_id'] = $issue->consumable_unit_id;
            }
            $unit = ConsumableUnit::where('material_id', $material->id)->whereKey($meta['consumable_unit_id'] ?? 0)->lockForUpdate()->first();
            if (!$unit) $this->reject('consumable_unit_id', 'Select the physical unit being moved.');
            $increase = $issue !== null;
            if (!$increase && in_array($unit->status, ['HOLD_REVIEW', 'DEPLETED'], true)) $this->reject('consumable_unit_id', 'This unit is depleted or on hold.');
            if (!$increase && $unit->unit_cost === null) $this->reject('consumable_unit_id', 'Receipt valuation is missing. Review authoritative cost before consumption.');
            if ($type === 'defective' && strlen(trim($meta['notes'] ?? '')) < 5) $this->reject('notes', 'Waste requires a documented reason.');
            $before = $unit->remaining_quantity;
            $delta = $increase ? $quantity : '-'.$quantity;
            $next = bcadd($before, $delta, 6);
            if (bccomp($next, '0', 6) < 0) $this->reject('quantity', 'Issue exceeds this unit’s remaining quantity.');
            $stockNext = bcadd($summary['total_remaining'], $delta, 6);
            if (!$increase && bccomp($stockNext, (string) $stock->quantity_reserved, 6) < 0) $this->reject('quantity', 'Reserved material cannot be consumed.');
            $offcut = $type === 'return' && ($meta['return_kind'] ?? '') === 'recovered_offcut';
            $value = $increase
                ? StoresDecimal::money(bcmul((string) ($issue->movement_value ?? '0'), bcdiv($quantity, ltrim($issue->quantity, '-'), 12), 12))
                : ($unit->remaining_value === null ? null : (bccomp($next, '0', 6) === 0 ? $unit->remaining_value : StoresDecimal::money(bcmul($unit->unit_cost, $quantity, 12))));
            if ($increase && bccomp(bcadd((string) $returned, $quantity, 6), ltrim($issue->quantity, '-'), 6) === 0) {
                $returnedValue = InventoryLog::where('original_issue_log_id', $issue->id)->whereIn('type', ['return', 'reversal'])->sum('movement_value');
                $value = bcsub((string) $issue->movement_value, (string) $returnedValue, 2);
            }
            if (!$increase && $value !== null && bccomp($value, $unit->remaining_value, 2) > 0) $value = $unit->remaining_value;
            if ($offcut) {
                $unit = $this->createUnit($material, $quantity, $unit->unit_cost, $value, array_merge($meta, ['parent_unit_id' => $unit->id, 'status' => 'OPEN', 'source_receipt_id' => $unit->source_receipt_id, 'supplier_id' => $unit->supplier_id]));
                $before = '0.000000';
                $next = $quantity;
            } else {
                if ($increase && bccomp($next, $unit->original_quantity, 6) > 0) $this->reject('quantity', 'Return would exceed the original physical quantity.');
                $unit->remaining_quantity = $next;
                $unit->remaining_value = $unit->remaining_value === null ? null : ($increase ? bcadd($unit->remaining_value, $value ?? '0', 2) : bcsub($unit->remaining_value, $value ?? '0', 2));
                if (bccomp($next, '0', 6) === 0) {
                    $unit->status = 'DEPLETED'; $unit->depleted_at = now();
                } else {
                    if ($unit->status !== 'HOLD_REVIEW') $unit->status = 'OPEN';
                    $unit->opened_at ??= now(); $unit->depleted_at = null;
                }
                $unit->save();
            }
            $stock->quantity_on_hand = $stockNext;
            $stock->save();
            $log = $this->log($material, $stock, $type, $delta, $unit->unit_cost, $value, array_merge($meta, ['consumable_unit_id' => $unit->id]));
            $this->history($unit, $type, $delta, $before, $next, $value, $meta, $log);
            if ($type === 'check_out' && ($log->project_id || $log->reference_no)) StockIssued::dispatch($log);
            if ($type === 'return') StockReturned::dispatch($log);
            return $log;
        });
    }


    private function reverseReceipt(LibraryMaterial $material, Stock $stock, string $quantity, array $meta): InventoryLog
    {
        $original = InventoryLog::whereKey($meta['reversal_of_log_id'] ?? 0)->lockForUpdate()->first();
        if (!$original || $original->material_id !== $material->id || $original->type !== 'check_in' || bccomp($quantity, $original->quantity, 6) !== 0) $this->reject('movement', 'Select the complete original controlled-unit receipt for reversal.');
        if (InventoryLog::where('reversal_of_log_id', $original->id)->exists()) $this->reject('movement', 'This receipt has already been reversed.');
        $units = ConsumableUnit::where('source_log_id', $original->id)->orderBy('id')->lockForUpdate()->get();
        if ($units->isEmpty() || $units->contains(fn ($unit) => $unit->opened_at !== null || bccomp($unit->remaining_quantity, $unit->original_quantity, 6) !== 0)) $this->reject('movement', 'A receipt may only be reversed while every physical unit remains unopened and unconsumed.');
        if (bccomp(StoresDecimal::sum($units->pluck('remaining_quantity')), $quantity, 6) !== 0) $this->reject('movement', 'Receipt unit breakdown differs from the original movement. Review the discrepancy.');
        $next = bcsub((string) $stock->getRawOriginal('quantity_on_hand'), $quantity, 6);
        if (bccomp($next, (string) $stock->quantity_reserved, 6) < 0) $this->reject('quantity', 'Reserved stock prevents reversing this receipt.');
        $stock->quantity_on_hand = $next; $stock->save();
        $log = $this->log($material, $stock, 'reversal', '-'.$quantity, $original->receipt_unit_cost, $original->movement_value, $meta);
        foreach ($units as $unit) {
            $before = $unit->remaining_quantity; $value = $unit->remaining_value;
            $unit->update(['remaining_quantity' => '0', 'remaining_value' => $value === null ? null : '0', 'status' => 'DEPLETED', 'depleted_at' => now()]);
            $this->history($unit, 'receipt_reversal', '-'.$before, $before, '0', $value, $meta, $log);
        }
        return $log;
    }

    private function receive(LibraryMaterial $material, Stock $stock, string $quantity, array $meta): InventoryLog
    {
        $specs = $meta['controlled_units'] ?? [];
        if (!$specs || count($specs) > 100) $this->reject('controlled_units', 'Identify each received unit and its actual stock quantity (maximum 100 per line).');
        $quantities = array_map(fn ($u) => StoresDecimal::quantity($u['quantity'] ?? null), $specs);
        if (bccomp(StoresDecimal::sum($quantities), $quantity, 6) !== 0) $this->reject('controlled_units', 'The sum of physical unit quantities must equal the received stock quantity.');
        $grn = !empty($meta['grn_item_id']) ? GoodsReceiptNoteItem::with('goodsReceiptNote')->findOrFail($meta['grn_item_id']) : null;
        $meta['source_receipt_id'] = $grn?->goods_receipt_note_id;
        $meta['supplier_id'] = $grn?->goodsReceiptNote?->purchaseOrder?->supplier_id ?? ($meta['supplier_id'] ?? null);
        // GRN unit_price is the buying-unit valuation snapshot, converted only if the GRN is configured in the base unit.
        $cost = StoresDecimal::cost($meta['receipt_unit_cost'] ?? null);
        if ($grn && ($grn->receipt_unit_cost ?? $grn->unit_price ?? $grn->purchaseOrderItem?->unit_price) !== null) {
            $authoritative = StoresDecimal::cost($grn->receipt_unit_cost ?? $grn->unit_price ?? $grn->purchaseOrderItem?->unit_price);
            $buyingId = $grn->purchaseOrderItem?->uom_id ?: $material->purchase_uom_id ?: $material->base_uom_id;
            if ((int) $buyingId !== (int) $material->base_uom_id) {
                $factor = (string) ($material->uomConversions->firstWhere('from_uom_id', $buyingId)?->factor ?? '0');
                if (bccomp($factor, '0', 6) <= 0) $this->reject('entered_uom_id', 'Buying-unit conversion is required for the GRN valuation.');
                $authoritative = bcdiv($authoritative, $factor, 8);
            }
            if ($cost !== null && bccomp($cost, $authoritative, 8) !== 0) $this->reject('receipt_unit_cost', 'Receipt cost must match the accepted GRN valuation per stock unit.');
            $cost = $authoritative;
        }
        $stock->quantity_on_hand = bcadd((string) $stock->getRawOriginal('quantity_on_hand'), $quantity, 6);
        $stock->save();
        $log = $this->log($material, $stock, 'check_in', $quantity, $cost, $cost === null ? null : StoresDecimal::money(bcmul($quantity, $cost, 12)), $meta);
        foreach ($specs as $i => $spec) {
            $value = $cost === null ? null : StoresDecimal::money(bcmul($quantities[$i], $cost, 12));
            $unit = $this->createUnit($material, $quantities[$i], $cost, $value, array_merge($meta, ['source_log_id' => $log->id, 'notes' => $spec['notes'] ?? $meta['notes'] ?? null]));
            $this->history($unit, 'check_in', $quantities[$i], '0', $quantities[$i], $value, $meta, $log);
        }
        $material->forceFill(['is_inventory_visible' => true])->save();
        return $log;
    }

    private function createUnit(LibraryMaterial $material, string $quantity, ?string $cost, ?string $value, array $meta): ConsumableUnit
    {
        // The database ID supplies the existing monotonic sequence, never an unlocked MAX+1.
        $unit = ConsumableUnit::create([
            'unit_code' => 'CU-PENDING-'.strtoupper((string) \Illuminate\Support\Str::ulid()),
            'material_id' => $material->id, 'parent_unit_id' => $meta['parent_unit_id'] ?? null,
            'source_receipt_id' => $meta['source_receipt_id'] ?? null, 'source_log_id' => $meta['source_log_id'] ?? null,
            'supplier_id' => $meta['supplier_id'] ?? null, 'source_reference' => $meta['reference_no'] ?? null,
            'original_quantity' => $quantity, 'remaining_quantity' => $quantity,
            'unit_of_measure' => $material->unit_of_measure, 'unit_cost' => $cost, 'original_value' => $value, 'remaining_value' => $value,
            'status' => $meta['status'] ?? 'UNOPENED', 'received_at' => now(),
            'opened_at' => ($meta['status'] ?? '') === 'OPEN' ? now() : null,
            'notes' => $meta['notes'] ?? null, 'created_by' => $meta['user_id'] ?? auth()->id(),
        ]);
        $unit->update(['unit_code' => 'CU-'.str_pad((string) $unit->id, 8, '0', STR_PAD_LEFT)]);
        return $unit;
    }

    private function log(LibraryMaterial $material, Stock $stock, string $type, string $quantity, ?string $cost, ?string $value, array $meta): InventoryLog
    {
        return InventoryLog::create([
            'material_id' => $material->id, 'consumable_unit_id' => $meta['consumable_unit_id'] ?? null,
            'user_id' => $meta['user_id'] ?? auth()->id(), 'type' => $type,
            'quantity' => $quantity, 'balance_after' => $stock->getRawOriginal('quantity_on_hand'),
            'entered_quantity' => $quantity, 'entered_uom_id' => $material->base_uom_id, 'uom_conversion_factor' => '1',
            'receipt_unit_cost' => $cost, 'movement_value' => $value,
            'batch_number' => $meta['batch_number'] ?? app(InventoryService::class)->generateBatchNumber(),
            'project_id' => $meta['project_id'] ?? null, 'project_material_id' => $meta['project_material_id'] ?? null,
            'original_issue_log_id' => $meta['original_issue_log_id'] ?? null, 'reversal_of_log_id' => $meta['reversal_of_log_id'] ?? null,
            'return_kind' => $type === 'return' ? ($meta['return_kind'] ?? 'whole_item') : null,
            'supplier_id' => $meta['supplier_id'] ?? null, 'reference_no' => $meta['reference_no'] ?? null,
            'recipient_name' => $meta['recipient_name'] ?? null, 'notes' => $meta['notes'] ?? null,
            'usage_type' => 'consumable', 'logged_at' => $meta['logged_at'] ?? now(),
        ]);
    }

    private function history(ConsumableUnit $unit, string $type, string $quantity, string $before, string $after, ?string $value, array $meta, ?InventoryLog $log = null): void
    {
        $unit->movements()->create([
            'inventory_log_id' => $log?->id, 'project_id' => $meta['project_id'] ?? null,
            'actor_id' => $meta['user_id'] ?? auth()->id(), 'type' => $type, 'quantity' => $quantity,
            'balance_before' => $before, 'balance_after' => $after, 'value' => $value, 'unit_cost' => $unit->unit_cost,
            'reference' => $meta['reference_no'] ?? $log?->batch_number, 'reason' => $meta['notes'] ?? null, 'created_at' => now(),
        ]);
    }

    public function convert(LibraryMaterial $material, array $specs, string $notes, int $actor): array
    {
        return DB::transaction(function () use ($material, $specs, $notes, $actor) {
            $material = LibraryMaterial::lockForUpdate()->findOrFail($material->id);
            if ($material->isBoardTrackable() || $material->is_serialized || $material->is_batch_controlled) $this->reject('material_id', 'Boards, lots and serial items cannot use bulk-to-unit conversion.');
            if (ConsumableUnit::where('material_id', $material->id)->exists()) $this->reject('material_id', 'Existing unit history prevents repeating the opening conversion.');
            $stock = Stock::where('material_id', $material->id)->lockForUpdate()->firstOrFail();
            $quantities = array_map(fn ($u) => StoresDecimal::quantity($u['quantity'] ?? null), $specs);
            if (bccomp(StoresDecimal::sum($quantities), (string) $stock->getRawOriginal('quantity_on_hand'), 6) !== 0) $this->reject('controlled_units', 'Physically verified unit quantities must equal the existing bulk balance. Variance requires review; no write-off is performed.');
            // Existing receipt-supported MWA becomes the specific-identification opening cost; no new economic receipt.
            $readiness = app(StoresValuationReadinessService::class)->project()['data']->firstWhere('material_id', $material->id);
            $cost = ($readiness['classification'] ?? '') === StoresValuationReadinessService::VALUED ? StoresDecimal::cost($material->unit_cost) : null;
            $material->update(['tracking_mode' => 'consumable_unit', 'issue_disposition' => 'consumed']);
            foreach ($specs as $i => $spec) {
                $q = $quantities[$i];
                $value = $cost === null ? null : StoresDecimal::money(bcmul($q, $cost, 12));
                $meta = ['notes' => $notes, 'user_id' => $actor, 'status' => !empty($spec['opened']) ? 'OPEN' : 'UNOPENED', 'reference_no' => 'PHYSICAL-CONVERSION'];
                $unit = $this->createUnit($material, $q, $cost, $value, $meta);
                $this->history($unit, 'opening_conversion', $q, '0', $q, $value, $meta);
            }
            return $this->summary($material->fresh());
        });
    }

    public function count(ConsumableUnit $unit, string $physical, string $notes, int $actor): ConsumableUnitCount
    {
        return DB::transaction(function () use ($unit, $physical, $notes, $actor) {
            $unit = ConsumableUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $q = $physical === '0' || preg_match('/^0\.0+$/', $physical) ? '0.000000' : StoresDecimal::quantity($physical);
            $variance = bcsub($q, $unit->remaining_quantity, 6);
            return $unit->counts()->create(['actor_id' => $actor, 'system_quantity' => $unit->remaining_quantity, 'physical_quantity' => $q, 'variance' => $variance, 'status' => bccomp($variance, '0', 6) === 0 ? 'RECONCILED' : 'REQUIRES_REVIEW', 'notes' => $notes, 'created_at' => now()]);
        });
    }

    public function hold(ConsumableUnit $unit, bool $hold, string $reason, int $actor): ConsumableUnit
    {
        return DB::transaction(function () use ($unit, $hold, $reason, $actor) {
            LibraryMaterial::whereKey($unit->material_id)->lockForUpdate()->firstOrFail();
            $unit = ConsumableUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $unit->status = $hold ? 'HOLD_REVIEW' : (bccomp($unit->remaining_quantity, '0', 6) === 0 ? 'DEPLETED' : ($unit->opened_at ? 'OPEN' : 'UNOPENED'));
            $unit->save();
            $this->history($unit, $hold ? 'hold' : 'release_hold', '0', $unit->remaining_quantity, $unit->remaining_quantity, '0', ['user_id' => $actor, 'notes' => $reason]);
            return $unit;
        });
    }

    public function indicators(): array
    {
        $summaries = LibraryMaterial::where('tracking_mode', 'consumable_unit')->get()->map(fn ($m) => $this->summary($m));
        return ['open_units' => ConsumableUnit::where('status', 'OPEN')->count(), 'held_units' => ConsumableUnit::where('status', 'HOLD_REVIEW')->count(),
            'reconciliation_differences' => $summaries->where('reconciliation_status', 'DIFFERENCE')->count(),
            'count_variances' => ConsumableUnitCount::where('status', 'REQUIRES_REVIEW')->count(),
            'low_stock_materials' => $summaries->filter(fn ($s) => bccomp((string) ($s['material']->stock?->min_stock_level ?? '0'), '0', 6) > 0 && bccomp($s['available_quantity'], (string) $s['material']->stock->min_stock_level, 6) < 0)->count()];
    }

    private function reject(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
