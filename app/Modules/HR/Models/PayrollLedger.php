<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLedger extends Model
{
    protected $fillable = [
        'employee_id',
        'ledger_month',
        'type',
        'amount_type',
        'amount_value',
        'name',
        'description',
        'is_recurring',
        'recurring_end_month'
    ];

    protected $casts = [
        'amount_value' => 'decimal:2',
        'is_recurring' => 'boolean'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
