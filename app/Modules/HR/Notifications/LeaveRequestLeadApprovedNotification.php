<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestLeadApprovedNotification extends Notification
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
        $leadApprover = $this->leaveRequest->leadApprover;

        return (new MailMessage)
            ->subject('Leave Request Awaiting Your Approval')
            ->greeting("Hello {$notifiable->name},")
            ->line('A leave request has been approved by the department lead and requires your final HR approval.')
            ->line("Employee: {$employee->name}")
            ->line("Leave Type: {$leaveType->name}")
            ->line("Period: {$this->leaveRequest->start_date->format('M j, Y')} to {$this->leaveRequest->end_date->format('M j, Y')}")
            ->line("Days: {$this->leaveRequest->days_requested}")
            ->line("Lead Approved by: {$leadApprover->name}")
            ->action('Review Leave Request', url('/hr/leave'))
            ->line('Please review and give your final decision.');
    }
}
