<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssigned
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public array $assignments;
    public int $assignerId;

    public function __construct(Task $task, array $assignments, int $assignerId)
    {
        $this->task = $task;
        $this->assignments = $assignments;
        $this->assignerId = $assignerId;
    }
}