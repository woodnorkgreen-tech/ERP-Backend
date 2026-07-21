<?php

namespace App\Modules\UniversalTask\Listeners;

use App\Modules\UniversalTask\Events\TaskIssueLogged;
use App\Modules\UniversalTask\Services\TaskNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskIssueNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(protected TaskNotificationService $notificationService)
    {
    }

    /**
     * Handle the event.
     *
     * Routes through TaskNotificationService::notifyTaskIssue() — the
     * central notification engine — instead of writing App\Models\Notification
     * rows directly, so recipients' mail/push preferences are honoured
     * instead of silently getting only the in-app row.
     */
    public function handle(TaskIssueLogged $event): void
    {
        $issue = $event->issue;

        // Only send notifications for critical or high severity issues
        if (!$issue->isCriticalOrHigh()) {
            return;
        }

        $task = $issue->task()->first();

        if (!$task) {
            Log::warning("Task not found for issue ID: {$issue->id}");
            return;
        }

        $this->notificationService->notifyTaskIssue($task, 'created', [
            'issue_id' => $issue->id,
            'title' => $issue->title,
            'severity' => $issue->severity,
        ]);
    }
}
