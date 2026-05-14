<?php

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\Log;

class AutoSyncTaskStateAction
{
    /**
     * Synchronize the state of a task based on its underlying data.
     * 
     * @param EnquiryTask $task
     * @return void
     */
    public function execute(EnquiryTask $task): void
    {
        if ($task->status === 'completed') {
            return;
        }

        $shouldComplete = false;
        $reason = "";

        // 1. Materials Auto-Completion
        if ($task->type === 'materials') {
            $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $task->id)->first();
            if ($materialsData) {
                $status = $materialsData->project_info['approval_status'] ?? [];
                if ($status['all_approved'] ?? false) {
                    $shouldComplete = true;
                    $reason = "All materials approved";
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

        // 3. Quote Auto-Completion
        if ($task->type === 'quote') {
            $quoteData = \App\Models\TaskQuoteData::where('enquiry_task_id', $task->id)->first();
            if ($quoteData && $quoteData->budget_imported) {
                // If it's a simple project or auto-import is done, we could complete it.
                // For now, let's just mark it as "In Progress" if data exists.
                if ($task->status === 'pending') {
                    $task->status = 'in_progress';
                    $task->save();
                }
            }
        }

        // 4. Quote Approval Auto-Completion
        if ($task->type === 'quote_approval') {
            $approval = \DB::table('quote_approvals')->where('task_id', $task->id)->first();
            if ($approval && $approval->approval_status === 'approved') {
                $shouldComplete = true;
                $reason = "Quote formally approved in database";
            }
        }

        if ($shouldComplete) {
            Log::info("AutoSyncTaskStateAction: Auto-completing task {$task->id} ({$task->type}) - Reason: {$reason}");
            $task->status = 'completed';
            $task->completed_at = now();
            $task->save();
        }
    }
}
