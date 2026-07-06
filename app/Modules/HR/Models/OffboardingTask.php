<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingTask extends Model
{
    protected $table = 'hr_offboarding_tasks';

    protected $guarded = ['id'];

    protected $casts = [
        'is_required'   => 'boolean',
        'is_optional'   => 'boolean',
        'is_active'     => 'boolean',
        'is_applicable' => 'boolean',
        'is_needed'     => 'boolean',
        'completed_at'  => 'datetime',
        'due_date'      => 'date',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(OffboardingCard::class, 'card_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }
}
