<?php

namespace App\Listeners;

use App\Events\QuoteApproved;
use App\Constants\EnquiryConstants;
use App\Modules\Projects\Actions\ActivateProjectAction;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class EvaluateFinancialRequirements
{
    protected $activateProjectAction;
    protected $notificationService;

    public function __construct(
        ActivateProjectAction $activateProjectAction, 
        NotificationService $notificationService
    ) {
        $this->activateProjectAction = $activateProjectAction;
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        $enquiry = $event->enquiry;
        $user = $event->approvedBy;

        // 1. Send Quote Approval Notification (HIGH PRIORITY)
        try {
            $this->notificationService->sendQuoteApproved($enquiry, $user, $enquiry->job_number);
        } catch (\Exception $e) {
            Log::error("Failed to send quote approval notification in listener: " . $e->getMessage());
        }

        // 2. Evaluate Financial Gate based on job type
        $isExternal = !in_array($enquiry->workflow_preset_type, ['internal_job', 'sponsorship']);

        if ($isExternal) {
            // EXTERNAL: Halt progress, put in Awaiting Deposit queue
            $enquiry->update(['status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT]);
        } else {
            // INTERNAL: Skip financial gate entirely, activate project
            $this->activateProjectAction->execute($enquiry, $user);
        }
    }
}
