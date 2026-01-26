<?php

namespace App\Modules\Production\Observers;

use App\Models\ProjectEnquiry;
use Illuminate\Support\Facades\Log;

class ProjectEnquiryObserver
{
    /**
     * Handle the ProjectEnquiry "created" event.
     */
    public function created(ProjectEnquiry $enquiry): void
    {
        Log::info('ProjectEnquiry created', [
            'enquiry_id' => $enquiry->id,
            'title' => $enquiry->title,
            'client_id' => $enquiry->client_id,
            'status' => $enquiry->status,
            'priority' => $enquiry->priority,
            'created_by' => auth()->id()
        ]);
    }

    /**
     * Handle the ProjectEnquiry "updated" event.
     */
    public function updated(ProjectEnquiry $enquiry): void
    {
        $changes = $enquiry->getDirty();
        
        foreach ($changes as $field => $newValue) {
            // Skip timestamps
            if (in_array($field, ['updated_at', 'created_at'])) {
                continue;
            }

            $oldValue = $enquiry->getOriginal($field);
            
            // Log important changes
            if (in_array($field, ['status', 'priority', 'assigned_to', 'expected_delivery_date'])) {
                Log::info('ProjectEnquiry updated', [
                    'enquiry_id' => $enquiry->id,
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'updated_by' => auth()->id()
                ]);
            }
        }
    }

    /**
     * Handle the ProjectEnquiry "deleted" event.
     */
    public function deleted(ProjectEnquiry $enquiry): void
    {
        Log::info('ProjectEnquiry deleted', [
            'enquiry_id' => $enquiry->id,
            'title' => $enquiry->title,
            'deleted_by' => auth()->id()
        ]);
    }

    /**
     * Handle the ProjectEnquiry "restored" event.
     */
    private function generateWorkOrderNumber(ProjectEnquiry $enquiry = null): string
    {
        $year = date('Y');
        $month = date('m');
        $sequence = WorkOrder::whereYear('created_at', $year)->whereMonth('created_at', $month)->count() + 1;
        
        return sprintf('WO-%s%s-%03d', $year, $month, $sequence);
    }

    /**
     * Determine the appropriate work order status based on enquiry state
     */
    private function determineWorkOrderStatusFromEnquiry(ProjectEnquiry $enquiry): string
    {
        $status = $enquiry->status;

        if ($status === \App\Constants\EnquiryConstants::STATUS_COMPLETED) {
            return 'completed';
        }

        // If enquiry is approved or formalized into a project
        $activeStatuses = [
            \App\Constants\EnquiryConstants::STATUS_QUOTE_APPROVED,
            \App\Constants\EnquiryConstants::STATUS_PLANNING,
            \App\Constants\EnquiryConstants::STATUS_IN_PROGRESS,
        ];

        if (in_array($status, $activeStatuses) || $enquiry->job_number) {
            return 'in_progress';
        }

        return 'pending';
    }

    /**
     * Map enquiry priority to work order priority
     */
    private function mapPriority(string $enquiryPriority): string
    {
        $map = [
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'urgent' => 'urgent',
        ];

        return $map[strtolower($enquiryPriority)] ?? 'medium';
    }
}
