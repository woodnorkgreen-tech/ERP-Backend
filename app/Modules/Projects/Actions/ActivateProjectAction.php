<?php

namespace App\Modules\Projects\Actions;

use App\Models\ProjectEnquiry;
use App\Models\Project;
use App\Models\User;
use App\Events\ProjectActivated;
use App\Constants\EnquiryConstants;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ActivateProjectAction
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Converts an enquiry into an active Project and handles notifications.
     * This happens either immediately after quote approval (for internal jobs)
     * or after the financial gate is released (for external jobs).
     */
    public function execute(ProjectEnquiry $enquiry, User $activatedByUser): void
    {
        // 1. Mark status as planning (it's now officially a project)
        $enquiry->update(['status' => EnquiryConstants::STATUS_PLANNING]);

        // 2. Automatically convert to a formal Project
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

        // 3. Notify system-wide that a new project is active.
        // Global Broadcast Signal — wrapped separately so a broadcast failure
        // can't also take down the persistent notification below.
        try {
            event(new ProjectActivated([
                'id' => $enquiry->id,
                'title' => $enquiry->title,
                'job_number' => $enquiry->job_number,
                'client_name' => $enquiry->client?->full_name ?? $enquiry->client?->name ?? 'Client TBC',
                'venue' => $enquiry->venue ?? 'Venue TBC',
                'deadline' => $enquiry->expected_delivery_date?->format('d M Y') ?? 'TBC',
                'project_officer' => $enquiry->projectOfficer?->name ?? 'Unassigned',
                'activated_by' => $activatedByUser->name,
            ]));
        } catch (\Exception $e) {
            Log::error('Failed to broadcast project activation event', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Routes through the central notification engine so channel routing
        // and per-user preferences (mail/push) are honoured — a direct
        // Notification::create() loop here would only ever write the
        // database row, silently skipping mail/push regardless of what the
        // recipient's preferences say.
        $this->notificationService->sendProjectActivated($enquiry, $activatedByUser);
    }
}
