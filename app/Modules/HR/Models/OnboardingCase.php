<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OnboardingCase extends Model
{
    use SoftDeletes;

    protected $table = 'hr_onboarding_cases';

    protected $guarded = ['id'];

    protected $casts = [
        'start_date'      => 'date',
        'hr_approved_at'  => 'datetime',
        'it_unlocked_at'  => 'datetime',
        'sops_unlocked_at'=> 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'overall_progress'=> 'float',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function hrOwner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'hr_owner_id');
    }

    public function departmentLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'department_lead_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'hr_approved_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(OnboardingCard::class, 'onboarding_case_id')->orderBy('sequence_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'onboarding_case_id');
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(OnboardingDocumentRequirement::class, 'onboarding_case_id');
    }

    public function welcomeKitItems(): HasMany
    {
        return $this->hasMany(OnboardingWelcomeKitItem::class, 'onboarding_case_id');
    }

    public function handover(): HasOne
    {
        return $this->hasOne(OnboardingHandover::class, 'onboarding_case_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OnboardingReview::class, 'onboarding_case_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OnboardingActivityLog::class, 'onboarding_case_id')->orderByDesc('created_at');
    }
}
