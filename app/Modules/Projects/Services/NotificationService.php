<?php

namespace App\Modules\Projects\Services;

use App\Models\Notification;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\UniversalTask\Models\Task as UniversalTask;
use Illuminate\Support\Facades\Log;
class NotificationService
{
    /**
     * Send notification for Enquiry Task assignment
     */
    public function sendEnquiryTaskAssignment(
        EnquiryTask $task,
        User $assignedTo,
        User $assignedBy,
        bool $isReassignment = false
    ): void {
        try {
            $task->loadMissing('enquiry.client'); // Ensure relationships are loaded
            
            $type = $isReassignment ? 'enquiry_task_reassigned' : 'enquiry_task_assigned';
            $title = $isReassignment ? 'Task Reassigned' : 'New Task Assigned';
            
            $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Unknown Project';
            $enquiryNumber = $task->enquiry ? $task->enquiry->enquiry_number : 'N/A';
            $clientName = $task->enquiry && $task->enquiry->client ? $task->enquiry->client->name : 'Unknown Client';

            Notification::create([
                'user_id' => $assignedTo->id,
                'type' => $type,
                'title' => $title,
                'message' => "You have been assigned: {$task->title} for {$enquiryTitle} (#{$enquiryNumber})",
                'notifiable_type' => EnquiryTask::class,
                'notifiable_id' => $task->id,
                'data' => [
                    'task_id' => $task->id,
                    'enquiry_id' => $task->project_enquiry_id,
                    'enquiry_title' => $enquiryTitle,
                    'enquiry_number' => $enquiryNumber,
                    'client_name' => $clientName,
                    'task_type' => $task->type,
                    'assigned_by' => $assignedBy->name,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                ],
            ]);

            // If reassignment, notify the old assignee
            if ($isReassignment && $task->getOriginal('assigned_user_id')) {
                $oldUserId = $task->getOriginal('assigned_user_id');
                if ($oldUserId && $oldUserId !== $assignedTo->id) {
                    $oldUser = User::find($oldUserId);
                    if ($oldUser) {
                        Notification::create([
                            'user_id' => $oldUser->id,
                            'type' => 'enquiry_task_unassigned',
                            'title' => 'Task Reassigned',
                            'message' => "Task '{$task->title}' for {$enquiryTitle} has been reassigned to {$assignedTo->name}",
                            'notifiable_type' => EnquiryTask::class,
                            'notifiable_id' => $task->id,
                            'data' => [
                                'task_id' => $task->id,
                                'enquiry_id' => $task->project_enquiry_id,
                                'enquiry_title' => $enquiryTitle,
                                'reassigned_to' => $assignedTo->name,
                                'reassigned_by' => $assignedBy->name,
                            ],
                        ]);
                    }
                }
            }

            Log::info("Enquiry task notification sent", [
                'task_id' => $task->id,
                'user_id' => $assignedTo->id,
                'type' => $type
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send enquiry task notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification for Universal Task assignment
     */
    public function sendUniversalTaskAssignment(
        UniversalTask $task,
        User $assignedTo,
        User $assignedBy
    ): void {
        try {
            // Resolve context if available
            $contextTitle = null;
            $contextNumber = null;
            $clientName = null;

            if ($task->taskable_type && $task->taskable_id) {
                // Try to load the polymorphic relation
                $related = $task->taskable; // Assuming 'taskable' relationship exists in UniversalTask model
                if (!$related && class_exists($task->taskable_type)) {
                    $related = $task->taskable_type::find($task->taskable_id);
                }

                if ($related) {
                    if ($related instanceof \App\Models\ProjectEnquiry) {
                        $contextTitle = $related->title;
                        $contextNumber = $related->enquiry_number;
                        // Load client if possible
                        if ($related->client) {
                            $clientName = $related->client->name;
                        }
                    } elseif (property_exists($related, 'title')) {
                        $contextTitle = $related->title;
                    } elseif (property_exists($related, 'name')) {
                        $contextTitle = $related->name;
                    }
                }
            }

            $message = "You have been assigned: {$task->title}";
            if ($contextTitle) {
                $message .= " for {$contextTitle}";
                if ($contextNumber) $message .= " (#{$contextNumber})";
            }

            Notification::create([
                'user_id' => $assignedTo->id,
                'type' => 'universal_task_assigned',
                'title' => 'New Task Assigned',
                'message' => $message,
                'notifiable_type' => UniversalTask::class,
                'notifiable_id' => $task->id,
                'data' => [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                    'assigned_by' => $assignedBy->name,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->toISOString(),
                    'taskable_type' => $task->taskable_type,
                    'taskable_id' => $task->taskable_id,
                    // Unified context fields for frontend
                    'enquiry_title' => $contextTitle, 
                    'enquiry_number' => $contextNumber,
                    'client_name' => $clientName,
                ],
            ]);

            Log::info("Universal task notification sent", [
                'task_id' => $task->id,
                'user_id' => $assignedTo->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send universal task notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when task is due soon
     */
    public function sendTaskDueSoonNotification($task, User $user, string $taskType = 'enquiry'): void
    {
        try {
            $isEnquiry = $taskType === 'enquiry';
            
            if ($isEnquiry) {
                $task->loadMissing('enquiry');
                $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Project';
                $message = "Upcoming Deadline: '{$task->title}' for {$enquiryTitle} is due soon";
            } else {
                $message = "Upcoming Deadline: '{$task->title}' is due soon";
            }

            $type = $isEnquiry ? 'enquiry_task_due_soon' : 'universal_task_due_soon';
            $daysRemaining = now()->diffInDays($task->due_date, false);

            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => 'Task Due Soon',
                'message' => $message,
                'notifiable_type' => $isEnquiry ? EnquiryTask::class : UniversalTask::class,
                'notifiable_id' => $task->id,
                'data' => [
                    'task_id' => $task->id,
                    'due_date' => $task->due_date?->toISOString(),
                    'days_remaining' => $daysRemaining,
                    'priority' => $task->priority,
                ] + ($isEnquiry && $task->enquiry ? ['enquiry_id' => $task->project_enquiry_id, 'enquiry_title' => $task->enquiry->title] : []),
            ]);

            Log::info("{$taskType} task due soon notification sent", [
                'task_id' => $task->id,
                'user_id' => $user->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task due soon notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when task is overdue
     */
    public function sendTaskOverdueNotification($task, User $user, string $taskType = 'enquiry'): void
    {
        try {
            $isEnquiry = $taskType === 'enquiry';
            
            if ($isEnquiry) {
                $task->loadMissing('enquiry');
                $enquiryTitle = $task->enquiry ? $task->enquiry->title : 'Project';
                $message = "Task Overdue: '{$task->title}' for {$enquiryTitle} is overdue";
            } else {
                $message = "Task Overdue: '{$task->title}' is overdue";
            }

            $type = $isEnquiry ? 'enquiry_task_overdue' : 'universal_task_overdue';
            $daysOverdue = $task->due_date->diffInDays(now());

            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => 'Task Overdue',
                'message' => "Task '{$task->title}' is now {$daysOverdue} day(s) overdue", // Keeping original main message concise, title handles urgency
                'notifiable_type' => $isEnquiry ? EnquiryTask::class : UniversalTask::class,
                'notifiable_id' => $task->id,
                'data' => [
                    'task_id' => $task->id,
                    'due_date' => $task->due_date->toISOString(),
                    'days_overdue' => $daysOverdue,
                    'priority' => $task->priority,
                ] + ($isEnquiry && $task->enquiry ? ['enquiry_id' => $task->project_enquiry_id, 'enquiry_title' => $task->enquiry->title] : []),
            ]);

            Log::info("{$taskType} task overdue notification sent", [
                'task_id' => $task->id,
                'user_id' => $user->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task overdue notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when a new enquiry is created
     */
    public function sendEnquiryCreatedNotification(ProjectEnquiry $enquiry, User $recipient): void
    {
        try {
            Notification::create([
                'user_id' => $recipient->id,
                'type' => 'enquiry_created',
                'title' => 'New Project Enquiry',
                'message' => "New enquiry '{$enquiry->title}' (#{$enquiry->enquiry_number}) has been created",
                'notifiable_type' => ProjectEnquiry::class,
                'notifiable_id' => $enquiry->id,
                'data' => [
                    'enquiry_id' => $enquiry->id,
                    'enquiry_title' => $enquiry->title,
                    'enquiry_number' => $enquiry->enquiry_number,
                    'client_name' => $enquiry->client ? $enquiry->client->full_name : 'Unknown Client',
                    'created_by' => $enquiry->creator ? $enquiry->creator->name : 'Unknown User',
                    'priority' => $enquiry->priority,
                    'date_received' => $enquiry->date_received?->toISOString(),
                ],
            ]);

            Log::info("Enquiry created notification sent", [
                'enquiry_id' => $enquiry->id,
                'recipient_id' => $recipient->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send enquiry created notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when Enquiry Task is completed
     */
    public function sendEnquiryTaskCompleted(EnquiryTask $task, User $completedBy): void
    {        try {
            // Notify task creator
            if ($task->created_by && $task->created_by !== $completedBy->id) {
                $creator = User::find($task->created_by);
                if ($creator) {
                    $this->createCompletionNotification($task, $completedBy, $creator, 'enquiry');
                }
            }

            // Notify user who assigned it
            if ($task->assigned_by && $task->assigned_by !== $completedBy->id) {
                $assigner = User::find($task->assigned_by);
                if ($assigner && $assigner->id !== $task->created_by) {
                    $this->createCompletionNotification($task, $completedBy, $assigner, 'enquiry');
                }
            }

            Log::info("Enquiry task completion notifications sent", [
                'task_id' => $task->id,
                'completed_by' => $completedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send task completion notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification when Universal Task is completed
     */
    public function sendUniversalTaskCompleted(UniversalTask $task, User $completedBy): void
    {
        try {
            // Notify task creator
            if ($task->created_by && $task->created_by !== $completedBy->id) {
                $creator = User::find($task->created_by);
                if ($creator) {
                    $this->createCompletionNotification($task, $completedBy, $creator, 'universal');
                }
            }

            Log::info("Universal task completion notification sent", [
                'task_id' => $task->id,
                'completed_by' => $completedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send universal task completion notification: " . $e->getMessage());
        }
    }

    /**
     * Create completion notification (private helper)
     */
    private function createCompletionNotification(
        $task,
        User $completedBy,
        User $recipient,
        string $taskType = 'enquiry'
    ): void {
        $isEnquiry = $taskType === 'enquiry';

        Notification::create([
            'user_id' => $recipient->id,
            'type' => $isEnquiry ? 'enquiry_task_completed' : 'universal_task_completed',
            'title' => 'Task Completed',
            'message' => "Task '{$task->title}' has been completed by {$completedBy->name}",
            'notifiable_type' => $isEnquiry ? EnquiryTask::class : UniversalTask::class,
            'notifiable_id' => $task->id,
            'data' => [
                'task_id' => $task->id,
                'completed_by' => $completedBy->name,
                'completed_at' => now()->toISOString(),
            ] + ($isEnquiry ? ['enquiry_id' => $task->project_enquiry_id] : []),
        ]);
    }

    /**
     * Get notifications for a user
     * SECURITY: Only returns notifications for the specified user
     */
    public function getUserNotifications(
        int $userId,
        bool $unreadOnly = false,
        ?string $type = null,
        int $limit = 50
    ) {
        $query = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

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
     * SECURITY: Verifies the notification belongs to the user
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
     * SECURITY: Only affects the specified user's notifications
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
     * Delete a notification
     * SECURITY: Verifies the notification belongs to the user
     */
    public function deleteNotification(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification) {
            $notification->delete();
            return true;
        }

        return false;
    }
}
