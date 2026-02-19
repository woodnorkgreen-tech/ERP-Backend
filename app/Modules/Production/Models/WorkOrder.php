<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Constants\EnquiryConstants;

class WorkOrder extends Model
{
    use HasFactory;

    protected $table = 'work_orders';

    protected $fillable = [
        'work_order_number',
        'enquiry_task_id',
        'project_enquiry_id',
        'project_id',
        'title',
        'specifications',
        'quantity',
        'status',
        'priority',
        'due_date',
        'started_at',
        'completed_at',
        'workflow_completed_steps',
        'assigned_to',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'workflow_completed_steps' => 'array',
    ];

    /**
     * Relationships to always load
     */
    protected $with = ['projectEnquiry'];

    // Relationships
    public function enquiryTask(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    public function projectEnquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function scrapLogs(): HasMany
    {
        return $this->hasMany(WorkOrderScrapLog::class, 'work_order_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class, 'work_order_id');
    }

    public function midQcChecks(): HasMany
    {
        return $this->hasMany(WorkOrderMidQcCheck::class, 'work_order_id');
    }

    // Scopes
    public function scopeInProgress($query)
    {
        return $query->whereHas('projectEnquiry', function ($q) {
            $q->whereNull('job_number');
        });
    }

    public function scopeApproved($query)
    {
        return $query->whereHas('projectEnquiry', function ($q) {
            $q->whereNotNull('job_number');
        });
    }

    public function scopeCompleted($query)
    {
        return $query->whereHas('projectEnquiry', function ($q) {
            $q->where('status', EnquiryConstants::STATUS_COMPLETED);
        });
    }

    /**
     * Get status category based solely on related enquiry (ignoring work order's own status)
     */
    public function getStatusCategory(): string
    {
        if (!$this->projectEnquiry) {
            return 'in_progress'; // Default if no enquiry
        }

        $enquiry = $this->projectEnquiry;
        
        if ($enquiry->status === EnquiryConstants::STATUS_COMPLETED) {
            return 'completed';
        }
        
        // If enquiry has job number, it's "approved" (regardless of status)
        if ($enquiry->job_number) {
            return 'approved';
        }
        
        // If no job number, it's "in progress"
        return 'in_progress';
    }

    /**
     * Check if work order is in progress (enquiry has no job number)
     */
    public function isInProgress(): bool
    {
        return $this->getStatusCategory() === 'in_progress';
    }

    /**
     * Check if work order is approved (enquiry has job number)
     */
    public function isApproved(): bool
    {
        return $this->getStatusCategory() === 'approved';
    }

    /**
     * Check if work order is completed (enquiry is completed)
     */
    public function isCompleted(): bool
    {
        return $this->getStatusCategory() === 'completed';
    }
}
