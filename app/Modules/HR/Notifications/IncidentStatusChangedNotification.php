<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

class IncidentStatusChangedNotification extends Notification
{
    use Queueable;

    protected $incident;
    protected $newStatus;

    public function __construct(Incident $incident, string $newStatus)
    {
        $this->incident = $incident;
        $this->newStatus = $newStatus;
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
            ->subject('Incident Status Updated: ' . $this->incident->title)
            ->line('Your reported incident status has been updated.')
            ->line('**Title:** ' . $this->incident->title)
            ->line('**New Status:** ' . $this->newStatus)
            ->action('View Incident', url('/hr/incidents/' . $this->incident->id));
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Incident Status Updated',
            'message' => 'Your incident "' . $this->incident->title . '" status is now: ' . $this->newStatus,
            'url' => '/hr/incidents/' . $this->incident->id,
            'incident_id' => $this->incident->id,
            'new_status' => $this->newStatus,
        ];
    }
}

