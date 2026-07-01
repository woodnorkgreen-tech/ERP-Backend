<?php

namespace App\Modules\ProcurementStores\Observers;

use App\Modules\Notifications\Services\NotificationService;
use App\Modules\ProcurementStores\Models\PurchaseOrder;

class PurchaseOrderObserver
{
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        if (!$purchaseOrder->wasChanged('status')) {
            return;
        }

        NotificationService::send(
            type: 'procurement_purchase_order_status_changed',
            title: "Purchase order {$purchaseOrder->po_number} is {$purchaseOrder->status}",
            message: "Status changed from {$purchaseOrder->getOriginal('status')} to {$purchaseOrder->status}.",
            module: 'procurement-stores',
            data: [
                'purchase_order_id' => $purchaseOrder->id,
                'status' => $purchaseOrder->status,
                'url' => "/procurement-stores/purchase-orders/{$purchaseOrder->id}",
            ],
            users: array_filter([$purchaseOrder->user_id]),
            role: ['Super Admin', 'Procurement', 'Stores'],
        );
    }
}
