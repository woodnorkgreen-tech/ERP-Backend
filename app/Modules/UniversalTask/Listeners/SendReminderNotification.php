<?php

namespace App\Modules\UniversalTask\Listeners;

use App\Modules\UniversalTask\Events\TaskDueSoon;
use App\Modules\UniversalTask\Services\TaskNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendReminderNotification implements ShouldQueue
{
    public TaskNotificationService $notificationService;

    public function __construct(TaskNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(TaskDueSoon $event): void
    {
        $this->notificationService->notifyTaskDueSoon(
            $event->task,
            $event->hoursUntilDue
        );
    }
}