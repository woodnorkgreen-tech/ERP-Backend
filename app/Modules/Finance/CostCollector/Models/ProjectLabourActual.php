<?php

namespace App\Modules\Finance\CostCollector\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

/**
 * Project Actual Labour Record — W7 Labour Cost confirmed subset.
 *
 * Lifecycle:
 *   recorded → po_verified → finance_verified
 *   recorded | po_verified → returned_for_correction → (recorder corrects) resubmit → recorded
 *   finance_verified → superseded, once a correction successor is Finance-verified (W7-13:
 *     the economic effect is a W6-4 reversing pair, see CostTransferService::correct()).
 *
 * The cost formula at confirmation time is frozen:
 *   calculated_cost = actual_quantity * actual_days * unit_rate
 *   (for hours unit: actual_hours * unit_rate)
 *
 * On Finance verification, an authoritative CostLine with:
 *   nature  = 'actual'
 *   status  = 'verified'
 *   postsIndependently = false   ← STAB-7: zero duplicate GL debit
 * is posted to the project's cost account.
 *
 * PAYROLL PRIVACY: this model NEVER exposes employee salary, bank details,
 * payslips, or statutory deductions. Only employee name and staff number
 * are allowed on any API response.
 */
class ProjectLabourActual extends Model
{
    /** cost_lines.source_type written for every labour CostLine (analytical: W7-12). */
    public const COST_SOURCE_TYPE = 'ProjectLabourActual';

    protected $table = 'project_labour_actuals';

    protected $fillable = [
        'project_enquiry_id',
        'budget_line_id',
        'budget_id',
        'consumes_cost_line_id',
        'cost_line_id',
        'labour_role',
        'labour_category',
        'budget_unit',
        'unit_rate',
        'rate_resolution_status',
        'rate_source',
        'actual_quantity',
        'actual_days',
        'actual_hours',
        'calculated_cost',
        'work_date',
        'employee_id',
        'is_unbudgeted',
        'rework_type',
        'status',
        'recorded_by',
        'recorded_at',
        'po_verified_by',
        'po_verified_at',
        'po_notes',
        'finance_verified_by',
        'finance_verified_at',
        'finance_notes',
        'returned_by',
        'returned_at',
        'return_reason',
        'resubmitted_by',
        'resubmitted_at',
        'resubmission_count',
        'unbudgeted_reason',
        'reversal_of_id',
        'superseded_by_id',
        'correction_reason',
        'correction_transfer_id',
        'reclassification_transfer_id',
        'metadata',
    ];

    protected $casts = [
        'unit_rate'        => 'decimal:2',
        'actual_quantity'  => 'decimal:2',
        'actual_days'      => 'decimal:2',
        'actual_hours'     => 'decimal:2',
        'calculated_cost'  => 'decimal:2',
        'work_date'        => 'date',
        'is_unbudgeted'    => 'boolean',
        'recorded_at'      => 'datetime',
        'po_verified_at'   => 'datetime',
        'finance_verified_at' => 'datetime',
        'returned_at'      => 'datetime',
        'resubmitted_at'   => 'datetime',
        'resubmission_count' => 'integer',
        'metadata'         => 'array',
        'rate_source'      => 'array',
    ];

    // ── Status constants ──────────────────────────────────────────────────────

    const STATUS_RECORDED              = 'recorded';
    const STATUS_PO_VERIFIED           = 'po_verified';
    const STATUS_FINANCE_VERIFIED      = 'finance_verified';
    const STATUS_RETURNED              = 'returned_for_correction';
    const STATUS_SUPERSEDED            = 'superseded';

    const RATE_RESOLVED = 'resolved';
    const RATE_UNRESOLVED = 'unresolved';

    // ── Rework constants ──────────────────────────────────────────────────────

    const REWORK_NONE           = 'none';
    const REWORK_CLIENT_CAUSED  = 'client_caused';
    const REWORK_INTERNAL       = 'internal_rework';

    // ── Relations ─────────────────────────────────────────────────────────────

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function plannedCostLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'consumes_cost_line_id');
    }

    public function actualCostLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'cost_line_id');
    }

    /** W7-11: Employee is optional and privacy-safe — only name/staff_number exposed. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function poVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'po_verified_by');
    }

    public function financeVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_verified_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function resubmittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resubmitted_by');
    }

    /** Immutable return history, oldest cycle first. */
    public function returns(): HasMany
    {
        return $this->hasMany(ProjectLabourActualReturn::class)->orderBy('cycle');
    }

    /** The pending or completed correction successor of this actual, if any. */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function correctionTransfer(): BelongsTo
    {
        return $this->belongsTo(CostLineTransfer::class, 'correction_transfer_id');
    }

    public function reclassificationTransfer(): BelongsTo
    {
        return $this->belongsTo(CostLineTransfer::class, 'reclassification_transfer_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    public function scopeForEnquiry(Builder $query, int $enquiryId): Builder
    {
        return $query->where('project_enquiry_id', $enquiryId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_SUPERSEDED]);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FINANCE_VERIFIED);
    }

    public function scopePendingPoVerification(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RECORDED);
    }

    public function scopePendingFinanceVerification(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PO_VERIFIED);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_FINANCE_VERIFIED;
    }

    public function isUnbudgeted(): bool
    {
        return (bool) $this->is_unbudgeted;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function isAwaitingPoVerification(): bool
    {
        return $this->status === self::STATUS_RECORDED;
    }

    public function isAwaitingFinanceVerification(): bool
    {
        return $this->status === self::STATUS_PO_VERIFIED;
    }

    public function isReturnedForCorrection(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }
}
