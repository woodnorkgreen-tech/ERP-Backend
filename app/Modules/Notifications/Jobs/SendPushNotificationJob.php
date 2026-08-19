<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\UserDeviceToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
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
        $playerIds = UserDeviceToken::query()
            ->where('user_id', $this->userId)
            ->pluck('player_id')
            ->filter()
            ->values();

        if ($playerIds->isEmpty()) {
            return;
        }

        $appId = config('services.onesignal.app_id') ?: config('onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key') ?: config('onesignal.rest_api_key');

        if (!$appId || !$apiKey) {
            Log::warning('OneSignal push skipped because credentials are not configured.');
            return;
        }

        $response = Http::withHeaders([
           'Authorization' => 'Key ' . $apiKey,
            'Content-Type' => 'application/json',
      ])->post('https://onesignal.com/api/v1/notifications', [
            'app_id' => $appId,
            'include_player_ids' => $playerIds->all(),
            'headings' => ['en' => $this->payload['title']],
            'contents' => ['en' => $this->payload['message']],
            'data' => $this->payload,
        ]);

        if ($response->failed()) {
            Log::warning('OneSignal push failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
