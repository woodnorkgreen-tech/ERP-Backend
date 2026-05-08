<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Constants\EnquiryConstants;
use App\Constants\Permissions;

class ProjectEnquiry extends Model
{
    use HasFactory;

    protected $table = 'project_enquiries';

    protected $fillable = [
        'date_received',
        'expected_delivery_date',
        'client_id',
        'title',
        'description',
        'project_scope',
        'priority',
        'status',
        'department_id',
        'assigned_department',
        'estimated_budget',
        'project_deliverables',
        'contact_person',
        'project_officer_id',
        'assigned_po',
        'follow_up_notes',
        'enquiry_number',
        'job_number',
        'venue',
        'site_survey_skipped',
        'site_survey_skip_reason',
        'selected_workflow_tasks',
        'workflow_preset_type',
        'quote_approved',
        'quote_approved_at',
        'quote_approved_by',
        'created_by',
        'start_date',
        'end_date',
        'budget',
        'current_phase',
        'assigned_users',
        'client_approved_quote',
    ];

    protected $casts = [
        'date_received' => 'date',
        'expected_delivery_date' => 'date',
        'site_survey_skipped' => 'boolean',
        'assigned_po' => 'integer',
        'project_officer_id' => 'integer',
        'quote_approved' => 'boolean',
        'quote_approved_at' => 'datetime',
        'estimated_budget' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'assigned_users' => 'array',
        'project_scope' => 'array',
        'selected_workflow_tasks' => 'array',
        'current_phase' => 'integer',
        'job_number' => 'string',
        'client_approved_quote' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Department::class);
    }

    public function projectOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_officer_id');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'enquiry_id');
    }

    public function enquiryTasks(): HasMany
    {
        return $this->hasMany(\App\Modules\Projects\Models\EnquiryTask::class, 'project_enquiry_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(\App\Modules\Production\Models\WorkOrder::class, 'project_enquiry_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EnquiryPayment::class, 'project_enquiry_id');
    }


    // Scopes
    public function scopeByDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeAccessibleByUser($query, $user)
    {
        $accessibleDepartments = $user->getAccessibleDepartments()->pluck('id')->toArray();

        // Allow enquiries without department assignment, or with accessible departments
        return $query->where(function ($q) use ($accessibleDepartments) {
            $q->whereNull('department_id')
              ->orWhereIn('department_id', $accessibleDepartments);
        });
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', EnquiryConstants::getActiveStatuses());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', EnquiryConstants::STATUS_COMPLETED);
    }


    /**
     * Generate a unique project ID
     */
    public function generateProjectId(): string
    {
        return app(\App\Modules\Projects\Services\SequencingService::class)->generateProjectId($this->workflow_preset_type);
    }

    /**
     * Generate a unique job number when quote is approved
     */
    public function generateJobNumber(): string
    {
        return app(\App\Modules\Projects\Services\SequencingService::class)->generateJobNumber($this->workflow_preset_type);
    }

    /**
     * Get the route key name for this model.
     * This tells Laravel to use 'enquiry' as the route parameter name
     * instead of the default 'project_enquiry'.
     */
    public function getRouteKeyName()
    {
        return 'enquiry';
    }
}

// Alias for backward compatibility removed - use ProjectEnquiry directly
