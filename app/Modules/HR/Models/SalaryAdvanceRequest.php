<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvanceRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'amount',
        'reason',
        'status',
        'hr_remarks',
        'target_payroll_month',
        'ledger_id'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(PayrollLedger::class, 'ledger_id');
    }
}
