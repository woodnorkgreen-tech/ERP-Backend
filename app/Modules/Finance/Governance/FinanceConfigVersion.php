<?php

namespace App\Modules\Finance\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one Finance configuration item (Report 75).
 *
 * A version is never edited once it has been decided. A change is a new version,
 * and the one it replaces keeps its value, its approver and the dates it governed.
 */
class FinanceConfigVersion extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const RETURNED = 'returned';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const APPROVED = 'approved';
    public const ACTIVE = 'active';

    /** Still a proposal: at most one of these per item. */
    public const OPEN = [self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::RETURNED, self::APPROVED];

    protected $table = 'finance_config_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'date',
        'in_force_from' => 'date',
        'in_force_to' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(FinanceConfigApproval::class, 'version_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * Where this version stands today, in the words the screens use.
     *
     * `active` in the table means "activated". Whether an activated version is
     * governing now, is waiting for its date, or has been replaced is a matter of
     * dates, so it is worked out here and never stored: a stored "superseded"
     * would need something to flip it at midnight.
     */
    public function state(?string $on = null): string
    {
        if ($this->status !== self::ACTIVE) {
            return $this->status;
        }
        $on ??= now()->toDateString();
        if ($this->in_force_from && $this->in_force_from->toDateString() > $on) {
            return 'scheduled';
        }
        if ($this->in_force_to && $this->in_force_to->toDateString() < $on) {
            return 'superseded';
        }

        return 'active';
    }
}
