<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

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
            // Ensure the row exists before locking; insert has no race risk.
            Stock::firstOrCreate(
                ['material_id' => $materialId],
                ['quantity_on_hand' => 0, 'warehouse_code' => $meta['warehouse_code'] ?? 'MAIN']
            );

            // Lock the row for the duration of this transaction so concurrent
            // check-outs cannot both read the same balance and race to negative.
            $stock = Stock::where('material_id', $materialId)->lockForUpdate()->firstOrFail();

            $stock->quantity_on_hand += $quantity;
            $stock->save();

            // 3. Generate or use provided batch number
            $batchNumber = $meta['batch_number'] ?? $this->generateBatchNumber();

            // 4. Log the movement with batch number
            return InventoryLog::create([
                'material_id' => $materialId,
                'user_id' => Auth::id(),
                'type' => $type,
                'batch_number' => $batchNumber,
                'quantity' => $quantity,
                'balance_after' => $stock->quantity_on_hand,
                'project_id' => $meta['project_id'] ?? null,
                'supplier_id' => $meta['supplier_id'] ?? null,
                'reference_no' => $meta['reference_no'] ?? null,
                'recipient_name' => $meta['recipient_name'] ?? $meta['requestor_name'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'usage_type' => $meta['usage_type'] ?? 'consumable',
                'logged_at' => $meta['logged_at'] ?? now(),
            ]);
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
