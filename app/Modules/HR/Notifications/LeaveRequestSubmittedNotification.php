<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmittedNotification extends Notification
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
        $employee = $this->leaveRequest->employee;

        return (new MailMessage)
            ->subject('New Leave Request Submitted')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employee->name} has submitted a leave request.")
            ->line("Leave Type: {$leaveType->name}")
            ->line("Period: {$this->leaveRequest->start_date->format('M j, Y')} to {$this->leaveRequest->end_date->format('M j, Y')}")
            ->line("Days: {$this->leaveRequest->days_requested}")
            ->line("Reason: {$this->leaveRequest->reason}")
            ->action('Review Request', url('/hr/leave-management'))
            ->line('Please review this request at your earliest convenience.');
    }
}
