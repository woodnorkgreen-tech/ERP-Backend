<?php

namespace App\Modules\UniversalTask\Services;

use App\Models\Notification;
use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TaskNotificationService
{
    /**
     * Send notification when a task is assigned to users.
     *
     * @param Task $task
     * @param array $assignments Array of TaskAssignment models
     * @param int $assignerId User who made the assignment
     */
    public function notifyTaskAssigned(Task $task, array $assignments, int $assignerId): void
    {
        $assigner = User::find($assignerId);

        foreach ($assignments as $assignment) {
            $user = $assignment->user;

            // Create in-app notification
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_assigned',
                'title' => 'Task Assigned',
                'message' => "You have been assigned to task: {$task->title}",
                'data' => [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'assigned_by' => $assigner?->name,
                    'assignment_role' => $assignment->role,
                ],
            ]);

            // Send email notification if user has email preferences
            if ($this->shouldSendEmailNotification($user, 'task_assigned')) {
                $this->sendEmailNotification($user, 'task_assigned', [
                    'task' => $task,
                    'assigner' => $assigner,
                    'assignment' => $assignment,
                ]);
            }

            Log::info('Task assignment notification sent', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'notification_type' => 'task_assigned',
            ]);
        }
    }

    /**
     * Send notification when a task status changes.
     *
     * @param Task $task
     * @param string $oldStatus
     * @param string $newStatus
     * @param int $userId User who changed the status
     */
    public function notifyTaskStatusChanged(Task $task, string $oldStatus, string $newStatus, int $userId): void
    {
        $user = User::find($userId);
        $assignees = $this->getTaskAssignees($task);

        foreach ($assignees as $assignee) {
            // Skip if the user who changed status is the assignee (avoid self-notification)
            if ($assignee->id === $userId) {
                continue;
            }

            Notification::create([
                'user_id' => $assignee->id,
                'type' => 'task_status_changed',
                'title' => 'Task Status Updated',
                'message' => "Task '{$task->title}' status changed from {$oldStatus} to {$newStatus}",
                'data' => [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by' => $user?->name,
                ],
            ]);

            // Send email for important status changes
            if ($this->shouldSendEmailForStatusChange($newStatus) &&
                $this->shouldSendEmailNotification($assignee, 'task_status_changed')) {
                $this->sendEmailNotification($assignee, 'task_status_changed', [
                    'task' => $task,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by' => $user,
                ]);
            }
        }

        Log::info('Task status change notifications sent', [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $userId,
            'assignees_notified' => count($assignees),
        ]);
    }

    /**
     * Send notification when a task is due soon.
     *
     * @param Task $task
     * @param int $hoursUntilDue
     */
    public function notifyTaskDueSoon(Task $task, int $hoursUntilDue): void
    {
        $assignees = $this->getTaskAssignees($task);

        foreach ($assignees as $assignee) {
            Notification::create([
                'user_id' => $assignee->id,
                'type' => 'task_due_soon',
                'title' => 'Task Due Soon',
                'message' => "Task '{$task->title}' is due in {$hoursUntilDue} hours",
                'data' => [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'due_date' => $task->due_date?->toISOString(),
                    'hours_until_due' => $hoursUntilDue,
                ],
            ]);

            // Send email notification
            if ($this->shouldSendEmailNotification($assignee, 'task_due_soon')) {
                $this->sendEmailNotification($assignee, 'task_due_soon', [
                    'task' => $task,
                    'hours_until_due' => $hoursUntilDue,
                ]);
            }
        }

        Log::info('Task due soon notifications sent', [
            'task_id' => $task->id,
            'hours_until_due' => $hoursUntilDue,
            'assignees_notified' => count($assignees),
        ]);
    }

    /**
     * Send notification when a task becomes overdue.
     *
     * @param Task $task
     */
    public function notifyTaskOverdue(Task $task): void
    {
        $assignees = $this->getTaskAssignees($task);
        $manager = $this->getUserManager($task->assignedUser);

        $recipients = array_merge($assignees, $manager ? [$manager] : []);

        foreach ($recipients as $recipient) {
            Notification::create([
                'user_id' => $recipient->id,
                'type' => 'task_overdue',
                'title' => 'Task Overdue',
                'message' => "Task '{$task->title}' is now overdue",
                'data' => [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'due_date' => $task->due_date?->toISOString(),
                    'overdue_days' => $task->due_date ? now()->diffInDays($task->due_date) : null,
                ],
            ]);

            // Always send email for overdue notifications
            if ($this->shouldSendEmailNotification($recipient, 'task_overdue')) {
                $this->sendEmailNotification($recipient, 'task_overdue', [
                    'task' => $task,
                ]);
            }
        }

        Log::info('Task overdue notifications sent', [
            'task_id' => $task->id,
            'assignees_notified' => count($assignees),
            'managers_notified' => $manager ? 1 : 0,
        ]);
    }

    /**
     * Send notification when a user is mentioned in a comment.
     *
     * @param Task $task
     * @param User $mentionedUser
     * @param User $mentioner
     * @param string $comment
     */
    public function notifyUserMentioned(Task $task, User $mentionedUser, User $mentioner, string $comment): void
    {
        Notification::create([
            'user_id' => $mentionedUser->id,
            'type' => 'user_mentioned',
            'title' => 'You were mentioned',
            'message' => "{$mentioner->name} mentioned you in task: {$task->title}",
            'data' => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'mentioned_by' => $mentioner->name,
                'comment_preview' => substr($comment, 0, 100),
            ],
        ]);

        // Send email notification
        if ($this->shouldSendEmailNotification($mentionedUser, 'user_mentioned')) {
            $this->sendEmailNotification($mentionedUser, 'user_mentioned', [
                'task' => $task,
                'mentioner' => $mentioner,
                'comment' => $comment,
            ]);
        }

        Log::info('User mention notification sent', [
            'task_id' => $task->id,
            'mentioned_user_id' => $mentionedUser->id,
            'mentioned_by' => $mentioner->id,
        ]);
    }

    /**
     * Send notification for task issues.
     *
     * @param Task $task
     * @param string $issueType ('created', 'resolved', 'escalated')
     * @param array $issueData
     */
    public function notifyTaskIssue(Task $task, string $issueType, array $issueData): void
    {
        $assignees = $this->getTaskAssignees($task);
        $supervisors = $this->getTaskSupervisors($task);

        $recipients = array_merge($assignees, $supervisors);

        $notificationData = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'issue_type' => $issueType,
            'issue_title' => $issueData['title'] ?? null,
            'issue_severity' => $issueData['severity'] ?? null,
        ];

        $title = match ($issueType) {
            'created' => 'Task Issue Reported',
            'resolved' => 'Task Issue Resolved',
            'escalated' => 'Task Issue Escalated',
            default => 'Task Issue Update',
        };

        $message = match ($issueType) {
            'created' => "An issue was reported for task: {$task->title}",
            'resolved' => "An issue was resolved for task: {$task->title}",
            'escalated' => "An issue was escalated for task: {$task->title}",
            default => "Task issue update for: {$task->title}",
        };

        foreach ($recipients as $recipient) {
            Notification::create([
                'user_id' => $recipient->id,
                'type' => 'task_issue',
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
            ]);

            // Send email for critical issues
            if (($issueData['severity'] ?? null) === 'critical' &&
                $this->shouldSendEmailNotification($recipient, 'task_issue')) {
                $this->sendEmailNotification($recipient, 'task_issue', [
                    'task' => $task,
                    'issue_type' => $issueType,
                    'issue_data' => $issueData,
                ]);
            }
        }

        Log::info('Task issue notifications sent', [
            'task_id' => $task->id,
            'issue_type' => $issueType,
            'recipients_count' => count($recipients),
        ]);
    }

    /**
     * Get all assignees for a task (including effective assignees for subtasks).
     *
     * @param Task $task
     * @return \Illuminate\Support\Collection
     */
    protected function getTaskAssignees(Task $task)
    {
        $assignees = collect();

        // Direct assignments
        $assignees = $assignees->merge($task->assignments->pluck('user'));

        // Effective assignee
        if ($task->getEffectiveAssignee()) {
            $assignees->push($task->getEffectiveAssignee());
        }

        return $assignees->unique('id');
    }

    /**
     * Get supervisors for a task (department managers, etc.).
     *
     * @param Task $task
     * @return array
     */
    protected function getTaskSupervisors(Task $task): array
    {
        // TODO: Implement logic to get department managers or supervisors
        // For now, return empty array
        return [];
    }

    /**
     * Get the manager of a user.
     *
     * @param User|null $user
     * @return User|null
     */
    protected function getUserManager(?User $user): ?User
    {
        // TODO: Implement logic to get user's manager
        // This would depend on the employee/department structure
        return null;
    }

    /**
     * Check if email notification should be sent for a user and notification type.
     *
     * @param User $user
     * @param string $notificationType
     * @return bool
     */
    protected function shouldSendEmailNotification(User $user, string $notificationType): bool
    {
        // TODO: Implement user notification preferences
        // For now, assume email notifications are enabled
        return true;
    }

    /**
     * Check if email should be sent for status changes.
     *
     * @param string $newStatus
     * @return bool
     */
    protected function shouldSendEmailForStatusChange(string $newStatus): bool
    {
        return in_array($newStatus, ['completed', 'blocked', 'overdue']);
    }

    /**
     * Send email notification.
     *
     * @param User $user
     * @param string $type
     * @param array $data
     */
    protected function sendEmailNotification(User $user, string $type, array $data): void
    {
        // TODO: Implement email sending using Laravel Mail
        // This would require creating Mailable classes for each notification type

        Log::info('Email notification would be sent', [
            'user_id' => $user->id,
            'notification_type' => $type,
            'data_keys' => array_keys($data),
        ]);
    }
}