<?php

namespace App\Events\Stores;

use App\Models\User;
use App\Modules\ProcurementStores\Models\BoardWorkflowTask;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BoardsDispatchedToStation
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string            $jobRef,
        public readonly Collection        $boards,         // Collection<Board> now At Station
        public readonly User              $dispatchedBy,
        public readonly BoardWorkflowTask $dispatchTask,   // the Logistics task now marked done
    ) {}
}
