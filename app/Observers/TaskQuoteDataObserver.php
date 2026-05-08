<?php

namespace App\Observers;

use App\Models\TaskQuoteData;
use App\Modules\Projects\Actions\AutoSyncTaskStateAction;
use App\Modules\Projects\Models\EnquiryTask;

class TaskQuoteDataObserver
{
    /**
     * Handle the TaskQuoteData "saved" event.
     */
    public function saved(TaskQuoteData $data): void
    {
        $task = EnquiryTask::find($data->enquiry_task_id);
        if ($task) {
            app(AutoSyncTaskStateAction::class)->execute($task);
        }
    }
}
