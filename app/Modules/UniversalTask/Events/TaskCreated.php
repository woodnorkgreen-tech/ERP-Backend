<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCreated
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public int $creatorId;

    public function __construct(Task $task, int $creatorId)
    {
        $this->task = $task;
        $this->creatorId = $creatorId;
    }
}