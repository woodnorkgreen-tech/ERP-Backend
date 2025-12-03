<?php

namespace App\Modules\UniversalTask\Listeners;

use App\Models\Notification;
use App\Modules\UniversalTask\Events\TaskIssueLogged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTaskIssueNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TaskIssueLogged $event): void
    {
        $issue = $event->issue;

        // Only send notifications for critical or high severity issues
        if (!$issue->isCriticalOrHigh()) {
            return;
        }

        // Load the task with its relationships
        $task = $issue->task()->with(['assignedUser', 'department'])->first();

        if (!$task) {
            Log::warning("Task not found for issue ID: {$issue->id}");
            return;
        }

        $recipients = $this->getNotificationRecipients($task);

        // Create notifications for all recipients
        foreach ($recipients as $userId) {
            $this->createNotification($userId, $issue, $task);
        }
    }

    /**
     * Get the list of user IDs who should receive notifications.
     * Includes task assignees and their supervisors.
     */
    protected function getNotificationRecipients($task): array
    {
        $recipients = [];

        // Add the assigned user
        if ($task->assigned_user_id) {
            $recipients[] = $task->assigned_user_id;
        }

        // Add all users with assignments on this task
        $assignedUsers = $task->assignments()->pluck('user_id')->toArray();
        $recipients = array_merge($recipients, $assignedUsers);

        // Add department manager (supervisor) if available
        if ($task->department && $task->department->manager_id) {
            $department = $task->department()->with('manager.user')->first();
            if ($department && $department->manager && $department->manager->user) {
                $recipients[] = $department->manager->user->id;
            }
        }

        // Remove duplicates and return
        return array_unique($recipients);
    }

    /**
     * Create a notification record for a user.
     */
    protected function createNotification(int $userId, $issue, $task): void
    {
        Notification::create([
            'user_id' => $userId,
            'type' => 'task_issue_logged',
            'title' => "Critical Issue Logged: {$issue->title}",
            'message' => "A {$issue->severity} severity issue has been logged for task '{$task->title}': {$issue->description}",
            'data' => [
                'issue_id' => $issue->id,
                'task_id' => $task->id,
                'severity' => $issue->severity,
                'issue_type' => $issue->issue_type,
                'reported_by' => $issue->reported_by,
            ],
            'is_read' => false,
        ]);
    }
}
