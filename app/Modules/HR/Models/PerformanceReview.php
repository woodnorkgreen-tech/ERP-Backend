<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = [
        'employee_id',
        'reviewed_by',
        'review_date',
        'period_start',
        'period_end',
        'overall_rating',
        'notes',
        'recommendations',
        'status',
    ];

    protected $casts = [
        'review_date'    => 'date',
        'period_start'   => 'date',
        'period_end'     => 'date',
        'overall_rating' => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reviewed_by');
    }
}
