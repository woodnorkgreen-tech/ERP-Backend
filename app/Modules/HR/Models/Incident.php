<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Modules\HR\Models\Department;

class Incident extends Model
{
    protected $table = 'incidents';
    
    protected $fillable = [
        'title',
        'description',
        'location',
        'incident_datetime',
        'severity',
        'status',
        'incident_types',
        'classification_category',
        'classification_subcategory',
        'classification_other_details',
        'immediate_actions_taken',
        'witnesses',
        'equipment_involved',
        'root_cause',
        'corrective_actions',
        'preventive_measures',
        'additional_notes',
        'evidence_paths',
        'reporter_name',
        'reporter_email',
        'reported_by',
        'department_id',
        'job_title',
        'contact_info',
        'short_term_fixes',
        'long_term_measures',
        'responsible_party',
        'impact_analysis',
        'avoid_recurrence',
        'policy_changes',
        'training_needs',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'date_reported',
        'is_guest_submission',
    ];
    
    protected $casts = [
        'incident_datetime' => 'datetime',
        'date_reported' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'incident_types' => 'array',
        'evidence_paths' => 'array',
        'is_guest_submission' => 'boolean',
    ];
    
    // Severity constants
    const SEVERITY_LOW = 'Low';
    const SEVERITY_MEDIUM = 'Medium';
    const SEVERITY_HIGH = 'High';
    const SEVERITY_CRITICAL = 'Critical';
    
    // Status constants
    const STATUS_OPEN = 'Open';
    const STATUS_IN_PROGRESS = 'In Progress';
    const STATUS_UNDER_REVIEW = 'Under Review';
    const STATUS_RESOLVED = 'Resolved';
    const STATUS_CLOSED = 'Closed';
    
    // Category constants
    const CATEGORY_INVENTORY = 'inventory';
    const CATEGORY_HEALTH = 'health';
    const CATEGORY_COMPLIANCE = 'compliance';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_OPERATIONAL = 'operational';
    
    /**
     * Get the reporter (user who reported the incident)
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
    
    /**
     * Get the reviewer (user who reviewed the incident)
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    
    /**
     * Get the approver (user who approved the incident)
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    
    /**
     * Get the department
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
    
    /**
     * Get the incident comments
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IncidentComment::class, 'incident_id');
    }
    
    /**
     * Get the incident activity logs
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(IncidentActivityLog::class, 'incident_id');
    }
    
    /**
     * Get severity badge class
     */
    public function getSeverityBadgeClass(): string
    {
        return match($this->severity) {
            self::SEVERITY_LOW => 'success',
            self::SEVERITY_MEDIUM => 'warning',
            self::SEVERITY_HIGH => 'danger',
            self::SEVERITY_CRITICAL => 'danger',
            default => 'secondary',
        };
    }
    
    /**
     * Get status badge class
     */
    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            self::STATUS_OPEN => 'primary',
            self::STATUS_IN_PROGRESS => 'warning',
            self::STATUS_UNDER_REVIEW => 'info',
            self::STATUS_RESOLVED => 'success',
            self::STATUS_CLOSED => 'secondary',
            default => 'secondary',
        };
    }
    
    /**
     * Check if user can review this incident
     */
    public function canBeReviewedBy(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'Admin', 'HR', 'Lead']);
    }
    
    /**
     * Check if user can approve this incident
     */
    public function canBeApprovedBy(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'Admin', 'HR']);
    }
    
    /**
     * Scope for filtering by status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
    
    /**
     * Scope for filtering by severity
     */
    public function scopeSeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }
    
    /**
     * Scope for filtering by department
     */
    public function scopeDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
    
    /**
     * Scope for filtering by reporter
     */
    public function scopeReportedBy($query, $userId)
    {
        return $query->where('reported_by', $userId);
    }
    
    /**
     * Get all severity options
     */
    public static function getSeverityOptions(): array
    {
        return [
            self::SEVERITY_LOW => 'Low',
            self::SEVERITY_MEDIUM => 'Medium',
            self::SEVERITY_HIGH => 'High',
            self::SEVERITY_CRITICAL => 'Critical',
        ];
    }
    
    /**
     * Get all status options
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CLOSED => 'Closed',
        ];
    }
    
    /**
     * Get all category options
     */
    public static function getCategoryOptions(): array
    {
        return [
            self::CATEGORY_INVENTORY => 'Inventory & Stock Control',
            self::CATEGORY_HEALTH => 'Health & Safety',
            self::CATEGORY_COMPLIANCE => 'Process & Compliance',
            self::CATEGORY_SECURITY => 'Security',
            self::CATEGORY_OPERATIONAL => 'Operational Delays',
        ];
    }
    
    /**
     * Get incident types for a category
     */
    public static function getIncidentTypesForCategory(string $category): array
    {
        return match($category) {
            self::CATEGORY_INVENTORY => [
                'Stock Discrepancies',
                'Losses',
                'Damages',
                'Unauthorized Access',
            ],
            self::CATEGORY_HEALTH => [
                'Injuries',
                'Unsafe Practices',
                'Safety Violations',
            ],
            self::CATEGORY_COMPLIANCE => [
                'Policy Breaches',
                'Late Reporting',
                'Poor Documentation',
            ],
            self::CATEGORY_SECURITY => [
                'Theft',
                'Unauthorized Entry',
                'After-hours Access',
                'Violence/Unrest',
            ],
            self::CATEGORY_OPERATIONAL => [
                'Communication Breakdowns',
                'Technical Difficulties',
                'Reporting Delays',
                'Approval Delays',
                'Unforeseen Circumstances',
                'Regulatory Changes',
            ],
            default => [],
        };
    }
}

