<?php

namespace App\Modules\UniversalTask\Events;

use App\Modules\UniversalTask\Models\TaskIssue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskIssueLogged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public TaskIssue $issue;

    /**
     * Create a new event instance.
     */
    public function __construct(TaskIssue $issue)
    {
        $this->issue = $issue;
    }
}
