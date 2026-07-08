<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingClearance extends Model
{
    protected $table = 'hr_offboarding_clearances';

    protected $guarded = ['id'];

    protected $casts = [
        'is_applicable' => 'boolean',
        'is_needed'     => 'boolean',
        'cleared_at'    => 'datetime',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function clearedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cleared_by');
    }
}
