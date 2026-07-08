<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Mail\AppNotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendMailToAddressNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $email, public array $payload)
    {
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new AppNotificationMail($this->payload));
    }
}
