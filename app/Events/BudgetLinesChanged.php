<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A project's budget figures have changed.
 *
 * The cost account measures every shilling of spend against the planned lines
 * projected from this budget, and those lines only ever refreshed when the
 * budget *task* was completed. Once the budget started following the materials
 * list on every save, that left the account grading actual spend against a
 * budget that could be several revisions old — and silently, because a stale
 * planned figure looks exactly like a current one.
 *
 * Announced rather than acted on, like every other cost event here: the budget
 * states what it now says, and the cost ledger decides what that means for it.
 *
 * Carries the task id, not the model: the listener is queued and needs the
 * budget as it stands when it runs, not a snapshot from when it was raised.
 */
class BudgetLinesChanged
{
    use Dispatchable;

    public function __construct(public int $budgetTaskId) {}
}
