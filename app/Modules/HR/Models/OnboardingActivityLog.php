<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingActivityLog extends Model
{
    protected $table = 'hr_onboarding_activity_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }
}
