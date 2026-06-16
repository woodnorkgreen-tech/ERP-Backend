<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingHandover extends Model
{
    protected $table = 'hr_onboarding_handovers';

    protected $guarded = ['id'];

    protected $casts = [
        'handover_date' => 'date',
        'completed_at'  => 'datetime',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function handedOverByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'handed_over_by');
    }

    public function departmentLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handed_over_to_employee_id');
    }
}
