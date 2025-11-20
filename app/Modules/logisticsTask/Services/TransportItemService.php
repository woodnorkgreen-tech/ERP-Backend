<?php

namespace App\Modules\logisticsTask\Services;

use App\Modules\logisticsTask\Models\TransportItem;
use App\Modules\logisticsTask\Models\LogisticsTask;
use Illuminate\Support\Facades\DB;

class TransportItemService
{
    /**
     * Get transport items for a logistics task
     */
    public function getTransportItems(int $logisticsTaskId): array
    {
        return TransportItem::where('logistics_task_id', $logisticsTaskId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'category' => $item->category,
                    'source' => $item->source,
                    'weight' => $item->weight,
                    'special_handling' => $item->special_handling,
                    'total_weight' => $item->total_weight,
                ];
            })
            ->toArray();
    }

    /**
     * Create a new transport item
     */
    public function createTransportItem(int $logisticsTaskId, array $data): TransportItem
    {
        // Validate logistics task exists
        LogisticsTask::findOrFail($logisticsTaskId);

        return TransportItem::create([
            'logistics_task_id' => $logisticsTaskId,
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Update a transport item
     */
    public function updateTransportItem(int $itemId, array $data): TransportItem
    {
        $item = TransportItem::findOrFail($itemId);

        $item->update([
            ...$data,
            'updated_at' => now(),
        ]);

        return $item->fresh();
    }

    /**
     * Delete a transport item
     */
    public function deleteTransportItem(int $itemId): bool
    {
        $item = TransportItem::findOrFail($itemId);
        return $item->delete();
    }

    /**
     * Bulk create transport items
     */
    public function bulkCreateTransportItems(int $logisticsTaskId, array $items): array
    {
        return DB::transaction(function () use ($logisticsTaskId, $items) {
            $createdItems = [];

            foreach ($items as $itemData) {
                $item = $this->createTransportItem($logisticsTaskId, $itemData);
                $createdItems[] = $item;
            }

            return $createdItems;
        });
    }

    /**
     * Import items from production tasks
     */
    public function importFromProductionTask(int $logisticsTaskId, int $productionTaskId): array
    {
        // This would integrate with production/budget tasks
        // For now, return empty array
        return [];
    }

    /**
     * Get items by category
     */
    public function getItemsByCategory(int $logisticsTaskId, string $category): array
    {
        return TransportItem::where('logistics_task_id', $logisticsTaskId)
            ->where('category', $category)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Calculate total weight for logistics task
     */
    public function calculateTotalWeight(int $logisticsTaskId): float
    {
        return TransportItem::where('logistics_task_id', $logisticsTaskId)
            ->get()
            ->sum(function ($item) {
                return $item->total_weight ?? 0;
            });
    }

    /**
     * Get items requiring special handling
     */
    public function getItemsRequiringSpecialHandling(int $logisticsTaskId): array
    {
        return TransportItem::where('logistics_task_id', $logisticsTaskId)
            ->whereNotNull('special_handling')
            ->where('special_handling', '!=', '')
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Validate transport item data
     */
    public function validateTransportItemData(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Item name is required';
        }

        if (!isset($data['quantity']) || $data['quantity'] < 1) {
            $errors[] = 'Quantity must be at least 1';
        }

        if (empty($data['unit'])) {
            $errors[] = 'Unit is required';
        }

        if (!in_array($data['category'] ?? null, ['production', 'custom'])) {
            $errors[] = 'Category must be either production or custom';
        }

        return $errors;
    }
}
