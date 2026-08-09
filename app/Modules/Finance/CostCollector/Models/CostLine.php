<?php

namespace App\Modules\Finance\CostCollector\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cost fact against a project.
 *
 * Budget and spend share this table, separated by `nature`, so variance is a
 * GROUP BY rather than a reconciliation. A row counts toward project cost when
 * `status = verified` — that is the only rule.
 *
 * Append-only. Nothing here is edited after verification; corrections are
 * reversals, the same discipline the petty-cash and overtime ledgers already
 * follow.
 */
class CostLine extends Model
{
    public const NATURE_PLANNED = 'planned';
    public const NATURE_COMMITTED = 'committed';
    public const NATURE_ACCRUED = 'accrued';
    public const NATURE_ACTUAL = 'actual';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_QUERIED = 'queried';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';

    /**
     * Legal transitions. Held here rather than scattered through the service so
     * that "can this move?" has exactly one answer in the codebase.
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_SUBMITTED, self::STATUS_REJECTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_QUERIED, self::STATUS_REJECTED],
        self::STATUS_QUERIED   => [self::STATUS_SUBMITTED, self::STATUS_REJECTED],
        self::STATUS_VERIFIED  => [self::STATUS_REVERSED],
        self::STATUS_REJECTED  => [],
        self::STATUS_REVERSED  => [],
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'base_net_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'quantity' => 'decimal:3',
        'unit_rate' => 'decimal:4',
        'budget_remaining_before' => 'decimal:2',
        'budget_remaining_after' => 'decimal:2',
        'details' => 'array',
        'evidence' => 'array',
        'capture_meta' => 'array',
        'incurred_at' => 'datetime',
        'posting_date' => 'date',
        'verified_at' => 'datetime',
        'posted_at' => 'datetime',
        'cos_transferred_at' => 'datetime',
    ];

    public function expenseCode(): BelongsTo
    {
        return $this->belongsTo(ExpenseCode::class);
    }

    /** The planned line this fulfils. Null means unbudgeted spend. */
    public function consumesLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'consumes_line_id');
    }

    /** Verified actuals and commitments drawing down this planned line. */
    public function consumers(): HasMany
    {
        return $this->hasMany(self::class, 'consumes_line_id');
    }

    public function scopeCounting($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeOfNature($query, string ...$natures)
    {
        return $query->whereIn('nature', $natures);
    }

    public function scopeForProject($query, ?int $projectId, ?int $enquiryId = null, ?string $jobNumber = null)
    {
        return $query->where(function ($q) use ($projectId, $enquiryId, $jobNumber) {
            if ($projectId) {
                $q->orWhere('project_id', $projectId);
            }
            if ($enquiryId) {
                $q->orWhere('project_enquiry_id', $enquiryId);
            }
            if (filled($jobNumber)) {
                $q->orWhere('job_number', $jobNumber);
            }
        });
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Unbudgeted spend — the state the Unbudgeted panel is built on. */
    public function isUnbudgeted(): bool
    {
        return $this->nature !== self::NATURE_PLANNED && $this->consumes_line_id === null;
    }
}
