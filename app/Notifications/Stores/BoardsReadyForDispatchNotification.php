<?php

namespace App\Notifications\Stores;

use App\Modules\ProcurementStores\Models\BoardRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class BoardsReadyForDispatchNotification extends Notification
{
    public function __construct(
        private readonly BoardRequest $boardRequest,
        private readonly int          $boardCount,
    ) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        $jobRef   = $this->boardRequest->job_ref;
        $material = $this->boardRequest->material?->material_name ?? 'boards';

        return OneSignalMessage::create()
            ->subject('🚚 Dispatch Required')
            ->body("{$this->boardCount}x {$material} allocated for Job #{$jobRef}. Deliver to station now.");
    }
}
