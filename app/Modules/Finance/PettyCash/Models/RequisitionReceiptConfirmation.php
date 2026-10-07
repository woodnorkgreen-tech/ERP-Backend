<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Report 75R-B: a receiver's confirmation that one Payment reached them. */
class RequisitionReceiptConfirmation extends Model
{
    public const BY_RECEIVER = 'receiver';
    public const ON_BEHALF = 'on_behalf';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'confirmed_at' => 'datetime', 'invalidated_at' => 'datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
