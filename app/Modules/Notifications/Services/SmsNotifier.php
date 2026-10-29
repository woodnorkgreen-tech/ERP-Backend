<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Logistics\Services\SmsService;
use App\Modules\Notifications\Jobs\SendSmsNotificationJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SMS side of notifications. NotificationService calls shouldText() and
 * messageFor() for every notification it sends; other code (for example the
 * material list approval) can call toUsers() directly.
 */
class SmsNotifier
{
    public static function enabled(): bool
    {
        return (bool) config('sms_notifications.enabled', true) && SmsService::enabled();
    }

    /** Should a notification of this module/type also be texted? */
    public static function shouldText(string $module, string $type): bool
    {
        if (!self::enabled()) {
            return false;
        }

        if (in_array(strtolower($module), array_map('strtolower', (array) config('sms_notifications.exclude_modules', [])), true)) {
            return false;
        }

        return !in_array($type, (array) config('sms_notifications.exclude_types', []), true);
    }

    public const OPEN_APP = ' Open the ERP or the Woodnork Green app for details.';

    /**
     * The text for one person for a notification payload. Hand-written wording
     * where we have it (leave); sensitive types say what it is from the title only
     * (no amounts or case details); everything else is "Hello <first name>, <the
     * in-app message>". Reference codes (#14, PV-0012) are always removed.
     */
    public static function messageFor(array $payload, ?User $recipient = null): string
    {
        $type  = (string) ($payload['type'] ?? '');
        $first = SmsTemplates::firstName($recipient?->name);
        $max   = (int) config('sms_notifications.max_length', 300);

        $custom = SmsTemplates::compose($payload, $recipient);
        if ($custom !== null) {
            return mb_strimwidth($custom, 0, $max, '...');
        }

        $title   = trim((string) ($payload['title'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));

        $sensitive = false;
        foreach ((array) config('sms_notifications.generic_type_patterns', []) as $pattern) {
            if ($pattern !== '' && str_contains($type, $pattern)) {
                $sensitive = true;
                break;
            }
        }

        // Sensitive types say what it is (the title) and nothing else.
        $text = $sensitive || $message === '' ? ($title !== '' ? $title : $message) : $message;
        $text = rtrim(self::withoutCodes($text), '.');

        if ($text === '') {
            $text = 'You have a new notification';
        }

        return mb_strimwidth("Hello {$first}, {$text}." . self::OPEN_APP, 0, $max, '...');
    }

    /** Plain words only: no HTML, no reference codes such as #14, (#14), PV-0012, (WNG-118). */
    public static function withoutCodes(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s*\((?:#|No\.?\s*)?[A-Za-z]{0,6}-?\d[\w\-]*\)/u', '', $text);
        $text = preg_replace('/\s*#\s*[\w\-]+/u', '', $text);
        $text = preg_replace('/\b[A-Z]{2,6}-\d[\w\-]*\b/u', '', $text);
        $text = preg_replace('/\s+([,.;:])/u', '$1', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Active users of a department (by name) and/or holding any of the roles.
     * Department leads are included (pass $excludeLeads = true to leave them out).
     *
     * @param  string[]  $departmentKeywords  matched anywhere in the department name
     * @param  string[]  $roles
     * @return int[]
     */
    public static function departmentUserIds(array $departmentKeywords, array $roles = [], ?bool $excludeLeads = null): array
    {
        $excludeLeads ??= (bool) config('sms_notifications.exclude_department_leads', false);

        $users = User::query()
            ->active()
            ->where(function ($q) use ($departmentKeywords, $roles) {
                $q->whereHas('department', function ($d) use ($departmentKeywords) {
                    $d->where(function ($w) use ($departmentKeywords) {
                        foreach ($departmentKeywords as $keyword) {
                            $w->orWhere('name', 'like', '%' . $keyword . '%');
                        }
                    });
                });
                if ($roles !== []) {
                    $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', $roles));
                }
            })
            ->get();

        if ($excludeLeads) {
            $users = $users->reject(fn (User $u) => $u->isDeptLead());
        }

        return $users->pluck('id')->all();
    }

    /**
     * Text these users (User models or ids) the given message. Write "{name}" in the
     * message where the person's first name should go; each person gets their own.
     *
     * @param  iterable<int|User>  $users
     */
    public static function toUsers(iterable $users, string $message): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $ids = collect($users)
                ->map(fn ($u) => $u instanceof User ? $u->id : $u)
                ->filter()
                ->unique()
                ->values();

            foreach ($ids as $id) {
                SendSmsNotificationJob::dispatch((int) $id, $message);
            }
        } catch (Throwable $e) {
            Log::warning('Could not queue notification SMS', ['error' => $e->getMessage()]);
        }
    }
}
