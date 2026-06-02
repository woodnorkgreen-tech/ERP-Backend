<?php

namespace App\Events\Stores;

use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\BoardWorkflowTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BoardRequestFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BoardRequest      $boardRequest,
        public readonly Collection        $issuedBoards,   // Collection<Board>
        public readonly BoardWorkflowTask $workflowTask,   // the dispatch task created for Logistics
    ) {}
}
