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

        // A final survey submission is already the user's completion action.
        if ($task->type === 'site-survey') {
            $survey = \App\Models\SiteSurvey::where('enquiry_task_id', $task->id)->first();
            if ($survey && in_array($survey->status, ['completed', 'approved'], true)) {
                $shouldComplete = true;
                $reason = 'Final site survey submitted';
            }
        }

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

        // 2. Budget: deliberately NOT auto-completed. "grandTotal > 0" isn't a
        // real submission signal — it also goes true from the background
        // materials-approval sync (MaterialsController::syncMaterialsToBudget)
        // and from the 2s debounced autosave while the user is still editing.
        // Completion only happens via the explicit "Complete Task" button,
        // which is already gated by EnquiryWorkflowService::validateTaskCompletion.

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

        // Procurement is complete when every line that needs purchasing has
        // been received. In-stock/no-purchase lines are already resolved.
        if ($task->type === 'procurement') {
            $procurement = \App\Models\TaskProcurementData::where('enquiry_task_id', $task->id)->first();
            $items = $procurement?->procurement_items ?? [];
            $openItems = collect($items)->filter(function (array $item): bool {
                return (float) ($item['purchaseQuantity'] ?? 0) > 0
                    && ($item['procurementStatus'] ?? null) !== 'received'
                    && ($item['availabilityStatus'] ?? null) !== 'received';
            });
            if (!empty($items) && $openItems->isEmpty()) {
                $shouldComplete = true;
                $reason = 'All procurement items resolved or received';
            }
        }

        if ($task->type === 'production') {
            $workOrder = $task->workOrder()->with('tasks')->first();
            if ($workOrder && $workOrder->tasks->isNotEmpty()
                && $workOrder->tasks->every(fn ($item) => $item->status === 'completed')) {
                $shouldComplete = true;
                $reason = 'All production work-order tasks completed';
            }
        }

        if ($task->type === 'handover') {
            $survey = $task->handoverSurvey;
            if ($survey && $survey->submitted) {
                $shouldComplete = true;
                $reason = 'Client handover acknowledgement submitted';
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
            // The transition is performed by the system because objective task
            // evidence changed. The assignee remains visible on the task but is
            // not falsely recorded as the person who clicked completion.
            $this->workflowService->updateTaskStatus($task->id, $status, null, $reason);
        } catch (\Throwable $e) {
            Log::warning("AutoSyncTaskStateAction: skipped auto-{$status} for task {$task->id}: {$e->getMessage()}");
        }
    }
}
