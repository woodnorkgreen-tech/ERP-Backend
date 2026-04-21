<?php

namespace App\Modules\HR\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\HR\Models\Interview;

class InterviewScheduledNotification extends Notification
{
    public function __construct(public Interview $interview) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $interview = $this->interview;
        $candidate = $interview->candidate;
        $job       = $interview->jobPosting;

        return (new MailMessage)
            ->subject("Interview Scheduled — {$job->title}")
            ->greeting("Hello {$candidate->first_name},")
            ->line("Your interview for the **{$job->title}** position has been scheduled.")
            ->line("**Type:** {$interview->interview_type}")
            ->line("**Date & Time:** " . $interview->scheduled_at->format('D, d M Y \a\t H:i'))
            ->line("**Duration:** {$interview->duration_minutes} minutes")
            ->when($interview->location, fn($m) => $m->line("**Location:** {$interview->location}"))
            ->when($interview->meeting_link, fn($m) => $m->line("**Meeting Link:** {$interview->meeting_link}"))
            ->when($interview->notes, fn($m) => $m->line("**Notes:** {$interview->notes}"))
            ->line('Please ensure you are available at the scheduled time.')
            ->salutation('Best regards, HR Team');
    }
}
