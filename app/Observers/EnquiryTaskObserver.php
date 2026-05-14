<?php

namespace App\Observers;

use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Actions\SyncEnquiryStatusAction;
use Illuminate\Support\Facades\Log;

class EnquiryTaskObserver
{
    /**
     * Handle the EnquiryTask "saved" event.
     */
    public function saved(EnquiryTask $task): void
    {
        // 1. Sync Legacy Columns with Pivot Table
        // If assigned_user_id is set manually (legacy), sync it to the pivot table
        if ($task->wasChanged('assigned_user_id') && $task->assigned_user_id) {
            $task->assignedUsers()->syncWithoutDetaching([$task->assigned_user_id]);
        }

        // 2. Automate Parent Enquiry Status Sync
        // When a task status changes, we should always re-evaluate the parent enquiry status
        if ($task->wasChanged('status')) {
            app(SyncEnquiryStatusAction::class)->execute($task->project_enquiry_id);
        }
    }

    /**
     * Handle the EnquiryTask "deleted" event.
     */
    public function deleted(EnquiryTask $task): void
    {
        app(SyncEnquiryStatusAction::class)->execute($task->project_enquiry_id);
    }
}
