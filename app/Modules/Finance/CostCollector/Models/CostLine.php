<?php

namespace App\Modules\Finance\CostCollector\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Every cost lands in the reporting month its date falls in.
     *
     * CostContextResolver already does this for the two capture paths, and
     * JournalPostingService refuses to post a line without a period — correctly,
     * because an unassigned line is one no month-end close can ever see. But
     * anything else that writes a cost line (a console backfill, a producer, a
     * test fixture) had to remember, and forgetting produced a row that looked
     * fine until the day it would not post.
     *
     * Resolved here from the line's own date so the rule holds for every writer.
     * A date no period covers still resolves to null and is still refused at
     * posting: that is a gap in Finance's calendar, not something to paper over.
     */
    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if ($line->accounting_period_id !== null) {
                return;
            }

            $line->accounting_period_id = AccountingPeriod::forDate(
                $line->incurred_at ?? $line->posting_date ?? now(),
            )?->id;
        });
    }

    public function expenseCode(): BelongsTo
    {
        return $this->belongsTo(ExpenseCode::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The project this cost belongs to.
     *
     * `CostAccountService` filtered on this relation before it existed, so the
     * project-status filter threw the moment anything passed it — which nothing
     * did, because the controller never forwarded a filter either.
     */
    public function projectEnquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function vatTreatment(): BelongsTo
    {
        return $this->belongsTo(VatTreatment::class);
    }

    public function whtCategory(): BelongsTo
    {
        return $this->belongsTo(WhtCategory::class);
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

    /**
     * Resolve every dimension a verifier needs to read, in the same query.
     *
     * Cost centre, activity, cause and payee type are reference tables with no
     * Eloquent model — deliberately, they are rows Finance adds rather than
     * classes anyone codes against. Correlated subselects give the names without
     * inventing four models, and without the N+1 that eager loading four
     * belongsTo relations across a 100-row queue would produce.
     *
     * The supplier name is guarded on `requires_supplier_record`: `payee_id` is
     * a bare unsigned integer whose meaning depends on the payee type, so
     * joining it straight to suppliers would put a supplier's name against an
     * employee who happens to share the id.
     */
    public function scopeWithReferenceNames($query)
    {
        $lookup = fn (string $table, string $column, string $localKey) => DB::table($table)
            ->select($column)
            ->whereColumn("{$table}.id", "cost_lines.{$localKey}")
            ->limit(1);

        return $query
            ->select('cost_lines.*')
            ->addSelect([
                'cost_centre_name' => $lookup('cost_centres', 'name', 'cost_centre_id'),
                'activity_name' => $lookup('activities', 'name', 'activity_id'),
                'cost_cause_name' => $lookup('cost_causes', 'name', 'cost_cause_id'),
                'cost_cause_is_exception' => $lookup('cost_causes', 'is_exception', 'cost_cause_id'),
                'payee_type_name' => $lookup('payee_types', 'name', 'payee_type_id'),
                'journal_entry_no' => $lookup('journal_entries', 'entry_no', 'journal_entry_id'),
                // Aliased, unlike the rest: this one reads `cost_lines` from
                // inside a query on `cost_lines`, so without a distinct name the
                // WHERE compares the outer row to itself and never matches.
                'consumes_line_description' => DB::table('cost_lines as planned_line')
                    ->select('description')
                    ->whereColumn('planned_line.id', 'cost_lines.consumes_line_id')
                    ->limit(1),
                'consumes_line_budgeted' => DB::table('cost_lines as planned_budget')
                    ->select('net_amount')
                    ->whereColumn('planned_budget.id', 'cost_lines.consumes_line_id')
                    ->limit(1),
                'payee_supplier_name' => DB::table('suppliers')
                    ->select('supplier_name')
                    ->whereColumn('suppliers.id', 'cost_lines.payee_id')
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('payee_types')
                        ->whereColumn('payee_types.id', 'cost_lines.payee_type_id')
                        ->where('payee_types.requires_supplier_record', true))
                    ->limit(1),
            ]);
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
