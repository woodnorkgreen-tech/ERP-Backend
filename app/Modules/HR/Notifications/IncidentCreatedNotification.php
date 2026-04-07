<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

class IncidentCreatedNotification extends Notification
{
    use Queueable;

    protected $incident;

    public function __construct(Incident $incident)
    {
        $this->incident = $incident;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Incident Reported: ' . $this->incident->title)
            ->line('A new incident has been reported.')
            ->line('**Title:** ' . $this->incident->title)
            ->line('**Severity:** ' . $this->incident->severity)
            ->line('**Location:** ' . $this->incident->location)
            ->line('**Reported by:** ' . ($this->incident->reporter_name ?? 'Guest'))
            ->action('View Incident', url('/hr/incidents/' . $this->incident->id))
            ->line('Please review and take action if necessary.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New Incident Reported',
            'message' => 'Incident: ' . $this->incident->title . ' - Severity: ' . $this->incident->severity,
            'url' => '/hr/incidents/' . $this->incident->id,
            'incident_id' => $this->incident->id,
            'severity' => $this->incident->severity,
        ];
    }
}

