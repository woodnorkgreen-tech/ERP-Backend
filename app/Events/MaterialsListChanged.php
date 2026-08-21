<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A project's materials list has changed.
 *
 * Raised on every save, not only on approval. Departmental sign-off is recorded
 * on the materials task, but it no longer decides who may see the list: holding
 * the budget back until two people re-approved meant one added line stalled the
 * whole downstream chain, and the desk and the budget drifted from the list they
 * are supposed to mirror.
 *
 * Announced rather than acted on, for the same reason every cost event in this
 * system is: the materials task states what the list now says, and whoever
 * depends on that decides what it means for them. The materials controller used
 * to reach directly into the budget's JSON and reconcile it by hand — 316 lines
 * of index-building inside a controller, a second implementation of a merge the
 * budget service already owned.
 *
 * Carries the task id rather than the model: the listener is queued, and what it
 * needs is the current state of the list at the moment it runs, not a snapshot
 * serialised when the event was raised.
 */
class MaterialsListChanged
{
    use Dispatchable;

    public function __construct(public int $materialsTaskId) {}
}
