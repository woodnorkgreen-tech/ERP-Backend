<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsLogEntry extends Model
{
    protected $fillable = [
        'project_enquiry_id',
        'site',
        'loading_time',
        'departure',
        'setdown_time',
        'vehicle_allocated',
        'project_officer_incharge',
        'remarks',
        'status',
        'created_by',
    ];

    protected $casts = [
        'loading_time' => 'datetime',
        'departure' => 'datetime',
        'setdown_time' => 'datetime',
        'status' => 'string',
    ];

    /**
     * Get the project enquiry that this log entry belongs to.
     */
    public function projectEnquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    /**
     * Get the user who created this log entry.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
