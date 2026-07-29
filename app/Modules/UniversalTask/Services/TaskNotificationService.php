<?php

namespace App\Modules\UniversalTask\Services;

use App\Models\User;
use App\Modules\Notifications\Services\NotificationService as CentralNotificationService;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Formats and dispatches UniversalTask notifications through the
 * centralized Notifications module, which owns persistence, channel/
 * preference resolution, and delivery (mail/push) — replacing the old
 * direct App\Models\Notification writes and no-op email/preference stubs.
 *
 * Public method signatures are unchanged so existing listeners
 * (SendTaskNotification, SendReminderNotification)
 * keep working without modification.
 */
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

            CentralNotificationService::send(
                type: 'universal_task_assigned',
                title: 'Task Assigned',
                message: "You have been assigned to task: {$task->title}",
                module: 'universal-task',
                data: [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'assigned_by' => $assigner?->name,
                    'assignment_role' => $assignment->role,
                    'url' => "/universal-tasks/{$task->id}",
                ],
                users: [$user],
            );

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

        $recipients = $assignees->reject(fn ($assignee) => $assignee->id === $userId);

        foreach ($recipients as $assignee) {
            CentralNotificationService::send(
                type: 'universal_task_status_changed',
                title: 'Task Status Updated',
                message: "Task '{$task->title}' status changed from {$oldStatus} to {$newStatus}",
                module: 'universal-task',
                data: [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by' => $user?->name,
                    'url' => "/universal-tasks/{$task->id}",
                ],
                users: [$assignee],
            );
        }

        Log::info('Task status change notifications sent', [
            'task_id' => $task->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $userId,
            'assignees_notified' => $recipients->count(),
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
            CentralNotificationService::send(
                type: 'universal_task_due_soon',
                title: 'Task Due Soon',
                message: "Task '{$task->title}' is due in {$hoursUntilDue} hours",
                module: 'universal-task',
                urgency: 'warning',
                data: [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'due_date' => $task->due_date?->toISOString(),
                    'hours_until_due' => $hoursUntilDue,
                    'url' => "/universal-tasks/{$task->id}",
                ],
                users: [$assignee],
            );
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

        $recipients = $manager ? $assignees->push($manager)->unique('id') : $assignees;

        foreach ($recipients as $recipient) {
            CentralNotificationService::send(
                type: 'universal_task_overdue',
                title: 'Task Overdue',
                message: "Task '{$task->title}' is now overdue",
                module: 'universal-task',
                urgency: 'warning',
                data: [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'due_date' => $task->due_date?->toISOString(),
                    'overdue_days' => $task->due_date ? now()->diffInDays($task->due_date) : null,
                    'url' => "/universal-tasks/{$task->id}",
                ],
                users: [$recipient],
            );
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
        CentralNotificationService::send(
            type: 'universal_task_user_mentioned',
            title: 'You were mentioned',
            message: "{$mentioner->name} mentioned you in task: {$task->title}",
            module: 'universal-task',
            data: [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'mentioned_by' => $mentioner->name,
                'comment_preview' => substr($comment, 0, 100),
                'url' => "/universal-tasks/{$task->id}",
            ],
            users: [$mentionedUser],
        );

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

        $recipients = $assignees->merge($supervisors)->unique('id');

        $notificationData = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'issue_id' => $issueData['issue_id'] ?? null,
            'issue_type' => $issueType,
            'issue_title' => $issueData['title'] ?? null,
            'issue_severity' => $issueData['severity'] ?? null,
            'url' => "/universal-tasks/{$task->id}",
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

        $urgency = ($issueData['severity'] ?? null) === 'critical' ? 'critical' : 'warning';

        foreach ($recipients as $recipient) {
            CentralNotificationService::send(
                type: 'universal_task_issue',
                title: $title,
                message: $message,
                module: 'universal-task',
                urgency: $urgency,
                data: $notificationData,
                users: [$recipient],
            );
        }

        Log::info('Task issue notifications sent', [
            'task_id' => $task->id,
            'issue_type' => $issueType,
            'recipients_count' => $recipients->count(),
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
        if (!$task->department_id) {
            return [];
        }

        $department = $task->department()->with('manager.user')->first();

        if ($department && $department->manager && $department->manager->user) {
            return [$department->manager->user];
        }

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
}
