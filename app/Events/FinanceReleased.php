<?php

namespace App\Events;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinanceReleased
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $enquiry;
    public $releasedBy;

    /**
     * Create a new event instance.
     */
    public function __construct(ProjectEnquiry $enquiry, User $releasedBy)
    {
        $this->enquiry = $enquiry;
        $this->releasedBy = $releasedBy;
    }
}
