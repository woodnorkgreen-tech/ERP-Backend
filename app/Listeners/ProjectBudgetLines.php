<?php

namespace App\Listeners;

use App\Events\BudgetLinesChanged;
use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a project's planned cost lines equal to its budget.
 *
 * Idempotent by construction: the projector matches each line on
 * (source type, source id, source ref), supersedes what changed and retires
 * what the budget no longer contains, so a replayed job is harmless and a
 * double-dispatch cannot double-count a budget.
 *
 * Not queued (Report 76A). The planned lines are what every commitment and
 * actual is matched against, so a projection waiting on an absent worker left
 * the cost account with spend and no budget. It now runs in the saving
 * request's own process through FinanceEventPoster — after the response has
 * been sent, because the budget screen saves continuously and nobody should
 * wait on it — and a failure is a visible posting to retry rather than a
 * blocked save.
 */
class ProjectBudgetLines
{
    public function __construct(
        private BudgetProjector $projector,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(BudgetLinesChanged $event): void
    {
        $this->postings->record(
            FinanceEventPoster::BUDGET_PROJECTION,
            $event->budgetTaskId,
            ['actor_id' => $event->actorId],
            afterResponse: true,
        );
    }

    public function post(array $payload, int $budgetTaskId): string
    {
        $budget = TaskBudgetData::with('task')
            ->where('enquiry_task_id', $budgetTaskId)
            ->first();

        // Normal on a task that has never been priced — there is nothing to
        // project yet, and the first save will raise this again.
        if (! $budget) {
            Log::info('Budget change announced with no budget data to project', [
                'budget_task_id' => $budgetTaskId,
            ]);

            return 'no budget data to project yet';
        }

        // The actor rides in the payload: a retry or a sweep runs as somebody
        // else, or as nobody — see BudgetLinesChanged.
        $result = $this->projector->project($budget, $payload['actor_id'] ?? null);

        Log::info('Projected budget lines', ['budget_task_id' => $budgetTaskId, ...$result]);

        return sprintf('projected %d, retired %d, adopted %d', $result['projected'], $result['retired'], $result['adopted']);
    }

}
