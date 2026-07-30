<?php

namespace App\Modules\UniversalTask\Observers;

use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Events\TaskIssueLogged;
use Illuminate\Support\Facades\Log;

class TaskIssueObserver
{
    /**
     * Handle the TaskIssue "created" event.
     */
    public function created(TaskIssue $taskIssue): void
    {
        try {
            event(new TaskIssueLogged($taskIssue));
        } catch (\Throwable $e) {
            Log::warning('Task issue notification dispatch failed', [
                'issue_id' => $taskIssue->id,
                'task_id' => $taskIssue->task_id,
                'error' => $e->getMessage(),
            ]);
        }
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
