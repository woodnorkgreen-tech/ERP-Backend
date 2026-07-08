<?php

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use Illuminate\Support\Facades\Log;

class AutoSyncTaskStateAction
{
    public function __construct(
        private EnquiryWorkflowService $workflowService
    ) {}

    /**
     * Synchronize the state of a task based on its underlying data.
     *
     * Detection lives here, but the actual status change is delegated to the
     * single source of truth ({@see EnquiryWorkflowService::updateTaskStatus})
     * so gates, enquiry-status sync and notifications stay consistent — no task
     * status is ever written directly.
     */
    public function execute(EnquiryTask $task): void
    {
        if ($task->status === 'completed') {
            return;
        }

        $shouldComplete = false;
        $reason = '';

        // 1. Materials Auto-Completion
        if ($task->type === 'materials') {
            $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
            if ($materialsData) {
                $status = $materialsData->project_info['approval_status'] ?? [];
                if ($status['all_approved'] ?? false) {
                    $shouldComplete = true;
                    $reason = 'All materials approved';
                }
            }
        }

        // 2. Budget Auto-Completion
        if ($task->type === 'budget') {
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $task->id)->first();
            if ($budgetData && !empty($budgetData->budget_summary)) {
                $total = (float)($budgetData->budget_summary['grandTotal'] ?? 0);
                if ($total > 0) {
                    $shouldComplete = true;
                    $reason = "Budget finalized with total: {$total}";
                }
            }
        }

        // 3. Quote — move to in_progress once quote data exists. The quote is
        // commercial input for the approved scope, not something unlocked by an
        // internal budget.
        if ($task->type === 'quote') {
            $quoteData = \App\Models\TaskQuoteData::where('enquiry_task_id', $task->id)->first();
            if ($quoteData && $task->status === 'pending') {
                $this->transition($task, 'in_progress', 'Quote data present');
            }
        }

        // 4. Quote Approval Auto-Completion
        if ($task->type === 'quote_approval') {
            $approval = \DB::table('quote_approvals')->where('task_id', $task->id)->first();
            if ($approval && $approval->approval_status === 'approved') {
                $shouldComplete = true;
                $reason = 'Quote formally approved in database';
            }
        }

        if ($shouldComplete) {
            $this->transition($task, 'completed', $reason);
        }
    }

    /**
     * Route an auto-state change through the workflow service. Best-effort: a
     * gate rejection (e.g. prerequisites not yet met) must never break the data
     * save that triggered this observer — it just means the task isn't advanced.
     */
    private function transition(EnquiryTask $task, string $status, string $reason): void
    {
        try {
            Log::info("AutoSyncTaskStateAction: auto-{$status} task {$task->id} ({$task->type}) - {$reason}");
            $this->workflowService->updateTaskStatus($task->id, $status, $task->assigned_user_id);
        } catch (\Throwable $e) {
            Log::warning("AutoSyncTaskStateAction: skipped auto-{$status} for task {$task->id}: {$e->getMessage()}");
        }
    }
}
