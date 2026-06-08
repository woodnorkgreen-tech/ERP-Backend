<?php

namespace App\Events\Stores;

use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardWorkflowTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OffcutRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Board             $offcut,
        public readonly BoardWorkflowTask $workflowTask,  // return-to-rack task for Stores
    ) {}
}
