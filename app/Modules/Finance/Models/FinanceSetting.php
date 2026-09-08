<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Finance-owned, effective-dated policy numbers.
 *
 * The table has been seeded since the cost-collector work but had no model, so
 * every value in it — the petty-cash cap, the margin thresholds, the input-VAT
 * claim window — was documentation rather than behaviour. The tax schedules are
 * the first code to read it, which is the point: a claim window is a KRA rule
 * that changes by Finance Act, and it must be editable by Finance without a
 * deployment.
 *
 * Effective-dated the same way the tax tables are, so a schedule rerun for a
 * historical period uses the window that applied then rather than today's.
 */
class FinanceSetting extends Model
{
    protected $table = 'finance_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * The value in force on a date, or the given default.
     *
     * Seeded values are stored as text and the literal string `null` appears in
     * the table for settings WNG's accountant has not yet decided (the
     * capitalisation threshold, for one). Both that and a genuinely absent row
     * mean "no policy set", and both fall back rather than returning the string.
     */
    public static function value(string $key, mixed $default = null, ?string $on = null): mixed
    {
        $on ??= now()->toDateString();

        $value = static::query()
            ->where('key', $key)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->value('value');

        return ($value === null || $value === '' || $value === 'null') ? $default : $value;
    }

    public static function integer(string $key, int $default, ?string $on = null): int
    {
        $value = static::value($key, null, $on);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The value in force, but only if an accountant has signed it off.
     *
     * `value()` deliberately answers regardless of approval: a claim window or a
     * warning threshold that is merely proposed is still better guidance than
     * nothing, and reporting it costs nobody anything.
     *
     * A setting that RELAXES a control cannot be read that way. The seeder is
     * explicit that rows arrive unapproved so a threshold is "visible as
     * unapproved rather than quietly enforced as though someone had agreed to
     * it" — and a purchase-approval limit nobody approved would do exactly
     * that, removing a human sign-off on the strength of a seeded default.
     *
     * So anything that lets the system decide instead of a person reads through
     * here, and an unapproved row is treated as no policy at all.
     */
    public static function approvedValue(string $key, mixed $default = null, ?string $on = null): mixed
    {
        $on ??= now()->toDateString();

        $row = static::query()
            ->where('key', $key)
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->first();

        $value = $row?->value;

        return ($value === null || $value === '' || $value === 'null') ? $default : $value;
    }
}
