<?php

namespace App\Modules\Notifications\Jobs;

use App\Models\User;
use App\Modules\Logistics\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Texts one user. The phone number is the one on their employee record.
 * A user without a number is skipped quietly; a failure never affects the
 * in-app notification, which has already been saved.
 */
class SendSmsNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $userId, public string $message)
    {
    }

    public function handle(): void
    {
        if (!SmsService::enabled()) {
            return;
        }

        try {
            $user = User::query()->with('employee')->find($this->userId);
            $phone = $user?->employee?->phone;

            if (!$phone) {
                return;
            }

            $first = trim(explode(' ', trim((string) $user->name))[0] ?? '');
            SmsService::send($phone, str_replace('{name}', $first !== '' ? $first : 'there', $this->message));
        } catch (Throwable $e) {
            Log::warning('Notification SMS failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
        }
    }
}
