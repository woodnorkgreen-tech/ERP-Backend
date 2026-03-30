<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private LeaveRequest $leaveRequest
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $leaveType = $this->leaveRequest->leaveType;
        $approver = $this->leaveRequest->approver;
        $reviewNotes = $this->leaveRequest->review_notes;

        $mail = (new MailMessage)
            ->subject('Leave Request Rejected')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your leave request has been rejected.')
            ->line("Leave Type: {$leaveType->name}")
            ->line("Period: {$this->leaveRequest->start_date->format('M j, Y')} to {$this->leaveRequest->end_date->format('M j, Y')}")
            ->line("Days: {$this->leaveRequest->days_requested}")
            ->line("Rejected by: {$approver->name}");

        if ($reviewNotes) {
            $mail->line("Reason: {$reviewNotes}");
        }

        return $mail
            ->action('View My Leave', url('/my-leave'))
            ->line('If you have questions, please contact your manager or HR.');
    }
}
