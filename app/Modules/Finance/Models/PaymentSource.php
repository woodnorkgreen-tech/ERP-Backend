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
    ];

    protected $casts = [
        'float_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

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
}
