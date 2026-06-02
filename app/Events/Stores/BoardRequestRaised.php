<?php

namespace App\Events\Stores;

use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\BoardWorkflowTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BoardRequestRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BoardRequest      $boardRequest,
        public readonly BoardWorkflowTask $workflowTask,
    ) {}
}
