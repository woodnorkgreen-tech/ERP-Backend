<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCompleted
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public int $userId;

    public function __construct(Task $task, int $userId)
    {
        $this->task = $task;
        $this->userId = $userId;
    }
}