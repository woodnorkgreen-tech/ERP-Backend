<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingDocumentRequirement extends Model
{
    protected $table = 'hr_onboarding_document_requirements';

    protected $guarded = ['id'];

    protected $casts = [
        'is_required'  => 'boolean',
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }

    public function employeeDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }
}
