<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function build(): self
    {
        return $this
            ->subject($this->payload['title'] ?? 'ERP Notification')
            ->view('notifications::emails.notification')
            ->with([
                'payload' => $this->payload,
                'accent' => $this->accentColor($this->payload['urgency'] ?? 'info'),
            ]);
    }

    protected function accentColor(string $urgency): string
    {
        return match ($urgency) {
            'success' => '#10B981',
            'warning' => '#F59E0B',
            'critical' => '#EF4444',
            default => '#3B82F6',
        };
    }
}
