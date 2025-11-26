<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
      protected $with = ['roles'];

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
     * Scope to filter users by department.
     */
    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Get user's accessible departments based on role.
     */
    public function getAccessibleDepartments()
    {
        if ($this->hasRole('Super Admin')) {
            return \App\Modules\HR\Models\Department::all();
        }

        if ($this->hasRole('Admin')) {
            // Admin doesn't access departments directly, but can see all for admin purposes
            return collect();
        }

        // Regular users can only access their own department
        if ($this->department) {
            return collect([$this->department]);
        }

        return collect();
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
            'can_access_hr' => $this->hasRole(['Super Admin', 'Manager', 'Employee']),
            'can_access_creatives' => $this->hasRole(['Super Admin', 'Designer']) ||
                                     ($this->department && strtolower($this->department->name) === 'creatives'),
            'can_manage_users' => $this->can('user.create') || $this->can('user.update'),
            'can_manage_employees' => $this->can('employee.read'),
            'can_manage_departments' => $this->can('department.read'),
            'can_view_reports' => $this->can('admin.access'),
            'accessible_departments' => $this->getAccessibleDepartments()->pluck('id')->toArray(),
            'user_department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name
            ] : null
        ];

        return $permissions;
    }
}
