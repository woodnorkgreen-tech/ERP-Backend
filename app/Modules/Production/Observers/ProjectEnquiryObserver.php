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
    public function restored(ProjectEnquiry $enquiry): void
    {
        Log::info('ProjectEnquiry restored', [
            'enquiry_id' => $enquiry->id,
            'restored_by' => auth()->id()
        ]);
    }
}
