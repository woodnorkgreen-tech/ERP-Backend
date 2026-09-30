<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryAdvanceRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'amount',
        'reason',
        'status',
        'hr_remarks',
        'target_payroll_month',
        'ledger_id', 'payment_id', 'amount_recovered', 'paid_at', 'fully_recovered_at'
    ];

    protected $casts = ['amount' => 'decimal:2', 'amount_recovered' => 'decimal:2', 'paid_at' => 'datetime', 'fully_recovered_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(PayrollLedger::class, 'ledger_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(PayrollLedger::class, 'salary_advance_request_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Models\Payment::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRecovery::class);
    }

    protected $appends = ['outstanding_balance', 'recovery_status'];

    /** Paid advances for this employee that payroll has not fully recovered. */
    public static function outstandingFor(?int $employeeId): \Illuminate\Support\Collection
    {
        if (! $employeeId) {
            return collect();
        }

        return static::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('payment_id')
            ->whereColumn('amount_recovered', '<', 'amount')
            ->get(['id', 'employee_id', 'amount', 'amount_recovered', 'paid_at', 'payment_id', 'status']);
    }

    public function getOutstandingBalanceAttribute(): string
    {
        return bcsub((string) $this->amount, (string) $this->amount_recovered, 2);
    }

    public function getRecoveryStatusAttribute(): string
    {
        if (! $this->payment_id) return $this->status === 'approved' ? 'approved' : $this->status;
        if (bccomp($this->outstanding_balance, '0.00', 2) <= 0) return 'fully_recovered';
        return bccomp((string) $this->amount_recovered, '0.00', 2) === 1 ? 'recovery_in_progress' : 'paid';
    }
}
