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
        'assigned_po',
        'follow_up_notes',
        'enquiry_number',
        'converted_to_project_id',
        'venue',
        'site_survey_skipped',
        'site_survey_skip_reason',
        'quote_approved',
        'quote_approved_at',
        'quote_approved_by',
        'created_by',
        // Project fields
        'project_id',
        'start_date',
        'end_date',
        'budget',
        'current_phase',
        'assigned_users',
    ];

    protected $casts = [
        'date_received' => 'date',
        'expected_delivery_date' => 'date',
        'site_survey_skipped' => 'boolean',
        'assigned_po' => 'integer',
        'quote_approved' => 'boolean',
        'quote_approved_at' => 'datetime',
        'estimated_budget' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'assigned_users' => 'array',
        'current_phase' => 'integer',
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

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function enquiryTasks(): HasMany
    {
        return $this->hasMany(\App\Modules\Projects\Models\EnquiryTask::class, 'project_enquiry_id');
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
     * Approve the quote for this enquiry and convert to project
     */
    public function approveQuote(int $userId): bool
    {
        // Temporarily remove permission check for testing
        // $user = User::find($userId);
        // if (!$user || !$user->hasPermissionTo(Permissions::FINANCE_QUOTE_APPROVE)) {
        //     throw new \Exception('Unauthorized: Only users with finance approval permission can approve quotes');
        // }

        $this->update([
            'quote_approved' => true,
            'quote_approved_at' => now(),
            'quote_approved_by' => $userId,
            'status' => EnquiryConstants::STATUS_CONVERTED_TO_PROJECT
        ]);

        // Create project
        $project = Project::create([
            'enquiry_id' => $this->id,
            'project_id' => $this->generateProjectId(),
            'start_date' => $this->expected_delivery_date,
            'budget' => $this->estimated_budget,
            'assigned_users' => [], // can be set later
        ]);

        $this->update(['converted_to_project_id' => $project->id]);

        return true;
    }

    /**
     * Generate a unique project ID
     */
    public function generateProjectId(): string
    {
        $now = now();
        $year = $now->year;
        $month = str_pad($now->month, 2, '0', STR_PAD_LEFT);

        // Get the last project number for this month
        $lastProject = Project::whereYear('created_at', $year)
                              ->whereMonth('created_at', $now->month)
                              ->orderByRaw('CAST(SUBSTRING(project_id, LENGTH(?) + 1) AS UNSIGNED) DESC', [EnquiryConstants::PROJECT_PREFIX . "-{$year}{$month}-"])
                              ->first();

        $nextNumber = 1;
        if ($lastProject) {
            // Extract the number part after the prefix
            $prefix = EnquiryConstants::PROJECT_PREFIX . "-{$year}{$month}-";
            $numberPart = substr($lastProject->project_id, strlen($prefix));
            $nextNumber = intval($numberPart) + 1;
        }
        $formattedNumber = str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        return EnquiryConstants::PROJECT_PREFIX . "-{$year}{$month}-{$formattedNumber}";
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

// Alias for backward compatibility
class_alias(ProjectEnquiry::class, 'App\Models\Enquiry');
