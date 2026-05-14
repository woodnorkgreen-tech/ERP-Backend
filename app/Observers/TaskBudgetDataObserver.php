<?php

namespace App\Observers;

use App\Models\TaskBudgetData;
use App\Modules\Projects\Actions\AutoSyncTaskStateAction;
use App\Modules\Projects\Models\EnquiryTask;

class TaskBudgetDataObserver
{
    /**
     * Handle the TaskBudgetData "saved" event.
     */
    public function saved(TaskBudgetData $data): void
    {
        $task = EnquiryTask::find($data->enquiry_task_id);
        if ($task) {
            app(AutoSyncTaskStateAction::class)->execute($task);
        }
    }
}
