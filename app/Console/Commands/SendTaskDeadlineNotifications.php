<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\UniversalTask\Models\Task as UniversalTask;
use App\Modules\Projects\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendTaskDeadlineNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:notify-deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send notifications for upcoming and overdue task deadlines';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService)
    {
        $this->info('Starting deadline notification check...');

        try {
            // 1. Check Enquiry Tasks
            $this->checkEnquiryTasks($notificationService);

            // 2. Check Universal Tasks
            $this->checkUniversalTasks($notificationService);

            $this->info('Deadline notifications sent successfully.');
        } catch (\Exception $e) {
            $this->error('Error sending deadline notifications: ' . $e->getMessage());
            Log::error('Error sending deadline notifications: ' . $e->getMessage());
        }
    }

    private function checkEnquiryTasks(NotificationService $notificationService)
    {
        // Upcoming Enquiry Tasks (Due within 24 hours)
        $upcomingTasks = EnquiryTask::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDay()])
            ->whereNotNull('assigned_user_id')
            ->with('assignedUser')
            ->get();

        $this->info("Found {$upcomingTasks->count()} upcoming enquiry tasks.");

        foreach ($upcomingTasks as $task) {
            if ($task->assignedUser) {
                // Check if we already notified recently to avoid spam (optional constraint)
                // For now, relying on scheduler frequency (e.g. daily)
                $notificationService->sendTaskDueSoonNotification(
                    $task,
                    $task->assignedUser,
                    'enquiry'
                );
            }
        }

        // Overdue Enquiry Tasks
        $overdueTasks = EnquiryTask::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotNull('assigned_user_id')
            ->with('assignedUser')
            ->get();
        
        $this->info("Found {$overdueTasks->count()} overdue enquiry tasks.");

        foreach ($overdueTasks as $task) {
            if ($task->assignedUser) {
                $notificationService->sendTaskOverdueNotification(
                    $task,
                    $task->assignedUser,
                    'enquiry'
                );
            }
        }
    }

    private function checkUniversalTasks(NotificationService $notificationService)
    {
        // Upcoming Universal Tasks (Due within 24 hours)
        $upcomingTasks = UniversalTask::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDay()])
            ->whereNotNull('assigned_user_id')
            ->with('assignedUser')
            ->get();

        $this->info("Found {$upcomingTasks->count()} upcoming universal tasks.");

        foreach ($upcomingTasks as $task) {
            if ($task->assignedUser) {
                $notificationService->sendTaskDueSoonNotification(
                    $task,
                    $task->assignedUser,
                    'universal'
                );
            }
        }

        // Overdue Universal Tasks
        $overdueTasks = UniversalTask::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotNull('assigned_user_id')
            ->with('assignedUser')
            ->get();

        $this->info("Found {$overdueTasks->count()} overdue universal tasks.");

        foreach ($overdueTasks as $task) {
            if ($task->assignedUser) {
                $notificationService->sendTaskOverdueNotification(
                    $task,
                    $task->assignedUser,
                    'universal'
                );
            }
        }
    }
}
