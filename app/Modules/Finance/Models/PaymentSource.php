<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSource extends Model
{
    protected $table = 'payment_sources';

    protected $fillable = [
        'code',
        'name',
        'type',
        'gl_account_id',
        'custodian_user_id',
        'float_limit',
        'currency',
        'is_active',
        'can_make_payment',
    ];

    protected $casts = [
        'float_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'can_make_payment' => 'boolean',
    ];

    /**
     * A payable can never be flagged payment-capable, whatever wrote the row.
     *
     * `can_make_payment` replaced the old `type != 'payable'` check with a
     * stored flag, which means it can drift from `type` unless something
     * enforces the pairing on every write — a seeder, a factory, or a future
     * admin edit that doesn't know the rule. Drifting it open reopens the
     * exact wash-entry defect already fixed once: picking a liability account
     * as the paying account settles what WNG owes by crediting the same
     * control account it owes, while still minting a Payment that claims cash
     * moved. Enforcing it here, not just in the controller's request
     * validation, means no write path can bypass it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $source) {
            if ($source->type === 'payable') {
                $source->can_make_payment = false;
            }
        });
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    /**
     * Accounts from which money can actually leave.
     *
     * A payable is an obligation being settled, never the other side of the
     * cash movement. Keep this rule on the aggregate so every payment rail can
     * share it instead of maintaining UI/controller exclusion lists.
     */
    public function scopeUsableForPayment(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('type', '!=', 'payable')
            ->whereNotNull('gl_account_id');
    }

    /**
     * Scope for payment-capable sources (replaces usableForPayment).
     * Uses explicit can_make_payment flag + active status.
     */
    public function scopePaymentCapable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('can_make_payment', true)
            ->whereNotNull('gl_account_id');
    }
}
