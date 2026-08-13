<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A withholding tax category and its rate, effective-dated.
 *
 * Same reasoning as {@see VatTreatment}: the brief names the WHT rate table as
 * something Finance must be able to edit before go-live, so the rate is data.
 *
 * `threshold_amount` is the floor below which nothing is withheld, and
 * `aggregate_monthly` records that the threshold is meant to be tested against
 * the month's payments to a supplier rather than a single one — the aggregation
 * itself needs a supplier-month view that does not exist yet, so today the
 * threshold is applied per payment and this column is what will drive it.
 */
class WhtCategory extends Model
{
    protected $table = 'wht_categories';

    protected $fillable = [
        'code', 'name', 'rate_percent', 'residency', 'threshold_amount',
        'aggregate_monthly', 'gl_account_id', 'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'rate_percent' => 'decimal:3',
        'threshold_amount' => 'decimal:2',
        'aggregate_monthly' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * The liability withheld tax is credited to, and later cleared by the
     * remittance to KRA. Null on the zero-rate `NONE` category, which withholds
     * nothing and therefore posts nothing.
     */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }
}
