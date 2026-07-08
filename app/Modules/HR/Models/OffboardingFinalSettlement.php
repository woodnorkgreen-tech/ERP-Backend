<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingFinalSettlement extends Model
{
    protected $table = 'hr_offboarding_final_settlements';

    protected $guarded = ['id'];

    protected $casts = [
        'accrued_leave_days' => 'float',
        'leave_payout_amount'=> 'float',
        'outstanding_salary' => 'float',
        'other_dues'         => 'float',
        'deductions'         => 'float',
        'net_amount'         => 'float',
        'calculated_at'      => 'datetime',
        'approved_at'        => 'datetime',
        'paid_at'            => 'datetime',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function calculatedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'calculated_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
