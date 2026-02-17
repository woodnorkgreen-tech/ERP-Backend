<?php

namespace App\Modules\ProcurementStores\Notifications;

use Illuminate\Notifications\Notification;

class RequisitionRejected extends Notification
{
    public function __construct(public $requisition) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'url'           => '/procurement/requisition/' . $this->requisition->id,
            'notification'  => "Your requisition {$this->requisition->requisition_number} was rejected. Reason: {$this->requisition->rejection_reason}",
            'formated_date' => now()->format('D dS M Y h:ia'),
        ];
    }
}