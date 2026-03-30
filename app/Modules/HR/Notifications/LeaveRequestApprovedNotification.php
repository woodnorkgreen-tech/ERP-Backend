<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestApprovedNotification extends Notification
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

        return (new MailMessage)
            ->subject('Leave Request Approved')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your leave request has been approved!')
            ->line("Leave Type: {$leaveType->name}")
            ->line("Period: {$this->leaveRequest->start_date->format('M j, Y')} to {$this->leaveRequest->end_date->format('M j, Y')}")
            ->line("Days: {$this->leaveRequest->days_requested}")
            ->line("Approved by: {$approver->name}")
            ->action('View My Leave', url('/my-leave'))
            ->line('Enjoy your time off!');
    }
}
