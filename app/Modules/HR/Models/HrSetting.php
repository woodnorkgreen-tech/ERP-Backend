<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Simple key/value HR policy settings that HR can change from the app.
 */
class HrSetting extends Model
{
    protected $table = 'hr_settings';

    protected $guarded = ['id'];

    public const ADVANCE_CAP_ENABLED = 'salary_advance_cap_enabled';
    public const ADVANCE_CAP_PERCENT = 'salary_advance_cap_percent';

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->where('key', $key)->value('value');

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_by' => $userId],
        );
    }

    /**
     * The salary advance cap as it stands now.
     *
     * @return array{enabled: bool, percent: float}
     */
    public static function advanceCap(): array
    {
        $percent = static::get(self::ADVANCE_CAP_PERCENT, 30);

        return [
            'enabled' => filter_var(static::get(self::ADVANCE_CAP_ENABLED, false), FILTER_VALIDATE_BOOLEAN),
            'percent' => is_numeric($percent) ? (float) $percent : 30.0,
        ];
    }
}
