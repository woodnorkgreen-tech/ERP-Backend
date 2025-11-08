<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\HR\Models\Department;

class EnquiryTask extends Model
{
    use HasFactory;

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
        // Backward compatibility fields
        'assigned_at',
        'assigned_by',
        'assigned_to',
    ];

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

    // Task type to department mapping
    const TASK_TYPE_DEPARTMENT_MAPPING = [
        'site-survey' => 'Client Service',
        'design' => 'Design/Creatives',
        'materials' => 'Procurement',
        'budget' => 'Accounts/Finance',
        'quote' => 'Costing',
        'production' => 'Production',
        'logistics' => 'Logistics',
        'stores' => 'Stores',
        'project_management' => 'Projects',
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

    public function designAssets()
    {
        return $this->hasMany(\App\Models\DesignAsset::class, 'enquiry_task_id');
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

    // Helper method to get department for task type
    public function getMappedDepartment()
    {
        $departmentName = self::TASK_TYPE_DEPARTMENT_MAPPING[$this->type] ?? null;
        if ($departmentName) {
            return Department::where('name', $departmentName)->first();
        }
        return null;
    }
}
