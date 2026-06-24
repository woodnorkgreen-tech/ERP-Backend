<?php

namespace App\Modules\HR\Support\Pdf;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Centralises authorization for HR document/report downloads.
 *
 * Replaces the previously duplicated `hasAnyRole([...])` arrays scattered across
 * report controllers with a single definition, plus an ownership check so an
 * employee can fetch their own documents (payslip, P9, time statement) without
 * needing a privileged HR role.
 */
trait AuthorizesHrDocuments
{
    /** Roles allowed to access any HR document. */
    protected array $hrPrivilegedRoles = ['Super Admin', 'Admin', 'HR'];

    /**
     * Require a privileged HR role (optionally widened with extra roles for
     * reports that managers/leads may also pull).
     */
    protected function ensureHrAccess(array $extraRoles = []): void
    {
        $user = auth()->user();

        if (! $user || ! $user->hasAnyRole(array_merge($this->hrPrivilegedRoles, $extraRoles))) {
            throw new AuthorizationException('You are not authorized to access this HR document.');
        }
    }

    /**
     * Allow privileged HR users OR the employee who owns the record.
     */
    protected function ensureHrAccessOrOwner(?int $employeeId, array $extraRoles = []): void
    {
        $user = auth()->user();

        if ($user && $employeeId && (int) $user->employee_id === (int) $employeeId) {
            return;
        }

        $this->ensureHrAccess($extraRoles);
    }
}
