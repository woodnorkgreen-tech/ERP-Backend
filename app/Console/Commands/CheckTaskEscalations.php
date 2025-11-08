<?php

namespace App\Console\Commands;

use App\Modules\Projects\Services\EnquiryWorkflowService;
use Illuminate\Console\Command;

class CheckTaskEscalations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:check-escalations {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for overdue tasks and escalate priorities, send notifications';

    protected EnquiryWorkflowService $workflowService;

    public function __construct(EnquiryWorkflowService $workflowService)
    {
        parent::__construct();
        $this->workflowService = $workflowService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for task escalations...');

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Check and escalate overdue tasks
        if (!$isDryRun) {
            $this->workflowService->checkAndEscalateOverdueTasks();
            $this->info('Overdue tasks checked and escalated');
        } else {
            $overdueTasks = \App\Modules\Projects\Models\EnquiryTask::where('due_date', '<', now())
                ->where('status', '!=', 'completed')
                ->whereNotNull('assigned_by')
                ->count();
            $this->info("Would escalate {$overdueTasks} overdue tasks");
        }

        // Send due date reminders
        if (!$isDryRun) {
            $this->workflowService->checkAndSendDueDateReminders();
            $this->info('Due date reminders sent');
        } else {
            $tasksDueSoon = \App\Modules\Projects\Models\EnquiryTask::where('due_date', '>=', now())
                ->where('due_date', '<=', now()->addDay())
                ->where('status', '!=', 'completed')
                ->whereNotNull('assigned_by')
                ->count();
            $this->info("Would send reminders for {$tasksDueSoon} tasks due soon");
        }

        $this->info('Task escalation check completed successfully');
    }
}
