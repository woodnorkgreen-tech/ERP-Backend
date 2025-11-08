<?php

namespace App\Handlers;

use App\Commands\CreateEnquiryCommand;
use App\Models\ProjectEnquiry;
use App\Services\EnquiryService;

class CreateEnquiryHandler
{
    protected $enquiryService;

    public function __construct(EnquiryService $enquiryService)
    {
        $this->enquiryService = $enquiryService;
    }

    public function handle(CreateEnquiryCommand $command): ProjectEnquiry
    {
        return $this->enquiryService->createEnquiry($command->data);
    }
}
