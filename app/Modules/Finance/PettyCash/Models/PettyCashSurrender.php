<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Report 75R-B: one receiver's surrender of money received under a requisition.
 *
 * The header the surrender did not have while a requisition could only be paid
 * once. Its lines are the same PettyCashSurrenderItem rows, each now tied to the
 * requisition line it accounts for. A receiver may surrender in stages; each
 * stage is one of these and is reconciled on its own.
 */
class PettyCashSurrender extends Model
{
    public const SUBMITTED = 'submitted';
    public const RETURNED = 'returned';
    public const RECONCILED = 'reconciled';
    public const REVERSED = 'reversed';

    /** Statuses whose amounts hold a claim on the receiver's balance. */
    public const LIVE = [self::SUBMITTED, self::RECONCILED];

    protected $guarded = ['id'];

    protected $casts = [
        'spent_amount' => 'decimal:2', 'returned_amount' => 'decimal:2', 'overspend_amount' => 'decimal:2',
        'submitted_at' => 'datetime', 'returned_at' => 'datetime', 'reconciled_at' => 'datetime', 'reversed_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    /** Current items only; a reversed stage keeps its retired items for history. */
    public function items(): HasMany
    {
        return $this->hasMany(PettyCashSurrenderItem::class, 'surrender_id')->whereNull('superseded_at');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(PettyCashSurrenderItem::class, 'surrender_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PettyCashSurrenderAllocation::class, 'surrender_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
