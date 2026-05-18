<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, \App\Traits\LogsActions;

    protected $table = 'projects';

    protected $fillable = [
        'enquiry_id',
        'project_id',
        'start_date',
        'end_date',
        'budget',
        'current_phase',
        'assigned_users',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'assigned_users' => 'array',
        'current_phase' => 'integer',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }

    public function projectTasks(): HasMany
    {
        return $this->hasMany(\App\Modules\Projects\Models\EnquiryTask::class, 'project_enquiry_id', 'enquiry_id');
    }

    public function workOrders()
    {
        return $this->hasMany(\App\Modules\Production\Models\WorkOrder::class, 'project_id');
    }

    public function otEntries()
    {
        return $this->hasMany(\App\Modules\HR\Models\OTEntry::class, 'project_id');
    }
}
