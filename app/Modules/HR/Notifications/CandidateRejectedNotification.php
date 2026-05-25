<?php

namespace App\Modules\HR\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CandidateRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $jobTitle
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Application Status Update — {$this->jobTitle}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("We regret to inform you that your application for the {$this->jobTitle} position was not successful.")
            ->line('Thank you for your interest in joining our team.')
            ->line('We appreciate the time you invested in applying and wish you the best in your job search.')
            ->salutation('Best regards, HR Team');
    }
}
