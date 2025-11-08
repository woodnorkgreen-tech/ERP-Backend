<?php

namespace App\Events;

use App\Models\ProjectEnquiry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EnquiryCreated
{
    use Dispatchable, SerializesModels;

    public $enquiry;

    public function __construct(ProjectEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }
}
