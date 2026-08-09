<?php

namespace App\Listeners;

use App\Events\EnquiryTaskCompleted;
use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects a budget into the project's cost account when its task completes.
 *
 * Before this, projection only ever ran from `php artisan finance:project-budgets`,
 * so a budget approved today did not reach its cost account until someone
 * remembered to run a command. The figure looked live and was a snapshot.
 *
 * QUEUED deliberately. Hooking a cost-ledger write into another module's write
 * path means a failure here could otherwise stop somebody completing their task
 * — and no cost-reporting concern is worth blocking the workflow it observes.
 * The lines land a moment later instead.
 */
class ProjectBudgetLinesOnTaskCompletion implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private BudgetProjector $projector) {}

    public function handle(EnquiryTaskCompleted $event): void
    {
        if ($event->taskType !== 'budget') {
            return;
        }

        $budget = TaskBudgetData::with('task')
            ->where('enquiry_task_id', $event->taskId)
            ->first();

        // Completion is already gated on a priced budget existing, so this is a
        // race or a manual status change rather than a normal path.
        if (! $budget) {
            Log::info('Budget task completed with no budget data to project', ['task_id' => $event->taskId]);

            return;
        }

        $result = $this->projector->project($budget);

        Log::info('Projected budget lines on task completion', [
            'task_id' => $event->taskId,
            'enquiry_id' => $event->enquiryId,
            ...$result,
        ]);
    }

    /**
     * A budget that could not be projected must be visible, not silent — the
     * cost account would otherwise under-report with nothing to explain it.
     */
    public function failed(EnquiryTaskCompleted $event, Throwable $e): void
    {
        Log::error('Failed to project budget lines after task completion', [
            'task_id' => $event->taskId,
            'enquiry_id' => $event->enquiryId,
            'error' => $e->getMessage(),
        ]);
    }
}
