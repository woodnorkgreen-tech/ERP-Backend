<?php

namespace App\Modules\Logistics\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends SMS through TextSMS. The API key lives in .env only; the app never
 * talks to TextSMS, it just causes the backend to.
 */
class SmsService
{
    /** Turn 0712…, +254712… or 254 712… into 254712XXXXXX. Null if it isn't a Kenyan mobile. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '254' . substr($digits, 1);
        } elseif (preg_match('/^[17]\d{8}$/', $digits)) {
            $digits = '254' . $digits;
        }
        return preg_match('/^254[17]\d{8}$/', $digits) ? $digits : null;
    }

    public static function enabled(): bool
    {
        return (bool) config('logistics.sms.enabled')
            && config('logistics.sms.api_key') !== ''
            && config('logistics.sms.partner_id') !== '';
    }

    public static function send(?string $phone, string $message): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $mobile = self::normalizePhone($phone);
        if (!$mobile) {
            Log::info('SMS skipped: no valid phone number', ['given' => $phone]);
            return false;
        }

        try {
            // Same form-encoded request the Remotax sender uses.
            $response = Http::asForm()->timeout(15)->post(config('logistics.sms.url'), [
                'apikey'    => config('logistics.sms.api_key'),
                'partnerID' => config('logistics.sms.partner_id'),
                'message'   => mb_substr(trim($message), 0, 459),
                'shortcode' => config('logistics.sms.shortcode'),
                'mobile'    => $mobile,
                'pass_type' => 'plain',
            ]);

            $row  = $response->json('responses.0') ?? [];
            // TextSMS spells this key "respose-code" in some replies.
            $code = $row['respose-code'] ?? $row['response-code'] ?? null;
            $ok   = $response->successful() && (int) $code === 200;

            if (!$ok) {
                Log::warning('SMS failed', ['mobile' => $mobile, 'status' => $response->status(), 'body' => $response->body()]);
            }
            return $ok;
        } catch (Throwable $e) {
            Log::warning('SMS error', ['mobile' => $mobile, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
