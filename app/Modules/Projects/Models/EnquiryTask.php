<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\HR\Models\Department;

class EnquiryTask extends Model
{
    use \App\Modules\Projects\Traits\AuthorizedVisibility;
    use HasFactory, \App\Traits\LogsActions;

    protected $table = 'enquiry_tasks';


    protected $fillable = [
        'project_enquiry_id',
        'department_id',
        'title',
        'task_description',
        'status',
        'assigned_user_id',
        'priority',
        'estimated_hours',
        'actual_hours',
        'due_date',
        'started_at',
        'completed_at',
        'submitted_at',
        'notes',
        'task_order',
        'created_by',
        'type',
        // LEGACY FIELDS - Use assignedUsers() relationship instead
        'assigned_at',
        'assigned_by',
        'assigned_to',
    ];

    protected $appends = ['task_data', 'is_authorized'];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'task_order' => 'integer',
        'assigned_at' => 'datetime',
    ];

    /**
     * Relationships to always load
     */
    protected $with = ['assignedTo', 'assignedUser'];

    // Task type to department mapping. The FIRST department is the primary owner
    // (gets the task's department_id for routing — department_id is a single FK).
    // The remaining departments are collaborators that may also work the task.
    // All names must match DepartmentSeeder. Use the primaryDepartmentName() /
    // departmentNamesForType() helpers rather than reading this directly.
    const TASK_TYPE_DEPARTMENT_MAPPING = [
        'site-survey'        => ['Client Service', 'Production', 'Projects', 'Accounts/Finance', 'Design/Creatives', 'Teams'],
        'design'             => ['Design/Creatives', 'Production', 'Projects', 'Client Service'],
        'materials'          => ['Procurement', 'Production', 'Projects', 'Client Service'],
        'budget'             => ['Accounts/Finance', 'Procurement'],
        'quote'              => ['Costing', 'Accounts/Finance'],
        'quote_approval'     => ['Costing', 'Projects', 'Accounts/Finance'],
        'procurement'        => ['Procurement', 'Stores'],
        'production'         => ['Production', 'Projects', 'Client Service'],
        'teams'              => ['Teams', 'Projects'],
        'logistics'          => ['Logistics', 'Projects', 'Client Service'],
        'setup'              => ['Production', 'Projects', 'Client Service', 'Design/Creatives'],
        'handover'           => ['Projects', 'Client Service'],
        'setdown'            => ['Logistics', 'Projects', 'Client Service', 'Production'],
        'report'             => ['Accounts/Finance', 'Projects', 'Client Service'],
        'stores'             => ['Stores'],
        'project_management' => ['Projects'],
    ];

    // Relationships
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // Backward compatibility relationships
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function assignmentHistory()
    {
        return $this->hasMany(\App\Models\TaskAssignmentHistory::class, 'enquiry_task_id');
    }

    /**
     * Many-to-many relationship for multiple user assignments
     * This is the primary way to check task assignments
     */
    public function assignedUsers()
    {
        return $this->belongsToMany(
            \App\Models\User::class,
            'enquiry_task_user',
            'enquiry_task_id',
            'user_id'
        )->withPivot('assigned_by', 'assigned_at')->withTimestamps();
    }


    public function designAssets()
    {
        return $this->hasMany(\App\Models\DesignAsset::class, 'enquiry_task_id');
    }

    public function designRequirements()
    {
        return $this->hasMany(\App\Models\DesignRequirement::class, 'enquiry_task_id');
    }

    public function quoteData()
    {
        return $this->hasOne(\App\Models\TaskQuoteData::class, 'enquiry_task_id');
    }

    public function budgetData()
    {
        return $this->hasOne(\App\Models\TaskBudgetData::class, 'enquiry_task_id');
    }

    public function materialsData()
    {
        return $this->hasOne(\App\Models\TaskMaterialsData::class, 'enquiry_task_id');
    }

    public function procurementData()
    {
        return $this->hasOne(\App\Models\TaskProcurementData::class, 'enquiry_task_id');
    }


    public function handoverSurvey()
    {
        return $this->hasOne(\App\Models\HandoverSurvey::class, 'task_id');
    }

    public function workOrder()
    {
        return $this->hasOne(\App\Modules\Production\Models\WorkOrder::class, 'enquiry_task_id');
    }

    /**
     * Unified accessor for task data based on type
     */
    public function getTaskDataAttribute()
    {
        return match($this->type) {
            'quote', 'quote_approval' => $this->quoteData,
            'budget' => $this->budgetData,
            'materials' => $this->materialsData,
            'procurement' => $this->procurementData,
            'production' => $this->workOrder, // relation (eager-loadable) instead of a query-in-accessor
            'handover' => $this->handoverSurvey,
            default => null,
        };
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Eager-load everything the `task_data` and `is_authorized` accessors need,
     * so serializing a list of tasks doesn't trigger N+1 queries. Use this on
     * any endpoint that returns a collection of tasks.
     */
    public function scopeWithTaskData($query)
    {
        return $query->with([
            'assignedUsers',
            'quoteData',
            'budgetData',
            'materialsData',
            'procurementData',
            'handoverSurvey',
            'workOrder',
        ]);
    }

    /**
     * Tasks that belong to a department's POOL: those it owns directly
     * (department_id) plus those its task types make it a collaborator on.
     * Use for department boards/queues — NOT for ownership reporting.
     */
    public function scopeForDepartmentPool($query, $departmentId)
    {
        $department = Department::find($departmentId);
        $types = $department ? self::taskTypesForDepartment($department->name) : [];

        return $query->where(function ($q) use ($departmentId, $types) {
            $q->where('department_id', $departmentId);
            if (!empty($types)) {
                $q->orWhereIn('type', $types);
            }
        });
    }

    public function scopeByEnquiry($query, $enquiryId)
    {
        return $query->where('project_enquiry_id', $enquiryId);
    }

    // Additional scopes for enhanced functionality
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByAssignedUser($query, $userId)
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())->where('status', '!=', 'completed');
    }

    /**
     * Scope to filter tasks by assigned user (uses pivot table)
     */
    public function scopeAssignedToUser($query, $userId)
    {
        return $query->whereHas('assignedUsers', function($q) use ($userId) {
            $q->where('users.id', $userId);
        });
    }


    /**
     * Primary (owning) department name for a task type, or null if unmapped.
     * This is the department a task is routed to via department_id.
     */
    public static function primaryDepartmentName(string $type): ?string
    {
        $mapped = self::TASK_TYPE_DEPARTMENT_MAPPING[$type] ?? null;
        if (is_array($mapped)) {
            return $mapped[0] ?? null;
        }
        return $mapped; // tolerate a legacy string value
    }

    /**
     * All departments associated with a task type (primary first, then
     * collaborators). Always returns an array.
     */
    public static function departmentNamesForType(string $type): array
    {
        $mapped = self::TASK_TYPE_DEPARTMENT_MAPPING[$type] ?? [];
        return is_array($mapped) ? $mapped : [$mapped];
    }

    /**
     * Reverse lookup: task types that a given department works on (as primary
     * owner or collaborator). Powers department boards and pool-based access.
     */
    public static function taskTypesForDepartment(string $departmentName): array
    {
        $types = [];
        foreach (self::TASK_TYPE_DEPARTMENT_MAPPING as $type => $departments) {
            if (in_array($departmentName, (array) $departments, true)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    // Helper method to get the primary (owning) department for this task's type.
    public function getMappedDepartment()
    {
        $departmentName = self::primaryDepartmentName($this->type);
        if ($departmentName) {
            return Department::where('name', $departmentName)->first();
        }
        return null;
    }

    /**
     * Titles of prerequisite tasks (config: enquiry_workflow.task_dependencies)
     * that exist on this enquiry but are not yet completed/skipped. An empty
     * array means the task is unblocked and may proceed.
     *
     * Only prerequisites that actually exist as tasks on the enquiry are
     * considered, so presets that omit a task type are unaffected. This is the
     * single source of truth for workflow ordering — used by the workflow
     * service (to gate completion and announce unblocked tasks) and the API
     * resource (to surface coordination state to the UI).
     */
    public function blockingPrerequisiteTitles(): array
    {
        $prerequisites = config('enquiry_workflow.task_dependencies', [])[$this->type] ?? [];

        if (empty($prerequisites)) {
            return [];
        }

        return static::where('project_enquiry_id', $this->project_enquiry_id)
            ->whereIn('type', $prerequisites)
            ->where('status', '!=', 'completed')
            ->pluck('title')
            ->all();
    }

    /**
     * Accessor to check if the current authenticated user is authorized to interact with this task.
     */
    public function getIsAuthorizedAttribute(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        
        return $this->isUserAuthorized($user);
    }
}
