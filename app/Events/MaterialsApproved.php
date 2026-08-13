<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A project's materials list has been approved.
 *
 * Announced rather than acted on, for the same reason every cost event in this
 * system is: the materials task states what was approved, and whoever depends on
 * that decides what it means for them. The materials controller used to reach
 * directly into the budget's JSON and reconcile it by hand — 316 lines of
 * index-building inside a controller, a second implementation of a merge the
 * budget service already owned.
 *
 * Carries the task id rather than the model: the listener is queued, and what it
 * needs is the current state of the approved list at the moment it runs, not a
 * snapshot serialised when the event was raised.
 */
class MaterialsApproved
{
    use Dispatchable;

    public function __construct(public int $materialsTaskId) {}
}
