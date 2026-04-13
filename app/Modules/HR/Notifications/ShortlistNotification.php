<?php

namespace App\Modules\HR\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ShortlistNotification extends Notification
{
    public function __construct(
        public string $jobTitle,
        public string $subject,
        public string $messageBody
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting("Hello {$notifiable->first_name},")
            ->line($this->messageBody)
            ->salutation('Best regards, HR Team');
    }
}
