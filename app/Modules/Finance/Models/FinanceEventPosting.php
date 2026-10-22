<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost-chain posting owed to the books, and what became of it.
 *
 * See FinanceEventPoster. `posted` means the handler ran to the end — which
 * includes the ordinary "nothing to post" outcomes (office spend with no
 * project, a payment that is a supplier settlement); `outcome` says which.
 */
class FinanceEventPosting extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_POSTED = 'posted';
    public const STATUS_FAILED = 'failed';

    /** A posting should finish inside the request that raised it. */
    public const STALE_AFTER_MINUTES = 10;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'requested_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lastRetriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_retried_by');
    }

    /** Failed, or left unfinished by a request that died before running it. */
    public function scopeNeedingAttention($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_FAILED)
                ->orWhere(function ($stale) {
                    $stale->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES));
                });
        });
    }

    public function isStale(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && $this->updated_at?->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function needsAttention(): bool
    {
        return $this->status === self::STATUS_FAILED || $this->isStale();
    }
}
