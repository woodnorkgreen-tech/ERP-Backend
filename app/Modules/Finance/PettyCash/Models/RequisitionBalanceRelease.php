<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report 75R-B: approved money that it has been decided will not be paid.
 * The approved amount itself is never changed; this stands beside it.
 */
class RequisitionBalanceRelease extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'released_at' => 'datetime'];

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
