<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendVoucherReview extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['snapshot' => 'array'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(SpendVoucher::class, 'spend_voucher_id');
    }
}
