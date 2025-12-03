<?php

namespace App\Modules\UniversalTask\Events;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserMentioned
{
    use Dispatchable, SerializesModels;

    public Task $task;
    public User $mentionedUser;
    public User $mentioner;
    public string $comment;

    public function __construct(Task $task, User $mentionedUser, User $mentioner, string $comment)
    {
        $this->task = $task;
        $this->mentionedUser = $mentionedUser;
        $this->mentioner = $mentioner;
        $this->comment = $comment;
    }
}