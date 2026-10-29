<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\HR\Models\LeaveRequest;
use Throwable;

/**
 * Hand-written SMS wording for notification types where a plain copy of the
 * in-app text would be less useful (who asked, for when, who approved).
 * Returns null for any type it does not know, and the caller falls back to
 * "Hello <name>, <the in-app message>".
 */
class SmsTemplates
{
    public const CALL_TO_ACT = 'Please approve it on the ERP or the Woodnork Green app.';

    public static function compose(array $payload, ?User $recipient): ?string
    {
        $type = (string) ($payload['type'] ?? '');

        if (str_starts_with($type, 'leave_request_')) {
            try {
                return self::leave($type, $payload, $recipient);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public static function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'there';
    }

    private static function leave(string $type, array $payload, ?User $recipient): ?string
    {
        $data = (array) ($payload['data'] ?? []);
        $leave = !empty($data['leave_request_id'])
            ? LeaveRequest::query()->with(['employee.user', 'leaveType', 'approver', 'leadApprover'])->find($data['leave_request_id'])
            : null;

        $employee = $leave?->employee?->name ?: ($data['employee'] ?? null);
        $kind = $leave?->leaveType?->name ?: ($data['leave_type'] ?? null);
        $range = $data['date_range'] ?? $leave?->date_range_label ?? '';
        $days = $data['days_requested'] ?? $leave?->days_requested ?? null;

        if (!$employee) {
            return null;
        }

        // "Annual Leave" -> "Annual leave"; "Sick" -> "Sick leave"
        $kind = trim((string) $kind);
        $kind = $kind === '' ? 'leave' : (preg_match('/leave$/i', $kind) ? $kind : $kind . ' leave');
        $period = $range !== '' ? " for {$range}" : '';
        $length = '';
        if ($days !== null && (float) $days > 0) {
            $d = (float) $days;
            $length = ' (' . (floor($d) === $d ? (string) (int) $d : (string) $d) . ($d === 1.0 ? ' day)' : ' days)');
        }
        $first = self::firstName($recipient?->name);
        $isEmployee = $recipient && $leave?->employee?->user?->id === $recipient->id;

        $lead = $leave?->leadApprover?->name ?: $leave?->approver?->name;
        $decider = $leave?->approver?->name ?: $lead;
        $by = fn (?string $who) => $who ? " by {$who}" : '';
        $suffix = ' Open the ERP or the Woodnork Green app for details.';

        return match ($type) {
            // To the lead / HR who has to act.
            'leave_request_submitted' => "Hello {$first}, {$employee} requested {$kind}{$period}{$length}. " . self::CALL_TO_ACT,

            // To the employee, or to HR who must finish it.
            'leave_request_lead_approved' => $isEmployee
                ? "Hello {$first}, your {$kind}{$period} was approved{$by($lead)} and is now with HR for final approval."
                : "Hello {$first}, {$employee}'s {$kind}{$period} was approved{$by($lead)}. HR approval is needed. " . self::CALL_TO_ACT,
            'leave_request_lead_rejected' => "Hello {$first}, {$employee}'s {$kind}{$period} was rejected{$by($decider)}." . $suffix,

            // To the employee.
            'leave_request_approved' => "Hello {$first}, your {$kind}{$period} was approved{$by($decider)}." . $suffix,
            'leave_request_rejected' => "Hello {$first}, your {$kind}{$period} was rejected{$by($decider)}." . $suffix,
            'leave_request_cancelled' => "Hello {$first}, your {$kind}{$period} was cancelled." . $suffix,
            'leave_request_recalled' => "Hello {$first}, your {$kind}{$period} was recalled." . $suffix,

            default => null,
        };
    }
}
