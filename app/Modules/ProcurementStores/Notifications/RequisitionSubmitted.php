<?php

namespace App\Modules\ProcurementStores\Notifications;

use Illuminate\Notifications\Notification;

class RequisitionSubmitted extends Notification
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
            'notification'  => "Requisition {$this->requisition->requisition_number} needs your approval.",
            'formated_date' => now()->format('D dS M Y h:ia'),
        ];
    }
}