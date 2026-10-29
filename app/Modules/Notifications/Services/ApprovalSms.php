<?php

namespace App\Modules\Notifications\Services;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Texts the person who asked for something when it is approved, rejected,
 * returned or paid out. It listens to the request models themselves, so it
 * works for flows that have no in-app notification and does not touch any
 * controller. Nothing here changes an existing notification.
 *
 * To add another request type, add one entry to RULES.
 */
class ApprovalSms
{
    /**
     * model => [
     *   noun     what the person asked for ("payment voucher")
     *   users    columns holding the requester's user id
     *   employees columns holding the requester's employee id
     *   ref      column shown after the noun (or [relation, column] for a related record)
     *   ref_path a dotted path to the same kind of title (for example payload.payee_name)
     *   ref_prefix  word placed before it ("for")
     *   reason   column with the reason, added only to the outcomes in reason_for
     *   reason_for  outcomes that carry the reason
     *   watch    column => [new value => the words after "Your <noun> <ref> "]
     * ]
     */
    public const RULES = [
        \App\Modules\Finance\Models\SpendVoucher::class => [
            'noun' => 'payment voucher',
            'users' => ['requester_user_id'],
            'ref_path' => 'payee_name',
            'ref_prefix' => 'for',
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'rejected' => 'was rejected{by}'],
                'review_state' => ['returned_for_correction' => 'was returned for correction{by}'],
            ],
        ],
        \App\Modules\Finance\PettyCash\Models\PettyCashRequisition::class => [
            'noun' => 'petty cash request',
            'users' => ['user_id'],
            'ref_path' => 'purpose',
            'ref_prefix' => 'for',
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'rejected' => 'was rejected{by}', 'disbursed' => 'has been paid out{by}'],
            ],
        ],
        \App\Modules\Finance\PettyCash\Models\DirectDisbursementRequest::class => [
            'noun' => 'direct payment request',
            'users' => ['requested_by'],
            'ref_path' => 'payload.payee_name',
            'ref_prefix' => 'for',
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'rejected' => 'was rejected{by}'],
            ],
        ],
        \App\Modules\Finance\PettyCash\Models\PettyCashOfflineBatch::class => [
            'noun' => 'petty cash upload',
            'users' => ['uploaded_by'],
            'ref_path' => 'original_filename',
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'posted' => 'has been posted{by}', 'rejected' => 'was rejected{by}'],
            ],
        ],
        \App\Modules\Assets\Models\AssetHireRequest::class => [
            'noun' => 'asset request',
            'users' => ['requested_by', 'for_user_id'],
            'ref' => ['asset', 'name'],
            'ref_prefix' => 'for',
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'rejected' => 'was rejected{by}'],
            ],
        ],
        \App\Modules\HR\Models\Compensation::class => [
            'noun' => 'compensatory leave request',
            'employees' => ['employee_id'],
            'ref' => 'comp_date',
            'ref_prefix' => 'for',
            'reason' => 'notes',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => [
                    'under_review' => 'was approved{by} (supervisor stage) and is now with HR for final approval',
                    'approved' => 'was approved{by}',
                    'rejected' => 'was rejected{by}',
                ],
            ],
        ],
        \App\Modules\HR\Models\ProfileUpdateRequest::class => [
            'noun' => 'profile update request',
            'employees' => ['employee_id'],
            'reason' => 'rejection_reason',
            'reason_for' => ['was rejected{by}'],
            'watch' => [
                'status' => ['approved' => 'was approved{by}', 'rejected' => 'was rejected{by}'],
            ],
        ],
    ];

    public static function register(): void
    {
        foreach (array_keys(self::RULES) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $class::updated(function (Model $model) use ($class): void {
                self::handle($class, $model);
            });
        }
    }

    public static function handle(string $class, Model $model): void
    {
        try {
            if (!SmsNotifier::enabled()) {
                return;
            }

            $rule = self::RULES[$class];

            foreach ($rule['watch'] as $column => $outcomes) {
                if (!$model->wasChanged($column)) {
                    continue;
                }

                $outcome = $outcomes[(string) $model->getAttribute($column)] ?? null;
                if ($outcome === null) {
                    continue;
                }

                $recipients = self::recipients($rule, $model);
                if ($recipients === []) {
                    continue;
                }

                SmsNotifier::toUsers($recipients, self::message($rule, $model, $outcome));
            }
        } catch (Throwable $e) {
            Log::warning('Approval SMS failed', ['model' => $class, 'id' => $model->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /** User ids to text, leaving out whoever made the change (their own action). */
    private static function recipients(array $rule, Model $model): array
    {
        $ids = [];

        foreach ($rule['users'] ?? [] as $column) {
            $ids[] = $model->getAttribute($column);
        }

        $employeeIds = array_filter(array_map(fn ($c) => $model->getAttribute($c), $rule['employees'] ?? []));
        if ($employeeIds !== []) {
            foreach (Employee::query()->with('user')->whereIn('id', $employeeIds)->get() as $employee) {
                $ids[] = $employee->user?->id;
            }
        }

        $actor = Auth::id();

        return array_values(array_unique(array_filter($ids, fn ($id) => $id && (int) $id !== (int) $actor)));
    }

    private static function message(array $rule, Model $model, string $outcome): string
    {
        $actor = Auth::user()?->name;
        $by = $actor ? ' by ' . $actor : '';

        $text = 'Hello {name}, your ' . $rule['noun'];

        $ref = self::reference($rule, $model);
        if ($ref !== '') {
            $text .= ' ' . (!empty($rule['ref_prefix']) ? $rule['ref_prefix'] . ' ' : '') . $ref;
        }

        $text .= ' ' . str_replace('{by}', $by, $outcome) . '.';

        if (in_array($outcome, $rule['reason_for'] ?? [], true) && !empty($rule['reason'])) {
            $reason = trim(preg_replace('/\s+/', ' ', strip_tags((string) $model->getAttribute($rule['reason']))));
            if ($reason !== '') {
                $reason = mb_strimwidth($reason, 0, 120, '...');
                $text .= ' Reason: ' . $reason . (preg_match('/[.!?]$/', $reason) ? '' : '.');
            }
        }

        return $text . ' Open the ERP or the Woodnork Green app for details.';
    }

    /** A short, human title for the request (never a number or code). */
    private static function reference(array $rule, Model $model): string
    {
        if (!empty($rule['ref_path'])) {
            $value = data_get($model, $rule['ref_path']);
        } elseif (!empty($rule['ref'])) {
            $ref = $rule['ref'];
            $value = is_array($ref) ? data_get($model, $ref[0] . '.' . $ref[1]) : $model->getAttribute($ref);
        } else {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('j M Y');
        }

        $value = SmsNotifier::withoutCodes(trim((string) $value));

        return mb_strimwidth($value, 0, 60, '...');
    }
}
