<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

class InventoryService
{
    /**
     * Generate unique batch number for inventory movements
     * Format: ISS-YYYYMMDD-XXXX (e.g., ISS-20260129-0001)
     */
    public function generateBatchNumber(): string
    {
        // Wrapped in a transaction so lockForUpdate() is effective.
        // Nested calls (from within adjustStock's own transaction) are safe —
        // Laravel uses savepoints for nested transactions in MySQL.
        return DB::transaction(function () {
            $today  = now()->format('Ymd');
            $prefix = "ISS-{$today}-";

            $lastBatch = InventoryLog::where('batch_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderBy('batch_number', 'desc')
                ->first();

            $seq = $lastBatch
                ? ((int) substr($lastBatch->batch_number, -4)) + 1
                : 1;

            return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Adjust stock (Check-in, Check-out, Returns, etc.)
     * 
     * @param int $materialId The material being adjusted
     * @param float $quantity Positive for additions, negative for deductions
     * @param string $type Type of movement (check_in, check_out, return, etc.)
     * @param array $meta Additional metadata including optional batch_number
     * @return InventoryLog
     */
    public function adjustStock(int $materialId, float $quantity, string $type, array $meta = [])
    {
        return DB::transaction(function () use ($materialId, $quantity, $type, $meta) {
            $material = LibraryMaterial::with('uomConversions')->findOrFail($materialId);
            $enteredQuantity = $quantity;
            $conversionFactor = 1.0;
            $enteredUomId = isset($meta['entered_uom_id']) ? (int) $meta['entered_uom_id'] : null;
            if ($enteredUomId && $enteredUomId !== (int) $material->base_uom_id) {
                if ($material->is_serialized || $material->isBoardTrackable()) {
                    throw ValidationException::withMessages([
                        'entered_uom_id' => 'Individually tracked items must be moved in their stock unit so every physical item remains accounted for.',
                    ]);
                }

                $expectedAlternateUomId = $meta['expected_entered_uom_id'] ?? ($type === 'check_in'
                    ? $material->purchase_uom_id
                    : $material->issue_uom_id);
                if ((int) $expectedAlternateUomId !== $enteredUomId) {
                    throw ValidationException::withMessages([
                        'entered_uom_id' => 'Choose the stock unit or the buying/issuing unit configured in the Materials Library.',
                    ]);
                }

                $conversion = $material->uomConversions
                    ->first(fn ($row) => (int) $row->from_uom_id === $enteredUomId
                        && (int) $row->to_uom_id === (int) $material->base_uom_id);
                if (! $conversion || (float) $conversion->factor <= 0) {
                    throw ValidationException::withMessages([
                        'entered_uom_id' => 'This unit has no conversion to the material’s Stores unit. Complete the unit setup in the Materials Library first.',
                    ]);
                }
                $conversionFactor = (float) $conversion->factor;
                $quantity *= $conversionFactor;
                if ($type === 'check_in' && isset($meta['receipt_unit_cost']) && $meta['receipt_unit_cost'] !== null) {
                    $meta['receipt_unit_cost'] = (float) $meta['receipt_unit_cost'] / $conversionFactor;
                }
            }
            $usageType = $material->expectedUsageType();
            // A catalogue item may be reclassified after it was issued. Return
            // custody is governed by the immutable original movement, otherwise
            // changing master data can silently erase an existing obligation.
            if ($type === 'return' && !empty($meta['original_issue_log_id'])) {
                $usageType = InventoryLog::whereKey($meta['original_issue_log_id'])->value('usage_type')
                    ?: $usageType;
            }

            // Planning may reference an unfinished item — you can requisition
            // something you are still setting up, and receiving it is often how
            // it gets finished. Moving stock may not: an item Stores cannot
            // classify cannot be counted or costed.
            //
            // Returns and write-offs are deliberately exempt. Both are
            // corrections to movements that already happened, and trapping
            // stock inside a reclassified item would be worse than the
            // inconsistency it prevents.
            $status = $material->item_status ?? 'Active';
            if ($status !== 'Active' && ! in_array($type, ['return', 'defective'], true)) {
                $verb = $quantity < 0 ? 'issued' : 'received';
                throw new \DomainException(
                    "{$material->material_name} cannot be {$verb} while it is {$status}. "
                    .'Finish its setup in the Materials Library first.'
                );
            }


            // Ensure the row exists before locking; insert has no race risk.
            Stock::firstOrCreate(
                ['material_id' => $materialId],
                [
                    'quantity_on_hand' => 0,
                    'warehouse_code' => $meta['warehouse_code'] ?? 'MAIN',
                    'tracking_mode' => $material->isBoardTrackable()
                        ? Stock::TRACK_BY_AREA
                        : Stock::TRACK_BY_COUNT,
                ]
            );

            // Lock the row for the duration of this transaction so concurrent
            // check-outs cannot both read the same balance and race to negative.
            $stock = Stock::where('material_id', $materialId)->lockForUpdate()->firstOrFail();

            $expectedTrackingMode = $material->isBoardTrackable()
                ? Stock::TRACK_BY_AREA
                : Stock::TRACK_BY_COUNT;
            if ($stock->tracking_mode !== $expectedTrackingMode) {
                $stock->tracking_mode = $expectedTrackingMode;
            }

            $previousQuantity = (float) $stock->quantity_on_hand;
            $nextQuantity = $previousQuantity + $quantity;

            // The floor belongs inside the lock, not in the caller. Callers used
            // to test sufficiency with an unlocked read taken before this
            // transaction opened, so two concurrent issues of the last unit both
            // passed their own check and the ledger went negative. Reserved
            // stock is already spoken for, so it is the floor rather than zero.
            $floor = (float) $stock->quantity_reserved;
            if ($quantity < 0 && $nextQuantity < $floor - 0.00001) {
                $amount = fn (float $value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
                $reservedNote = $floor > 0 ? " ({$amount($previousQuantity)} on hand, {$amount($floor)} reserved)" : '';
                throw ValidationException::withMessages([
                    'quantity' => "{$material->material_name} has {$amount(max(0.0, $previousQuantity - $floor))} issuable{$reservedNote}. "
                        . "{$amount(abs($quantity))} cannot be issued.",
                ]);
            }

            $stock->quantity_on_hand = $nextQuantity;
            $stock->save();

            // Cost is receipt evidence, not catalogue input. Keep the material's
            // valuation cost derived from posted receipts using weighted average.
            if ($type === 'check_in' && array_key_exists('receipt_unit_cost', $meta) && $meta['receipt_unit_cost'] !== null) {
                $receivedQuantity = abs($quantity);
                $newQuantity = $previousQuantity + $receivedQuantity;
                if ($newQuantity > 0) {
                    $material->unit_cost = (
                        ($previousQuantity * (float) $material->unit_cost)
                        + ($receivedQuantity * (float) $meta['receipt_unit_cost'])
                    ) / $newQuantity;
                    $material->save();
                }
            }

            $controlled = app(ControlledInventoryService::class)->apply($material, $quantity, $type, $meta);

            // 3. Generate or use provided batch number
            $batchNumber = $meta['batch_number'] ?? $this->generateBatchNumber();

            // 4. Log the movement with batch number
            $log = InventoryLog::create([
                'material_id' => $materialId,
                'user_id' => Auth::id(),
                'type' => $type,
                'batch_number' => $batchNumber,
                'lot_number' => $meta['lot_number'] ?? null,
                'expiry_date' => $meta['expiry_date'] ?? null,
                'inventory_lot_id' => $controlled['inventory_lot_id'],
                'inventory_serial_item_id' => $controlled['inventory_serial_item_id'],
                'quantity' => $quantity,
                'entered_quantity' => $enteredUomId ? $enteredQuantity : null,
                'entered_uom_id' => $enteredUomId,
                'uom_conversion_factor' => $enteredUomId ? $conversionFactor : null,
                // Freeze the value used when stock leaves Stores. Finance posts
                // asynchronously, so reading the material's future average cost
                // inside the queue would rewrite the economics of this issue.
                'receipt_unit_cost' => $type === 'check_in'
                    ? ($meta['receipt_unit_cost'] ?? null)
                    : (in_array($type, ['check_out', 'issue', 'consumption', 'defective'], true)
                        ? (float) $material->unit_cost : null),
                'balance_after' => $stock->quantity_on_hand,
                'project_id' => $meta['project_id'] ?? null,
                'project_material_id' => $meta['project_material_id'] ?? null,
                'original_issue_log_id' => $meta['original_issue_log_id'] ?? null,
                'return_kind' => $type === 'return' ? ($meta['return_kind'] ?? 'whole_item') : null,
                'supplier_id' => $meta['supplier_id'] ?? null,
                'reference_no' => $meta['reference_no'] ?? null,
                'recipient_name' => $meta['recipient_name'] ?? $meta['requestor_name'] ?? null,
                'notes' => $meta['notes'] ?? null,
                // Usage behaviour is owned by the material master. Transaction
                // screens cannot silently reinterpret return obligations.
                'usage_type' => $usageType,
                'logged_at' => $meta['logged_at'] ?? now(),
            ]);

            foreach ($controlled['allocations'] as $allocation) {
                $log->allocations()->create($allocation);
            }

            if (in_array($type, ['check_out', 'issue', 'consumption'], true) && ($meta['project_id'] ?? $meta['reference_no'] ?? null)) {
                \App\Events\Stores\StockIssued::dispatch($log);
            }

            if ($type === 'return' && $log->original_issue_log_id) {
                \App\Events\Stores\StockReturned::dispatch($log);
            }

            return $log->load('allocations');
        });
    }

    /**
     * Reserve stock for a project
     */
    public function reserveStock(int $materialId, float $quantity)
    {
        $stock = Stock::where('material_id', $materialId)->first();
        if (!$stock) return false;

        $stock->quantity_reserved += $quantity;
        return $stock->save();
    }
}
