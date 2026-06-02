<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingCard extends Model
{
    protected $table = 'hr_onboarding_cards';

    protected $guarded = ['id'];

    protected $casts = [
        'is_locked'    => 'boolean',
        'unlocked_at'  => 'datetime',
        'completed_at' => 'datetime',
        'progress'     => 'float',
    ];

    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'card_id')->orderBy('sequence_order');
    }

    public function unlockedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'unlocked_by');
    }
}
