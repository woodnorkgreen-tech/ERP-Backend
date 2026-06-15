<?php

namespace App\Modules\Projects\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Constants\EnquiryConstants;

trait AuthorizedVisibility
{
    /**
     * Scope a query to only include tasks the user is authorized to see.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAuthorized(Builder $query)
    {
        // Transparency First: All authenticated users can see all tasks
        // across all project phases. Interaction gating is handled 
        // separately via the 'is_authorized' flag/readonly prop.
        return $query;
    }

    /**
     * Check if a user is authorized to interact with this task.
     *
     * @param mixed $user
     * @return bool
     */
    public function isUserAuthorized($user)
    {
        if (!$user) {
            return false;
        }

        // 1. Administrators see and interact with everything
        if ($user->hasRole(EnquiryConstants::ROLES_ADMIN)) {
            return true;
        }

        // 2. Personally assigned
        if ($this->assigned_user_id === $user->id || $this->assigned_to === $user->id) {
            return true;
        }

        // Check pivot table assignments. Use the loaded relation when present so
        // task lists (which eager-load assignedUsers) don't fire a query per row.
        $assignedViaPivot = $this->relationLoaded('assignedUsers')
            ? $this->assignedUsers->contains('id', $user->id)
            : $this->assignedUsers()->where('users.id', $user->id)->exists();
        if ($assignedViaPivot) {
            return true;
        }

        // 3. Role-based visibility/interaction
        foreach (EnquiryConstants::TASK_VISIBILITY_MAPPING as $role => $types) {
            if ($user->hasRole($role) && in_array($this->type, $types)) {
                return true;
            }
        }

        // 4. Department-pool access: the task's owning department, plus any
        //    department its task type collaborates with (TASK_TYPE_DEPARTMENT_MAPPING).
        if ($user->department_id) {
            if ($this->department_id && (int) $this->department_id === (int) $user->department_id) {
                return true;
            }

            $userDepartmentName = $user->department?->name;
            if ($userDepartmentName
                && in_array($userDepartmentName, \App\Modules\Projects\Models\EnquiryTask::departmentNamesForType($this->type), true)) {
                return true;
            }
        }

        return false;
    }
}
