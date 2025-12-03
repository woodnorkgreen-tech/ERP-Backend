<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskDueSoon
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public int $hoursUntilDue;

    public function __construct(Task $task, int $hoursUntilDue)
    {
        $this->task = $task;
        $this->hoursUntilDue = $hoursUntilDue;
    }
}