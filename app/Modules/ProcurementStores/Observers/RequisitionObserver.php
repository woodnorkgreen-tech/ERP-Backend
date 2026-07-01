<?php

namespace App\Modules\ProcurementStores\Observers;

use App\Modules\Notifications\Services\NotificationService;
use App\Modules\ProcurementStores\Models\Requisition;

class RequisitionObserver
{
    public function updated(Requisition $requisition): void
    {
        if (!$requisition->wasChanged('status')) {
            return;
        }

        $type = $requisition->status === 'pending_approval'
            ? 'procurement_requisition_submitted'
            : 'procurement_requisition_status_changed';

        NotificationService::send(
            type: $type,
            title: "Requisition {$requisition->requisition_number} is {$requisition->status}",
            message: "Status changed from {$requisition->getOriginal('status')} to {$requisition->status}.",
            module: 'procurement-stores',
            urgency: $requisition->urgency === 'urgent' ? 'critical' : 'info',
            data: [
                'requisition_id' => $requisition->id,
                'status' => $requisition->status,
                'url' => "/procurement-stores/requisitions/{$requisition->id}",
            ],
            users: array_filter([$requisition->user_id]),
            role: $requisition->status === 'pending_approval' ? ['Super Admin', 'Accounts', 'Procurement'] : [],
        );
    }
}
