<?php

namespace App\Listeners;

use App\Events\FinanceReleased;
use App\Modules\Projects\Actions\ActivateProjectAction;
use Illuminate\Support\Facades\Log;

class ActivateProjectAfterFinance
{
    protected $activateProjectAction;

    public function __construct(ActivateProjectAction $activateProjectAction)
    {
        $this->activateProjectAction = $activateProjectAction;
    }

    /**
     * Handle the event.
     */
    public function handle(FinanceReleased $event): void
    {
        $enquiry = $event->enquiry;
        $user = $event->releasedBy;

        try {
            // Activate the project now that finance has released it
            $this->activateProjectAction->execute($enquiry, $user);
        } catch (\Exception $e) {
            Log::error("Failed to activate project after finance release: " . $e->getMessage());
        }
    }
}
