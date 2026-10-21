<?php

namespace App\Modules\Logistics\Jobs;

use App\Modules\Logistics\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public ?string $phone, public string $message)
    {
    }

    public function handle(): void
    {
        SmsService::send($this->phone, $this->message);
    }
}
