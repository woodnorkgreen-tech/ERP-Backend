<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public string $oldStatus;
    public string $newStatus;
    public int $userId;

    public function __construct(Task $task, string $oldStatus, string $newStatus, int $userId)
    {
        $this->task = $task;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->userId = $userId;
    }
}