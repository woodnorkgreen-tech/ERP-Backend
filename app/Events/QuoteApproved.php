<?php

namespace App\Events;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuoteApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $enquiry;
    public $approvedBy;

    /**
     * Create a new event instance.
     */
    public function __construct(ProjectEnquiry $enquiry, User $approvedBy)
    {
        $this->enquiry = $enquiry;
        $this->approvedBy = $approvedBy;
    }
}
