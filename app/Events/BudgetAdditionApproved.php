<?php

namespace App\Events;

use App\Models\BudgetAddition;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An addition to a project's budget has been authorised.
 *
 * Announced rather than acted on directly, for the same reason every other cost
 * event in this system is: the approving module states what happened to the
 * budget, and Finance decides what that means for the cost account. Projects
 * does not know that planned cost lines exist, and should not have to.
 */
class BudgetAdditionApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public BudgetAddition $addition) {}
}
