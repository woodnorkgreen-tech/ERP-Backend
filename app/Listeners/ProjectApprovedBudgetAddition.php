<?php

namespace App\Listeners;

use App\Events\BudgetAdditionApproved;
use App\Modules\Finance\CostCollector\Services\BudgetRevisionProjector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Turns an authorised budget addition into planned cost lines.
 *
 * Queued like every other cost producer, and safe to replay: the projector
 * refuses anything not approved, and `postPlanned` converges by source key rather
 * than inserting again.
 *
 * Failures are logged rather than swallowed. A revision that does not reach the
 * cost account leaves the project spending against a stale ceiling, which is the
 * exact failure this listener exists to prevent — so it has to be visible.
 */
class ProjectApprovedBudgetAddition implements ShouldQueue
{
    public function __construct(private BudgetRevisionProjector $projector) {}

    public function handle(BudgetAdditionApproved $event): void
    {
        $result = $this->projector->project($event->addition);

        Log::info('Budget addition projected into the cost account', [
            'budget_addition_id' => $event->addition->id,
            'projected' => $result['projected'],
            'skipped' => $result['skipped'],
        ]);
    }
}
