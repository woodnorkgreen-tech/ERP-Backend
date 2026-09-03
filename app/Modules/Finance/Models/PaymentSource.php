<?php

namespace App\Modules\Finance\Models;

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
}
