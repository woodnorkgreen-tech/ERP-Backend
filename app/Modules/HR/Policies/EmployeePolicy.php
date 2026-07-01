<?php

namespace App\Modules\HR\Policies;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Employee;

/**
 * Single source of truth for who may act on an Employee record.
 *
 * Replaces the scattered hasRole(['Admin','HR','Super Admin']) checks that lived
 * inside EmployeeController, and mirrors the OvertimePolicy pattern already adopted
 * for overtime entries.
 *
 * Hierarchy (loosest → strictest):
 *   Super Admin  →  omnipotent (via before())
 *   Admin / HR   →  full CRUD + PII + salary
 *   Project Officer / Project Manager  →  read (roster visibility only)
 *   Department manager / direct-report manager  →  read own department/reports; no PII/salary
 *   Employee (own record)  →  read + self-update (profile only)
 */
class EmployeePolicy
{
    /**
     * Super Admin bypasses every ability check.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('Super Admin') ? true : null;
    }

    // ── CRUD abilities ─────────────────────────────────────────────────────────

    /**
     * List employees (roster). Project Officers/Managers and department managers
     * may list employees they are scoped to; the scope itself is enforced by
     * scopeAccessibleByUser() in the query — the policy just answers "can you list at all?"
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEE_READ) || $this->isHrAdmin($user);
    }

    /**
     * View a single employee record. Requires the record to also be in-scope for the user.
     * PII and salary masking are handled separately in the controller/resource layer.
     */
    public function view(User $user, Employee $employee): bool
    {
        if (! ($user->can(Permissions::EMPLOYEE_READ) || $this->isHrAdmin($user))) {
            return false;
        }

        return $employee->isAccessibleBy($user);
    }

    /**
     * Create a new employee record.
     */
    public function create(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEE_CREATE) || $this->isHrAdmin($user);
    }

    /**
     * Update an employee record. Department managers may update records in their
     * department (e.g. status, position), but only HR admins may touch salary/PII.
     */
    public function update(User $user, Employee $employee): bool
    {
        if (! ($user->can(Permissions::EMPLOYEE_UPDATE) || $this->isHrAdmin($user))) {
            return false;
        }

        return $employee->isAccessibleBy($user);
    }

    /**
     * Terminate (soft-delete) an employee. Restricted to HR administration —
     * department managers may not unilaterally terminate staff.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->can(Permissions::EMPLOYEE_DELETE) || $this->isHrAdmin($user);
    }

    /**
     * Restore a soft-deleted (terminated) employee — i.e. reinstatement.
     * Only HR admins may reinstate.
     */
    public function restore(User $user, Employee $employee): bool
    {
        return $this->isHrAdmin($user);
    }

    // ── Specific data-visibility abilities ────────────────────────────────────

    /**
     * View salary and bank account fields.
     */
    public function viewSalary(User $user, Employee $employee): bool
    {
        return $user->can(Permissions::EMPLOYEE_VIEW_SALARY)
            || $this->isHrAdmin($user)
            || ($user->employee_id !== null && $user->employee_id === $employee->id);
    }

    /**
     * View regulated personal data: national ID, KRA PIN, NSSF/NHIF,
     * date of birth, home address, emergency contact.
     * Only HR admins and the employee themselves may access this.
     */
    public function viewPii(User $user, Employee $employee): bool
    {
        return $this->isHrAdmin($user)
            || ($user->employee_id !== null && $user->employee_id === $employee->id);
    }

    /**
     * Upload/replace another employee's profile photo.
     * Employees may always update their own photo; HR admins may update anyone's.
     */
    public function uploadPhoto(User $user, Employee $employee): bool
    {
        if ($user->employee_id !== null && $user->employee_id === $employee->id) {
            return true;
        }

        return $this->isHrAdmin($user);
    }

    // ── Shared predicates ─────────────────────────────────────────────────────

    /**
     * "Global HR" tier — Admin and HR roles have full personnel management rights.
     * Super Admin is handled by before() and never reaches here.
     */
    private function isHrAdmin(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'HR']);
    }
}
