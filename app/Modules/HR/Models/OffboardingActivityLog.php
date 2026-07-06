<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingActivityLog extends Model
{
    protected $table = 'hr_offboarding_activity_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }
}
