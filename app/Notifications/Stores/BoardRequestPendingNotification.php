<?php

namespace App\Notifications\Stores;

use App\Modules\ProcurementStores\Models\BoardRequest;
use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class BoardRequestPendingNotification extends Notification
{
    public function __construct(private readonly BoardRequest $boardRequest) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        $material = $this->boardRequest->material?->material_name ?? 'Material';
        $qty      = $this->boardRequest->qty_requested;
        $jobRef   = $this->boardRequest->job_ref;

        return OneSignalMessage::create()
            ->subject('📦 Boards Needed — Action Required')
            ->body("{$qty}x {$material} board(s) requested for Job #{$jobRef}. Please fulfill using FIFO.");
    }
}
