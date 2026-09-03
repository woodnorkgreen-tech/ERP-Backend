<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendVoucherAllocation extends Model
{
    protected $fillable = ['spend_voucher_id', 'cost_line_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(SpendVoucher::class, 'spend_voucher_id');
    }

    public function costLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'cost_line_id');
    }
}
