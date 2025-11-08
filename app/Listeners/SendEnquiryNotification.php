<?php

namespace App\Listeners;

use App\Events\EnquiryCreated;
use Illuminate\Support\Facades\Notification;


class SendEnquiryNotification
{
    public function handle(EnquiryCreated $event): void
    {
        // Send notification to relevant users (e.g., department members)
        // Notification::send($users, new EnquiryCreatedNotification($event->enquiry));
    }
}
