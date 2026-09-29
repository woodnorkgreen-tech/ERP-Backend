<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Modules\Finance\Models\FinanceAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PettyCashCashCount extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['system_balance' => 'decimal:2', 'physical_cash' => 'decimal:2', 'outstanding_advances' => 'decimal:2',
        'supported_adjustments' => 'decimal:2', 'variance' => 'decimal:2', 'reviewed_at' => 'datetime', 'components' => 'array'];
    public function attachments(): MorphMany { return $this->morphMany(FinanceAttachment::class, 'source'); }
}
