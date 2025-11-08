<?php

namespace App\Modules\Projects\Services;

use App\Models\Notification;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send notification when a task is assigned
     */
    public function sendTaskAssignmentNotification(EnquiryTask $task, User $assignedTo, User $assignedBy): void
    {
        try {
            Notification::create([
                'user_id' => $assignedTo->id,
                'type' => 'task_assigned',
                'title' => 'New Task Assigned',
                'message' => "You have been assigned a new task: {$task->title}",
                'data' => [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'assigned_by' => $assignedBy->name,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                ],
            ]);

            Log::info("Task assignment notification sent to user {$assignedTo->id} for task {$task->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send task assignment notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when a task is due soon
     */
    public function sendTaskDueSoonNotification(EnquiryTask $task, User $user): void
    {
        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_due_soon',
                'title' => 'Task Due Soon',
                'message' => "Task '{$task->title}' is due soon",
                'data' => [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'due_date' => $task->due_date?->toISOString(),
                    'days_remaining' => now()->diffInDays($task->due_date, false),
                ],
            ]);

            Log::info("Task due soon notification sent to user {$user->id} for task {$task->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send task due soon notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when a task is overdue
     */
    public function sendTaskOverdueNotification(EnquiryTask $task, User $user): void
    {
        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'task_overdue',
                'title' => 'Task Overdue',
                'message' => "Task '{$task->title}' is now overdue",
                'data' => [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'due_date' => $task->due_date?->toISOString(),
                    'days_overdue' => $task->due_date->diffInDays(now()),
                ],
            ]);

            Log::info("Task overdue notification sent to user {$user->id} for task {$task->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send task overdue notification: " . $e->getMessage());
        }
    }

    /**
     * Get notifications for a user
     */
    public function getUserNotifications(int $userId, bool $unreadOnly = false, ?string $type = null)
    {
        $query = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->unread();
        }

        if ($type) {
            $query->byType($type);
        }

        return $query->get();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification) {
            $notification->markAsRead();
            return true;
        }

        return false;
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /**
     * Send notification to all users when a task is completed
     */
    public function sendTaskCompletedNotification(EnquiryTask $task, User $completedBy): void
    {
        try {
            // Get all users except the one who completed the task
            $users = User::where('id', '!=', $completedBy->id)->get();

            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'task_completed',
                    'title' => 'Task Completed',
                    'message' => "Task '{$task->title}' has been completed by {$completedBy->name}",
                    'data' => [
                        'task_id' => $task->id,
                        'enquiry_id' => $task->project_enquiry_id,
                        'completed_by' => $completedBy->name,
                        'completed_at' => now()->toISOString(),
                        'task_type' => $task->taskDefinition?->name ?? 'Unknown',
                    ],
                ]);
            }

            Log::info("Task completion notification sent to all users for task {$task->id} completed by {$completedBy->name}");
        } catch (\Exception $e) {
            Log::error("Failed to send task completion notification: " . $e->getMessage());
        }
    }
}
