<?php

namespace App\Observers;

use App\Models\TaskMaterialsData;
use App\Modules\Projects\Actions\AutoSyncTaskStateAction;
use App\Modules\Projects\Models\EnquiryTask;

class TaskMaterialsDataObserver
{
    /**
     * Handle the TaskMaterialsData "saved" event.
     */
    public function saved(TaskMaterialsData $data): void
    {
        $task = EnquiryTask::find($data->enquiry_task_id);
        if ($task) {
            app(AutoSyncTaskStateAction::class)->execute($task);
        }
    }
}
