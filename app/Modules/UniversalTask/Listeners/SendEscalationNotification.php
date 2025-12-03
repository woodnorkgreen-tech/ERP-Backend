<?php

namespace App\Modules\UniversalTask\Listeners;

use App\Modules\UniversalTask\Events\TaskStatusChanged;
use App\Modules\UniversalTask\Services\TaskNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendEscalationNotification implements ShouldQueue
{
    public TaskNotificationService $notificationService;

    public function __construct(TaskNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(TaskStatusChanged $event): void
    {
        // Send escalation notifications for overdue tasks
        if ($event->newStatus === 'overdue') {
            $this->notificationService->notifyTaskOverdue($event->task);
        }
    }
}