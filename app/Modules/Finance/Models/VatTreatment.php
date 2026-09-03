<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A VAT rate and its recoverability, effective-dated.
 *
 * The table was seeded before anything read it. Rates are rows rather than
 * constants because the brief requires Finance to change them without a deploy —
 * so nothing here may hardcode 16%.
 *
 * Recoverability is not a property of the rate alone: standard-rated input is
 * only claimable against an eTIMS-valid invoice and inside the claim window,
 * which is why `is_recoverable`, `requires_etims` and `claim_window_months` are
 * separate columns rather than one flag.
 */
class VatTreatment extends Model
{
    protected $table = 'vat_treatments';

    protected $fillable = [
        'code', 'name', 'rate_percent', 'is_recoverable', 'requires_etims',
        'claim_window_months', 'gl_account_id', 'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'rate_percent' => 'decimal:3',
        'is_recoverable' => 'boolean',
        'requires_etims' => 'boolean',
        'claim_window_months' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Where recoverable input VAT lands. Null means the tax is not separately
     * recoverable and stays inside project cost — the exempt, out-of-scope and
     * explicitly non-recoverable treatments.
     */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    /**
     * The row in force on a given date. A rate change is a new row with its own
     * effective range, so a cost posted last year keeps last year's rate.
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }
}
