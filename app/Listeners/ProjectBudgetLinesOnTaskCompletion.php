<?php

namespace App\Listeners;

use App\Events\BudgetLinesChanged;
use App\Events\EnquiryTaskCompleted;

/**
 * Completing a budget task is one of the moments its figures become final, so
 * it re-announces them.
 *
 * The projection itself lives in ProjectBudgetLines, reached through
 * BudgetLinesChanged, because budget *writes* announce the same thing and two
 * copies of a ledger-write would eventually disagree. This is kept as a
 * separate trigger because completion does not require a write: a budget priced
 * before this wiring existed reaches its cost account when its task is closed.
 *
 * Not queued — it only raises an event, and the listener behind it is queued.
 */
class ProjectBudgetLinesOnTaskCompletion
{
    public function handle(EnquiryTaskCompleted $event): void
    {
        if ($event->taskType !== 'budget') {
            return;
        }

        BudgetLinesChanged::dispatch($event->taskId);
    }
}
