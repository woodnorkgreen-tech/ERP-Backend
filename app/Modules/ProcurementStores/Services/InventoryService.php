<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InventoryService
{
    /**
     * Increase stock (Check-in, Returns)
     */
    public function adjustStock(int $materialId, float $quantity, string $type, array $meta = [])
    {
        return DB::transaction(function () use ($materialId, $quantity, $type, $meta) {
            // 1. Get or Create Stock record
            $stock = Stock::firstOrCreate(
                ['material_id' => $materialId],
                ['quantity_on_hand' => 0, 'warehouse_code' => $meta['warehouse_code'] ?? 'MAIN']
            );

            // 2. Calculate new balance
            // For check_in, quantity is positive. For adjustments, could be negative.
            $stock->quantity_on_hand += $quantity;
            $stock->save();

            // 3. Log the movement
            return InventoryLog::create([
                'material_id' => $materialId,
                'user_id' => Auth::id() ?? 1, // Fallback to system user
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $stock->quantity_on_hand,
                'project_id' => $meta['project_id'] ?? null,
                'supplier_id' => $meta['supplier_id'] ?? null,
                'reference_no' => $meta['reference_no'] ?? null,
                'notes' => $meta['notes'] ?? null,
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
