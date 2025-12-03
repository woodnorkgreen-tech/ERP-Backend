<?php

namespace App\Modules\UniversalTask\Observers;

use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Events\TaskIssueLogged;

class TaskIssueObserver
{
    /**
     * Handle the TaskIssue "created" event.
     */
    public function created(TaskIssue $taskIssue): void
    {
        // Dispatch the TaskIssueLogged event
        event(new TaskIssueLogged($taskIssue));
    }

    /**
     * Handle the TaskIssue "updated" event.
     */
    public function updated(TaskIssue $taskIssue): void
    {
        //
    }

    /**
     * Handle the TaskIssue "deleted" event.
     */
    public function deleted(TaskIssue $taskIssue): void
    {
        //
    }

    /**
     * Handle the TaskIssue "restored" event.
     */
    public function restored(TaskIssue $taskIssue): void
    {
        //
    }

    /**
     * Handle the TaskIssue "force deleted" event.
     */
    public function forceDeleted(TaskIssue $taskIssue): void
    {
        //
    }
}
