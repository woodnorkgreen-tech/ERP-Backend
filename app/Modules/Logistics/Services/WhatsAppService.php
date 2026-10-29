<?php

namespace App\Modules\Logistics\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends WhatsApp messages through Meta's WhatsApp Cloud API, using the
 * credentials from the wCRM dashboard (WABA credentials page). The token
 * lives in .env only; the apps never talk to WhatsApp, they just cause the
 * backend to.
 *
 * Business-started messages (a trip being assigned) must use a template that
 * Meta has approved. Plain text only works inside 24 hours after the person
 * last messaged the business number, which makes it handy for a first test.
 */
class WhatsAppService
{
    public static function enabled(): bool
    {
        return (bool) config('logistics.whatsapp.enabled')
            && config('logistics.whatsapp.token') !== ''
            && config('logistics.whatsapp.phone_number_id') !== '';
    }

    /** Template parameters may not be empty or contain line breaks / tabs / long runs of spaces. */
    public static function cleanParam(?string $value, int $max = 300): string
    {
        $v = preg_replace('/[\r\n\t]+/', ' ', (string) $value);
        $v = preg_replace('/ {2,}/', ' ', $v);
        $v = trim($v);
        if ($v === '') {
            $v = '-';
        }
        return mb_substr($v, 0, $max);
    }

    private static function endpoint(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('logistics.whatsapp.api_version'),
            config('logistics.whatsapp.phone_number_id')
        );
    }

    /** @return array{ok: bool, error: ?string} */
    private static function post(array $payload, string $mobile): array
    {
        try {
            $response = Http::withToken(config('logistics.whatsapp.token'))
                ->acceptJson()
                ->timeout(20)
                ->post(self::endpoint(), $payload);

            $id = $response->json('messages.0.id');
            if ($response->successful() && $id) {
                return ['ok' => true, 'error' => null];
            }

            $err = $response->json('error') ?? [];
            $msg = trim(($err['message'] ?? $response->body()) . ' (code ' . ($err['code'] ?? $response->status()) . ')');
            Log::warning('WhatsApp failed', ['mobile' => $mobile, 'status' => $response->status(), 'error' => $err ?: $response->body()]);
            return ['ok' => false, 'error' => $msg];
        } catch (Throwable $e) {
            Log::warning('WhatsApp error', ['mobile' => $mobile, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The one flexible template: "Hello {{1}}, {{2}}. Open the ERP app for more details."
     * {{1}} is the person's name and {{2}} is the whole message from the system.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function sendNotice(?string $phone, ?string $name, string $message): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'WhatsApp is not enabled'];
        }
        $mobile = SmsService::normalizePhone($phone);
        if (!$mobile) {
            return ['ok' => false, 'error' => 'No valid phone number'];
        }
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');
        $body  = rtrim(self::cleanParam($message, 900), '. ');

        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => $mobile,
            'type'              => 'template',
            'template'          => [
                'name'       => config('logistics.whatsapp.templates.notice'),
                'language'   => ['code' => config('logistics.whatsapp.template_language')],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => self::cleanParam($first !== '' ? $first : 'there', 60)],
                        ['type' => 'text', 'text' => $body !== '' ? $body : '-'],
                    ],
                ]],
            ],
        ], $mobile);
    }

    /**
     * Send an approved template. $params fill {{1}}, {{2}}… in order.
     *
     * @param  string[]  $params
     * @return array{ok: bool, error: ?string}
     */
    public static function sendTemplate(?string $phone, string $template, array $params): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'WhatsApp is not enabled'];
        }
        $mobile = SmsService::normalizePhone($phone);
        if (!$mobile) {
            Log::info('WhatsApp skipped: no valid phone number', ['given' => $phone]);
            return ['ok' => false, 'error' => 'No valid phone number'];
        }

        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => $mobile,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => config('logistics.whatsapp.template_language')],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => self::cleanParam($p)], array_values($params)),
                ]],
            ],
        ], $mobile);
    }

    /**
     * Plain text. Only delivered when the person messaged the business number
     * in the last 24 hours — use it to test the token and IDs before the
     * templates are approved.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function sendText(?string $phone, string $text): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'WhatsApp is not enabled'];
        }
        $mobile = SmsService::normalizePhone($phone);
        if (!$mobile) {
            return ['ok' => false, 'error' => 'No valid phone number'];
        }

        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => $mobile,
            'type'              => 'text',
            'text'              => ['body' => mb_substr(trim($text), 0, 1000)],
        ], $mobile);
    }
}
