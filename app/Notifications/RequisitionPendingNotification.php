<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class RequisitionPendingNotification extends Notification
{
    public function __construct(
        private string $requisitionNumber,
        private string $requestedBy,
        private string $urgency = 'normal'
    ) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        $title = $this->urgency === 'urgent'
            ? '🚨 URGENT: Requisition Needs Approval'
            : '📋 Requisition Needs Approval';

        return OneSignalMessage::create()
            ->subject($title)
            ->body("Requisition {$this->requisitionNumber} from {$this->requestedBy} is awaiting your approval.");
    }
}