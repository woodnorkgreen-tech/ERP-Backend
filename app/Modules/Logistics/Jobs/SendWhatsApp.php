<?php

namespace App\Modules\Logistics\Jobs;

use App\Modules\Logistics\Services\SmsService;
use App\Modules\Logistics\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one WhatsApp template message. If it fails, or if WHATSAPP_ALSO_SMS
 * is on, the same news goes out as an SMS so nobody misses a trip.
 */
class SendWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  string[]  $params
     */
    public function __construct(
        public ?string $phone,
        public string $template,
        public array $params,
        public ?string $smsFallback = null,
        public ?string $name = null
    ) {
    }

    public function handle(): void
    {
        // 'notice' = the single flexible template (name + whole message).
        $result = $this->template === 'notice'
            ? WhatsAppService::sendNotice($this->phone, $this->name, (string) $this->smsFallback)
            : WhatsAppService::sendTemplate($this->phone, $this->template, $this->params);

        $sendSms = !$result['ok'] || (bool) config('logistics.whatsapp.also_sms');
        if ($sendSms && $this->smsFallback !== null && SmsService::enabled()) {
            SmsService::send($this->phone, $this->smsFallback);
        }
    }
}
