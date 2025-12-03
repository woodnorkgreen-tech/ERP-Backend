<?php

namespace App\Modules\UniversalTask\Listeners;

use App\Modules\UniversalTask\Events\TaskAssigned;
use App\Modules\UniversalTask\Events\TaskStatusChanged;
use App\Modules\UniversalTask\Events\TaskCompleted;
use App\Modules\UniversalTask\Events\UserMentioned;
use App\Modules\UniversalTask\Services\TaskNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendTaskNotification implements ShouldQueue
{
    public TaskNotificationService $notificationService;

    public function __construct(TaskNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handleTaskAssigned(TaskAssigned $event): void
    {
        $this->notificationService->notifyTaskAssigned(
            $event->task,
            $event->assignments,
            $event->assignerId
        );
    }

    public function handleTaskStatusChanged(TaskStatusChanged $event): void
    {
        $this->notificationService->notifyTaskStatusChanged(
            $event->task,
            $event->oldStatus,
            $event->newStatus,
            $event->userId
        );
    }

    public function handleTaskCompleted(TaskCompleted $event): void
    {
        // TaskCompleted is a specific case of TaskStatusChanged
        // This listener can handle additional completion-specific logic if needed
        $this->notificationService->notifyTaskStatusChanged(
            $event->task,
            'in_progress', // Assuming completion from in_progress
            'completed',
            $event->userId
        );
    }

    public function handleUserMentioned(UserMentioned $event): void
    {
        $this->notificationService->notifyUserMentioned(
            $event->task,
            $event->mentionedUser,
            $event->mentioner,
            $event->comment
        );
    }

    /**
     * Get the name of the listener method for a given event.
     */
    public function getListenerMethodForEvent($event): string
    {
        return match (get_class($event)) {
            TaskAssigned::class => 'handleTaskAssigned',
            TaskStatusChanged::class => 'handleTaskStatusChanged',
            TaskCompleted::class => 'handleTaskCompleted',
            UserMentioned::class => 'handleUserMentioned',
            default => 'handle',
        };
    }
}