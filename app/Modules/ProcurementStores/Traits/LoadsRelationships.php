<?php

namespace App\Modules\ProcurementStores\Traits;

trait LoadsRelationships
{
    /**
     * Get relationship loading rules for common resources.
     */
    protected function getDefaultRelationships(string $resourceType): array
    {
        $relationships = [
            'bill' => ['purchaseOrder', 'supplier', 'createdBy', 'payments.paymentMethod', 'payments.createdBy'],
            'requisition' => ['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy'],
            'purchaseOrder' => ['items.material', 'supplier', 'createdBy', 'approvedBy'],
            'board' => ['material', 'movements'],
            'goodsReceiptNote' => ['purchaseOrder', 'supplier', 'items', 'createdBy'],
        ];

        return $relationships[$resourceType] ?? [];
    }

    /**
     * Load relationships onto model or collection.
     */
    protected function loadRelationships($model, string $resourceType)
    {
        $relationships = $this->getDefaultRelationships($resourceType);

        if (empty($relationships)) {
            return $model;
        }

        if (is_array($model)) {
            return collect($model)->each(fn($item) => $item->load($relationships));
        }

        return $model->load($relationships);
    }

    /**
     * Eager load relationships in query.
     */
    protected function withDefaultRelationships($query, string $resourceType)
    {
        $relationships = $this->getDefaultRelationships($resourceType);

        if (!empty($relationships)) {
            return $query->with($relationships);
        }

        return $query;
    }
}
