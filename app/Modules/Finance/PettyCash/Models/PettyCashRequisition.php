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

    protected $fillable = [
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

    /**
     * Generate a unique requisition number.
     */
    public static function generateRequisitionNumber(): string
    {
        $maxNum = self::withTrashed()
            ->where('requisition_number', 'LIKE', 'PCR-%')
            ->selectRaw("MAX(CAST(SUBSTRING(requisition_number, 5) AS UNSIGNED)) as max_val")
            ->value('max_val');
            
        $nextNum = $maxNum ? $maxNum + 1 : 1;
        return 'PCR-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    }
}
