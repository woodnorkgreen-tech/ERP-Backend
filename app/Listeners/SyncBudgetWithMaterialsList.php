<?php

namespace App\Listeners;

use App\Events\MaterialsListChanged;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\BudgetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the budget's material list identical to the materials task's.
 *
 * This is the whole of the "are they in sync?" question. It used to be answered
 * by a person: an orange banner appeared when the two copies differed and offered
 * a Sync button, so a budget was only as current as somebody's attention. Then
 * approval drove the sync, which meant an unapproved edit left the two lists
 * disagreeing until two people signed off. Now every save drives it, and there is
 * no state in which they are allowed to differ.
 *
 * Idempotent — `syncFromMaterialsList` rewrites from the materials list and
 * carries the budget's own rates across — so a replayed job is harmless.
 */
class SyncBudgetWithMaterialsList implements ShouldQueue
{
    public function __construct(private BudgetService $budgets) {}

    public function handle(MaterialsListChanged $event): void
    {
        $materialsTask = EnquiryTask::find($event->materialsTaskId);

        if (! $materialsTask) {
            return;
        }

        $budgetTask = EnquiryTask::where('project_enquiry_id', $materialsTask->project_enquiry_id)
            ->where('type', 'budget')
            ->first();

        // No budget task yet is normal, not a failure: the materials list exists
        // before the budget does on plenty of projects. The budget pulls the list
        // itself when it is first opened, so nothing is lost by there being
        // nothing to push into.
        if (! $budgetTask) {
            return;
        }

        try {
            $result = $this->budgets->syncFromMaterialsList($budgetTask->id);

            Log::info('Budget synced with materials list', [
                'materials_task_id' => $event->materialsTaskId,
                'budget_task_id' => $budgetTask->id,
                'reopened' => $result['reopened'],
            ]);
        } catch (\Throwable $e) {
            // Logged, never rethrown. In production this is queued, but the test
            // queue runs inline — and rethrowing there made a budget-sync problem
            // abort the materials save that triggered it, which is precisely
            // the coupling every other producer in this codebase avoids: a
            // downstream ledger must never stop someone saving their work.
            //
            // The failure is still loud. A budget left behind its materials
            // list is the exact thing this listener exists to prevent
            // and it is invisible from every screen, so it belongs in the log
            // even though it must not propagate.
            Log::error('Budget could not be synced with materials list', [
                'materials_task_id' => $event->materialsTaskId,
                'budget_task_id' => $budgetTask->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
