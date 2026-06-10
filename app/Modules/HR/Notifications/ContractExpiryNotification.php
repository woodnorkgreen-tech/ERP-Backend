<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractExpiryNotification extends Notification
{
    use Queueable;

    /** @param Employee[] $employees */
    public function __construct(private array $employees) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Contract Expiry Alert — Action Required')
            ->greeting("Hello {$notifiable->name},")
            ->line('The following employee contracts are expiring within the next 30 days. Please review and take action.')
            ->line('');

        foreach ($this->employees as $employee) {
            $days = now()->diffInDays($employee->contract_end_date, false);
            $label = $days === 0 ? 'TODAY' : "in {$days} day(s)";
            $mail->line("• **{$employee->name}** ({$employee->position} · {$employee->department?->name}) — expires {$label} ({$employee->contract_end_date->format('d M Y')})");
        }

        return $mail
            ->line('')
            ->action('Open Employee Registry', url('/hr/employees'))
            ->line('No action required if the contract has already been renewed or the employee has been offboarded.');
    }
}
