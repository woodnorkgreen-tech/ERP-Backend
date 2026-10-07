<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Modules\Finance\Models\Payment;
use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\HR\Models\Employee;

use Illuminate\Database\Eloquent\SoftDeletes;

class PettyCashRequisition extends Model
{
    // A pending requisition is a request for review, not yet a financial
    // commitment. Governance is deliberately evaluated when Finance approves
    // it; running the gate on model creation made it impossible to request a
    // budget correction through the normal workflow.
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cash_requisitions';

    public const VERIFICATION_FIELDS = ['department_id', 'purpose', 'project_id', 'project_name', 'enquiry_id',
        'venue', 'bill_id', 'requisition_type_id', 'category', 'custom_fields', 'type_snapshot',
        'responsible_verifier_id', 'payee_id', 'payee_name', 'payee_phone', 'total_amount'];

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->getOriginal('verification_status') === 'verified' && $r->isDirty(self::VERIFICATION_FIELDS)) {
                $r->invalidateVerification();
            }
        });
    }

    public function invalidateVerification(): void
    {
        if ($this->verification_status !== 'verified') {
            return;
        }
        app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class)->audit(
            $this, auth()->id(), 'verification_invalidated', 'Material details changed; a new verification is required.',
            ['verified_by' => $this->verified_by, 'verified_at' => $this->verified_at?->toIso8601String(),
                'previous_fingerprint' => $this->verification_fingerprint],
        );
        $this->forceFill(['verification_status' => 're_verification_required', 'verified_by' => null,
            'verified_at' => null, 'verification_fingerprint' => null]);
        if ($this->status === 'approved') {
            $this->forceFill(['status' => 'pending', 'approved_by' => null, 'approved_at' => null]);
            \Illuminate\Support\Facades\DB::afterCommit(fn () => \App\Events\PettyCashRequisitionReturnedToPending::dispatch($this->id));
        }
    }


    protected $fillable = [
        'responsible_verifier_id',
        'requisition_number',
        'user_id',
        'department_id',
        'category',
        'requisition_type_id',
        'purpose',
        'custom_fields',
        'type_snapshot',
        'total_amount',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'budget_exception',
        'digital_signature',
        'received_at',
        'payee_name',
        'project_id',
        'project_name',
        'venue',
        'enquiry_id',
        'signing_token',
        'received_by',
        'payee_id',
        'payee_phone',
        'bill_id',
        'is_public',
        'requester_name',
        'requester_phone',
        'surrendered_at',
        'surrender_due_at', 'surrender_returned_by', 'surrender_returned_at', 'surrender_return_reason',
        'surrender_resubmitted_by', 'surrender_resubmitted_at',
        'surrendered_by',
        'actual_spent_amount',
        'cash_returned_amount',
        'surrender_notes',
        'surrender_reconciled_at',
        'surrender_reconciled_by',
        'advance_journal_entry_id',
        'surrender_journal_entry_id',
        'advance_gl_posting_failed_at',
        'advance_gl_posting_error',
        'surrender_reversed_by', 'surrender_reversed_at', 'surrender_reversal_reason',
        'surrender_posting_generation', 'outstanding_advance_exception',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'actual_spent_amount' => 'decimal:2',
        'cash_returned_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'surrendered_at' => 'datetime',
        'surrender_due_at' => 'date',
        'surrender_returned_at' => 'datetime',
        'surrender_resubmitted_at' => 'datetime',
        'surrender_reversed_at' => 'datetime',
        'outstanding_advance_exception' => 'array',
        'surrender_reconciled_at' => 'datetime',
        'advance_gl_posting_failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'custom_fields' => 'array',
        'type_snapshot' => 'array',
        'budget_exception' => 'array',
    ];

    /**
     * Get the requester.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the department.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function requisitionType(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisitionType::class, 'requisition_type_id');
    }

    /**
     * Get the items (for bulk requests).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PettyCashRequisitionItem::class, 'requisition_id');
    }

    /**
     * Get the approver.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the associated disbursement.
     */
    public function disbursement(): HasOne
    {
        return $this->hasOne(Payment::class, 'requisition_id');
    }

    /**
     * Get the payee (individual receiving cash).
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_id');
    }

    /**
     * Get the project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    /**
     * Get the enquiry.
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'enquiry_id');
    }

    /**
     * Get the associated bill.
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\ProcurementStores\Models\Bill::class, 'bill_id');
    }

    /**
     * Get the surrender items (receipts & expense claims).
     */
    /**
     * The surrender as it currently stands. Items retired by a W3-7 reversal
     * are kept (their reversed cost lines still point at them) but are no
     * longer part of the surrender — see allSurrenderItems().
     */
    public function surrenderItems(): HasMany
    {
        return $this->hasMany(PettyCashSurrenderItem::class, 'requisition_id')->whereNull('superseded_at');
    }

    /** Statuses in which cash has left and the advance is not yet reconciled. */
    public const OUTSTANDING_ADVANCE_STATUSES = ['disbursed', 'received', 'surrender_pending', 'surrender_returned'];

    /**
     * Report 75R-B: money out on a requisition and not yet accounted for.
     *
     * A requisition paid as one payment is an advance for its whole amount until
     * its surrender is reconciled, as it always was. One paid by receiver is an
     * advance only for what has actually been paid, less what reconciled
     * surrenders have accepted or taken back. This is the same figure as the
     * position's "to account"; it is written in SQL so lists and totals can use
     * it without loading every requisition.
     */
    public const ADVANCE_EXPOSURE_SQL = "(CASE WHEN EXISTS (SELECT 1 FROM payments rp WHERE rp.requisition_id = petty_cash_requisitions.id AND rp.requisition_child_reference IS NOT NULL)
        THEN (SELECT COALESCE(SUM(ap.amount), 0) FROM payments ap WHERE ap.requisition_id = petty_cash_requisitions.id AND ap.status = 'active' AND ap.requisition_child_reference IS NOT NULL)
           - (SELECT COALESCE(SUM(sa.amount), 0) FROM petty_cash_surrender_allocations sa JOIN petty_cash_surrenders ps ON ps.id = sa.surrender_id
              WHERE sa.requisition_id = petty_cash_requisitions.id AND ps.status = 'reconciled')
        ELSE petty_cash_requisitions.total_amount END)";

    /**
     * The one definition of an outstanding advance, for every list, total and
     * overdue check: in an advance stage, with money still out. Each row carries
     * `advance_exposure`, the amount actually outstanding.
     */
    public function scopeOutstandingAdvances($query)
    {
        if ($query->getQuery()->columns === null) {
            $query->select('petty_cash_requisitions.*');
        }

        return $query->whereIn('petty_cash_requisitions.status', self::OUTSTANDING_ADVANCE_STATUSES)
            ->whereRaw(self::ADVANCE_EXPOSURE_SQL.' > 0')
            ->selectRaw(self::ADVANCE_EXPOSURE_SQL.' AS advance_exposure');
    }

    /**
     * W5-8 (confirmed 2026-09-23): where this advance stands against its
     * surrender deadline. The deadline is the transaction's own
     * surrender_due_at (explicit, or derived at disbursement from an approved
     * FinanceSetting); "due soon" exists only when Finance has approved a
     * window for it. No period is assumed.
     */
    public function surrenderState(?int $dueSoonDays = null, ?\Carbon\CarbonInterface $today = null): string
    {
        $today ??= now()->startOfDay();

        return match (true) {
            $this->status === 'surrendered' => 'surrendered',
            $this->status === 'surrender_pending' => 'surrender_submitted',
            $this->status === 'surrender_returned' => 'returned_for_correction',
            ! in_array($this->status, ['disbursed', 'received'], true) => 'not_disbursed',
            $this->surrender_due_at && $this->surrender_due_at->lt($today) => 'overdue',
            $this->surrender_due_at && $dueSoonDays !== null
                && $this->surrender_due_at->lte($today->copy()->addDays($dueSoonDays)) => 'due_soon',
            default => 'awaiting_surrender',
        };
    }

    public static function dueSoonDays(): ?int
    {
        $days = \App\Modules\Finance\Models\FinanceSetting::approvedValue('petty_cash_surrender_due_soon_days');

        return is_numeric($days) ? (int) $days : null;
    }

    /** Every item ever submitted, including those retired by a reversal. */
    public function allSurrenderItems(): HasMany
    {
        return $this->hasMany(PettyCashSurrenderItem::class, 'requisition_id');
    }

    /** Report 61: evidence (receipts, quotations) on the generic Finance attachments. */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Modules\Finance\Models\FinanceAttachment::class, 'source');
    }

    public function surrenderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surrendered_by');
    }

    public function surrenderReconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surrender_reconciled_by');
    }

    public function advanceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Models\JournalEntry::class, 'advance_journal_entry_id');
    }

    public function surrenderJournalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Models\JournalEntry::class, 'surrender_journal_entry_id');
    }

    public function responsibleVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_verifier_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** All cash movements, including voids retained for audit. */
    public function disbursements(): HasMany
    {
        return $this->hasMany(Payment::class, 'requisition_id');
    }

    /** Report 75R-B: receiver surrenders, every stage and status. */
    public function receiverSurrenders(): HasMany
    {
        return $this->hasMany(PettyCashSurrender::class, 'requisition_id');
    }

    public function receiptConfirmations(): HasMany
    {
        return $this->hasMany(RequisitionReceiptConfirmation::class, 'requisition_id');
    }

    public function balanceReleases(): HasMany
    {
        return $this->hasMany(RequisitionBalanceRelease::class, 'requisition_id');
    }

    /** Report 75R-A: every line-to-Payment allocation, reversed Payments included. */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(RequisitionPaymentAllocation::class, 'requisition_id');
    }

    /**
     * Whether money has ever moved against this requisition's lines.
     *
     * A reversed receiver payment keeps its line allocations as history, so the
     * lines it points at can no longer be rewritten even though nothing is paid.
     */
    public function hasPaymentHistory(): bool
    {
        return $this->disbursements()->where('status', 'active')->exists()
            || $this->paymentAllocations()->exists();
    }

    public static function generateRequisitionNumber(): string
    {
        return \App\Modules\Finance\Support\DocumentNumber::next(
            \App\Modules\Finance\Support\DocumentNumber::REQUISITION,
        );
    }
}
