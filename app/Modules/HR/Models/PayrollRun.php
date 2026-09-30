<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;

class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_month',
        'status',
        'snapshot_settings',
        'total_gross',
        'total_net',
        'total_statutory',
        'created_by',
        'locked_by',
        'paid_by',
        'accrual_journal_entry_id',
        'payment_journal_entry_id',
        'payment_source_id',
        'payment_date',
        'payment_reference',
        'payment_id',
    ];

    protected $casts = [
        'snapshot_settings' => 'array',
        'total_gross' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_statutory' => 'decimal:2',
        'payment_date' => 'date',
    ];

    /**
     * Get the payslips associated with this run.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Get the user who created this run.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'locked_by');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'paid_by');
    }

    public function accrualJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'accrual_journal_entry_id');
    }

    public function paymentJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    /**
     * The independent money-movement record for this run's net-pay
     * disbursement — see Critical Risk C7 in
     * finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md. Null for
     * any run paid before this was introduced.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
