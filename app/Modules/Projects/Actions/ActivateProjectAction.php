<?php

namespace App\Modules\Projects\Actions;

use App\Models\ProjectEnquiry;
use App\Models\Project;
use App\Models\User;
use App\Events\ProjectActivated;
use App\Models\Notification;
use App\Constants\EnquiryConstants;
use Illuminate\Support\Facades\Log;

class ActivateProjectAction
{
    /**
     * Converts an enquiry into an active Project and handles notifications.
     * This happens either immediately after quote approval (for internal jobs)
     * or after the financial gate is released (for external jobs).
     */
    public function execute(ProjectEnquiry $enquiry, User $activatedByUser): void
    {
        // 1. Mark status as planning (it's now officially a project)
        $enquiry->update(['status' => EnquiryConstants::STATUS_PLANNING]);

        // 2. Automatically convert to a formal Project/Mission
        $project = Project::firstOrCreate(
            ['enquiry_id' => $enquiry->id],
            [
                'project_id' => $enquiry->generateProjectId(),
                'start_date' => $enquiry->start_date ?? now(),
                'end_date' => $enquiry->end_date ?? $enquiry->expected_delivery_date,
                'budget' => $enquiry->budget ?? $enquiry->estimated_budget,
                'status' => 'planning',
                'assigned_users' => $enquiry->assigned_users
            ]
        );

        $enquiry->refresh();
        $enquiry->load(['client', 'projectOfficer']);

        // 3. Notify system-wide that a new project is active 
        // (Wrapped in try-catch to prevent 500 on non-critical messaging)
        try {
            $notifPayload = [
                'id' => $enquiry->id,
                'title' => $enquiry->title,
                'job_number' => $enquiry->job_number,
                'client_name' => $enquiry->client?->full_name ?? $enquiry->client?->name ?? 'Client TBC',
                'venue' => $enquiry->venue ?? 'Venue TBC',
                'deadline' => $enquiry->expected_delivery_date?->format('d M Y') ?? 'TBC',
                'project_officer' => $enquiry->projectOfficer?->name ?? 'Unassigned',
                'activated_by' => $activatedByUser->name
            ];

            // Global Broadcast Signal
            event(new ProjectActivated($notifPayload));

            // Record persistent notifications for all users
            $allUserIds = User::pluck('id');
            $notifData = [
                'type' => 'project_activated',
                'title' => 'New Mission Active',
                'message' => "Project {$enquiry->title} (#{$enquiry->job_number}) is officially live!",
                'data' => $notifPayload,
                'notifiable_type' => ProjectEnquiry::class,
                'notifiable_id' => $enquiry->id,
            ];

            foreach ($allUserIds as $uId) {
                Notification::create(array_merge($notifData, ['user_id' => $uId]));
            }
        } catch (\Exception $e) {
            Log::error('Failed to dispatch project activation notifications', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
