<?php

namespace App\Modules\Notifications\Jobs;

use App\Models\User;
use App\Modules\Notifications\Mail\AppNotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendMailNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $userId, public array $payload)
    {
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->send(new AppNotificationMail($this->payload));
    }
}
