<?php

namespace App\Listeners;

use App\Events\BudgetLinesChanged;
use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a project's planned cost lines equal to its budget.
 *
 * Idempotent by construction: the projector matches each line on
 * (source type, source id, source ref), supersedes what changed and retires
 * what the budget no longer contains, so a replayed job is harmless and a
 * double-dispatch cannot double-count a budget.
 *
 * QUEUED deliberately. This hooks a cost-ledger write onto another module's
 * write path, and no cost-reporting concern is worth blocking the workflow it
 * observes — a failure here must not stop somebody saving their budget. The
 * lines land a moment later instead.
 */
class ProjectBudgetLines implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private BudgetProjector $projector) {}

    public function handle(BudgetLinesChanged $event): void
    {
        $budget = TaskBudgetData::with('task')
            ->where('enquiry_task_id', $event->budgetTaskId)
            ->first();

        // Normal on a task that has never been priced — there is nothing to
        // project yet, and the first save will raise this again.
        if (! $budget) {
            Log::info('Budget change announced with no budget data to project', [
                'budget_task_id' => $event->budgetTaskId,
            ]);

            return;
        }

        Log::info('Projected budget lines', [
            'budget_task_id' => $event->budgetTaskId,
            ...$this->projector->project($budget),
        ]);
    }

    /**
     * A budget that could not be projected must be visible, not silent — the
     * cost account would otherwise under-report with nothing to explain it.
     */
    public function failed(BudgetLinesChanged $event, Throwable $e): void
    {
        Log::error('Failed to project budget lines', [
            'budget_task_id' => $event->budgetTaskId,
            'error' => $e->getMessage(),
        ]);
    }
}
