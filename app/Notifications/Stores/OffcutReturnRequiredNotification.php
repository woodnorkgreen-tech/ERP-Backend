<?php

namespace App\Notifications\Stores;

use App\Modules\ProcurementStores\Models\Board;
use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

class OffcutReturnRequiredNotification extends Notification
{
    public function __construct(private readonly Board $offcut) {}

    public function via($notifiable): array
    {
        return [OneSignalChannel::class];
    }

    public function toOneSignal($notifiable): OneSignalMessage
    {
        $code = $this->offcut->tracking_code;
        $job  = $this->offcut->assigned_job_ref ?? 'unknown';

        return OneSignalMessage::create()
            ->subject('🔁 Offcut Return Required')
            ->body("Offcut {$code} from Job #{$job} is in Quarantine. Please label and return to rack.");
    }
}
