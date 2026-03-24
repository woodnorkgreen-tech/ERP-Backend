<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $fillable = [
        'employee_id',
        'payroll_month',
        'basic_salary',
        'gross_pay',
        'net_pay',
        'tax_breakdown',
        'ledger_breakdown',
        'status',
        'payment_date',
        'notes'
    ];

    protected $casts = [
        'tax_breakdown' => 'array',
        'ledger_breakdown' => 'array',
        'payment_date' => 'date',
        'basic_salary' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'net_pay' => 'decimal:2'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
