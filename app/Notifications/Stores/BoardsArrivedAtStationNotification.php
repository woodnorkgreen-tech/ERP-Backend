<?php

namespace App\Notifications\Stores;

use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class BoardsArrivedAtStationNotification extends Notification
{
    public function __construct(
        private readonly string $jobRef,
        private readonly int    $boardCount,
        private readonly string $deliveredBy,
    ) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        return OneSignalMessage::create()
            ->subject('✅ Materials Delivered — Ready to Cut')
            ->body("{$this->boardCount} board(s) for Job #{$this->jobRef} delivered by {$this->deliveredBy}. You can now start WIP.");
    }
}
