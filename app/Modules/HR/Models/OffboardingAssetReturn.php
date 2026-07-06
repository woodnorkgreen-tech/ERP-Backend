<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingAssetReturn extends Model
{
    protected $table = 'hr_offboarding_asset_returns';

    protected $guarded = ['id'];

    protected $casts = [
        'is_returned'   => 'boolean',
        'is_applicable' => 'boolean',
        'is_needed'     => 'boolean',
        'returned_at'   => 'datetime',
    ];

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }
}
