<?php

namespace App\Listeners;

use App\Events\EnquiryCreated;
use App\Modules\Projects\Services\NotificationService;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;

class SendEnquiryNotification
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(EnquiryCreated $event): void
    {
        try {
            $enquiry = $event->enquiry;
            $notifiedUserIds = [];

            // 1. Notify Project Managers
            $projectManagerRole = Role::where('name', 'Project Manager')->first();
            if ($projectManagerRole) {
                foreach ($projectManagerRole->users as $pm) {
                    $this->notificationService->sendEnquiryCreatedNotification($enquiry, $pm);
                    $notifiedUserIds[] = $pm->id;
                }
            }

            // 2. Notify Super Admins (excluding those already notified)
            $adminRole = Role::where('name', 'Super Admin')->first();
            if ($adminRole) {
                foreach ($adminRole->users as $admin) {
                    if (!in_array($admin->id, $notifiedUserIds)) {
                        $this->notificationService->sendEnquiryCreatedNotification($enquiry, $admin);
                        $notifiedUserIds[] = $admin->id;
                    }
                }
            }

            // 3. Notify Creator (if not already notified)
            $creator = $enquiry->creator;
            if ($creator && !in_array($creator->id, $notifiedUserIds)) {
                $this->notificationService->sendEnquiryCreatedNotification($enquiry, $creator);
            }

            Log::info("Enquiry created notifications processed for enquiry #{$enquiry->id}");

        } catch (\Exception $e) {
            Log::error("Error in SendEnquiryNotification listener: " . $e->getMessage());
        }
    }
}