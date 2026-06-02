<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingWelcomeKitItem extends Model
{
    protected $table = 'hr_onboarding_welcome_kit_items';

    protected $guarded = ['id'];

    protected $casts = [
        'is_ready'        => 'boolean',
        'marked_ready_at' => 'datetime',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function markedReadyByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'marked_ready_by');
    }
}
