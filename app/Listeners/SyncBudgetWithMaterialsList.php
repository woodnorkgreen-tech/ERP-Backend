<?php

namespace App\Listeners;

use App\Events\MaterialsListChanged;
use App\Modules\Finance\Services\FinanceEventPoster;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\BudgetService;
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
 * carries the budget's own rates across — so a repeated run is harmless.
 *
 * Not queued (Report 76A). "Every save drives it" was only true where a queue
 * worker was running; without one the budget stopped following the materials
 * list and nothing said so. It now runs in the saving request's own process,
 * after the response has been sent, through FinanceEventPoster.
 *
 * A failure still cannot abort the materials save that triggered it — the
 * poster never throws into its caller. What changed is where the failure goes:
 * it used to be caught here and written to the log, invisible from every
 * screen; it is now a failed posting Finance can see and send again.
 */
class SyncBudgetWithMaterialsList
{
    public function __construct(
        private BudgetService $budgets,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(MaterialsListChanged $event): void
    {
        $this->postings->record(
            FinanceEventPoster::BUDGET_MATERIALS_SYNC,
            $event->materialsTaskId,
            afterResponse: true,
        );
    }

    public function post(array $payload, int $materialsTaskId): string
    {
        $materialsTask = EnquiryTask::find($materialsTaskId);

        if (! $materialsTask) {
            return 'materials task no longer exists';
        }

        $budgetTask = EnquiryTask::where('project_enquiry_id', $materialsTask->project_enquiry_id)
            ->where('type', 'budget')
            ->first();

        // No budget task yet is normal, not a failure: the materials list exists
        // before the budget does on plenty of projects. The budget pulls the list
        // itself when it is first opened, so nothing is lost by there being
        // nothing to push into.
        if (! $budgetTask) {
            return 'no budget task yet';
        }

        $result = $this->budgets->syncFromMaterialsList($budgetTask->id);

        Log::info('Budget synced with materials list', [
            'materials_task_id' => $materialsTaskId,
            'budget_task_id' => $budgetTask->id,
            'reopened' => $result['reopened'],
        ]);

        return 'budget synced'.($result['reopened'] ? ' and reopened' : '');
    }
}
