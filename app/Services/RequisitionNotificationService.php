<?php

namespace App\Services;

use App\Modules\Notifications\Services\NotificationService;

class RequisitionNotificationService
{
    public static function notifyApprovers(
        string $requisitionNumber,
        string $requestedBy,
        string $urgency = 'normal'
    ): void {
        NotificationService::send(
            type: 'finance_requisition_pending',
            title: "Requisition {$requisitionNumber} needs approval",
            message: "{$requestedBy} submitted a {$urgency} requisition for review.",
            module: 'finance',
            urgency: $urgency === 'urgent' ? 'critical' : 'warning',
            data: [
                'requisition_number' => $requisitionNumber,
                'requested_by' => $requestedBy,
                'url' => '/procurement-stores/requisitions',
            ],
            role: ['Super Admin', 'Finance', 'Accounts'],
        );
    }
}
