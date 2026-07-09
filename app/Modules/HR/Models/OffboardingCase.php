<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OffboardingCase extends Model
{
    use SoftDeletes;

    protected $table = 'hr_offboarding_cases';

    protected $guarded = ['id'];

    protected $casts = [
        'last_working_day' => 'date',
        'hr_approved_at'   => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'overall_progress' => 'float',
    ];

    public function employee(): BelongsTo
    {
        // withTrashed: the final approval gate soft-deletes the employee (archives them
        // after termination), but the case must keep showing their name/details afterward.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'initiated_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'hr_approved_by');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(OffboardingCard::class, 'offboarding_case_id')->orderBy('sequence_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OffboardingTask::class, 'offboarding_case_id');
    }

    public function assetReturns(): HasMany
    {
        return $this->hasMany(OffboardingAssetReturn::class, 'offboarding_case_id');
    }

    public function clearances(): HasMany
    {
        return $this->hasMany(OffboardingClearance::class, 'offboarding_case_id');
    }

    public function exitInterview(): HasOne
    {
        return $this->hasOne(OffboardingExitInterview::class, 'offboarding_case_id');
    }

    public function finalSettlement(): HasOne
    {
        return $this->hasOne(OffboardingFinalSettlement::class, 'offboarding_case_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OffboardingActivityLog::class, 'offboarding_case_id')->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OffboardingAttachment::class, 'offboarding_case_id')->orderByDesc('created_at');
    }
}
