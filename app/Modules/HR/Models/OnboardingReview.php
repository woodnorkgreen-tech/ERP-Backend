<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingReview extends Model
{
    protected $table = 'hr_onboarding_reviews';

    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_date' => 'date',
        'conducted_date' => 'date',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function conductedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'conducted_by');
    }
}
