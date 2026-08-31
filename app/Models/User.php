<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;
      protected $with = ['roles'];
      protected $appends = ['is_manager', 'is_dept_lead'];

    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id',
        'department_id',
        'is_active',
        'last_login_at',
        'onesignal_player_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Virtual attributes for hierarchy awareness.
     */
    public function getIsManagerAttribute(): bool
    {
        return $this->isManager();
    }

    public function getIsDeptLeadAttribute(): bool
    {
        return $this->isDeptLead();
    }

    /**
     * Get the department that the user belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Department::class);
    }

    /**
     * Get the employee record associated with this user.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Employee::class);
    }

    /**
     * Scope to filter active users.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Employee statuses that make someone ineligible for new work.
     *
     * 'on-leave' is deliberately absent: someone on leave still holds their
     * projects and comes back to them. 'suspended' is likewise left assignable
     * so a disciplinary hold does not silently rewrite project ownership —
     * add it here if the business wants suspension to block new assignment.
     */
    public const NON_ASSIGNABLE_EMPLOYEE_STATUSES = ['terminated', 'inactive'];

    /**
     * Scope to users who may be given new work.
     *
     * Deactivation lives in two independent places — the user account
     * (is_active / soft delete) and the employee record (status) — and a person
     * can be shut off in one without the other, so both must be checked. This
     * scope is the single definition; callers should not re-implement it.
     *
     * Note this governs *new* assignment only. Existing assignments to someone
     * who has since left are left alone rather than silently cleared, so they
     * stay visible and can be reassigned deliberately.
     */
    public function scopeAssignable($query)
    {
        return $query
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                // Users without an employee record (system/admin accounts) stay
                // assignable — there is no employment status to disqualify them.
                $q->whereNull('employee_id')
                  ->orWhereHas('employee', function ($e) {
                      $e->where(function ($inner) {
                          $inner->whereNull('status')
                                ->orWhereNotIn('status', self::NON_ASSIGNABLE_EMPLOYEE_STATUSES);
                      });
                  });
            });
    }

    /**
     * Scope to filter users by department.
     */
    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Get user's accessible departments based on role and leadership.
     */
    public function getAccessibleDepartments()
    {
        if ($this->hasRole(['Super Admin', 'HR'])) {
            return \App\Modules\HR\Models\Department::all();
        }

        $managedDepartmentIds = \App\Modules\HR\Models\Department::where('manager_id', $this->employee_id)->pluck('id');
        
        if ($managedDepartmentIds->isNotEmpty()) {
            return \App\Modules\HR\Models\Department::whereIn('id', $managedDepartmentIds->merge([$this->department_id])->unique())->get();
        }

        if ($this->department) {
            return collect([$this->department]);
        }

        return collect();
    }

    /**
     * Check if user is a Departmental Lead.
     */
    public function isDeptLead(): bool
    {
        if (!$this->employee_id) return false;
        return \App\Modules\HR\Models\Department::where('manager_id', $this->employee_id)->exists();
    }

    /**
     * Check if user is a Manager (has direct reports).
     */
    public function isManager(): bool
    {
        if (!$this->employee_id) return false;
        return \App\Modules\HR\Models\Employee::where('manager_id', $this->employee_id)->exists();
    }

    /**
     * Check if user can access a specific department.
     */
    public function canAccessDepartment($departmentId): bool
    {
        if ($this->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $this->department_id === $departmentId;
    }

    /**
     * Get user's navigation permissions and accessible modules.
     */
    public function getNavigationPermissions(): array
    {
        $permissions = [
            'can_access_admin' => $this->hasRole(['Super Admin', 'Admin']),
            'can_access_hr' => $this->hasRole(['Super Admin', 'Manager', 'Employee', 'HR']),
            'can_access_creatives' => $this->hasRole(['Super Admin', 'Designer']) ||
                                      ($this->department && strtolower($this->department->name) === 'creatives'),
            'can_manage_users' => $this->can('user.create') || $this->can('user.update'),
            'can_manage_employees' => $this->can('employee.read'),
            'can_manage_departments' => $this->can('department.read'),
            'can_view_reports' => $this->can('admin.access'),
            'is_manager' => $this->isManager(),
            'is_dept_lead' => $this->isDeptLead(),
            'accessible_departments' => $this->getAccessibleDepartments()->pluck('id')->toArray(),
            'user_department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name
            ] : null
        ];

        return $permissions;
    }
    public function routeNotificationForOneSignal(): string
    {
        return $this->onesignal_player_id;
    }

    /**
     * Get the notifications for the user using the custom Notification model.
     */
    public function appNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->universalNotifications();
    }

    public function universalNotifications(): HasMany
    {
        return $this->hasMany(\App\Modules\Notifications\Models\AppNotification::class);
    }

    public function appNotificationPreferences(): HasMany
    {
        return $this->hasMany(\App\Modules\Notifications\Models\AppNotificationPreference::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(\App\Modules\Notifications\Models\UserDeviceToken::class);
    }
}
