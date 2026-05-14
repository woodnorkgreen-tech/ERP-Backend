<?php

namespace App\Modules\Projects\Actions;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use App\Constants\EnquiryConstants;
use Illuminate\Support\Facades\Log;

class SyncEnquiryStatusAction
{
    /**
     * Synchronize the Enquiry status based on its tasks' completion status.
     *
     * @param int $enquiryId
     * @return ProjectEnquiry
     */
    public function execute(int $enquiryId): ProjectEnquiry
    {
        $enquiry = ProjectEnquiry::findOrFail($enquiryId);
        
        // Skip syncing for closed, approved, or active project statuses
        if (in_array($enquiry->status, [
            'completed', 
            'cancelled', 
            'quote_approved', 
            'awaiting_deposit', 
            'planning', 
            'in_progress'
        ])) {
            return $enquiry;
        }

        // Get all completed tasks for this enquiry
        $completedTaskTypes = EnquiryTask::where('project_enquiry_id', $enquiryId)
            ->whereIn('status', ['completed', 'skipped'])
            ->pluck('type')
            ->toArray();

        // Use centralized progression order from constants
        $statusProgression = EnquiryConstants::ENQUIRY_STATUS_REQUISITES;

        $newStatus = EnquiryConstants::STATUS_ENQUIRY_LOGGED;

        // Find the highest status where all required tasks are completed
        foreach ($statusProgression as $status => $requiredTasks) {
            if (count(array_intersect($requiredTasks, $completedTaskTypes)) === count($requiredTasks)) {
                $newStatus = $status;
                break;
            }
        }

        // Update if different
        if ($enquiry->status !== $newStatus) {
            $oldStatus = $enquiry->status;
            $enquiry->status = $newStatus;
            $enquiry->save();

            Log::info("SyncEnquiryStatusAction: Enquiry {$enquiryId} status updated from '{$oldStatus}' to '{$newStatus}'");
        }

        return $enquiry;
    }
}
