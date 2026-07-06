<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingExitInterview extends Model
{
    protected $table = 'hr_offboarding_exit_interviews';

    protected $guarded = ['id'];

    protected $casts = [
        'conducted_at'    => 'date',
        'would_recommend' => 'boolean',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function conductedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'conducted_by');
    }
}
