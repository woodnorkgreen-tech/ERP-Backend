<?php

namespace App\Modules\ProcurementStores\Traits;

use Illuminate\Validation\Validator;

trait ValidatesRequests
{
    /**
     * Validate request data and return errors if validation fails.
     * Returns null if validation passes, error response array if fails.
     */
    protected function validateRequestData(array $data, array $rules, array $messages = []): ?array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            return [
                'error' => $validator->errors()->toArray(),
            ];
        }

        return null;
    }

    /**
     * Check if authenticated user has required role for approvals/deletions.
     * Allowed roles can be configured or passed as parameter.
     */
    protected function canApproveOrDelete(?array $allowedRoles = null): bool
    {
        $user = auth()->user();
        if (!$user || !$user->roles) {
            return false;
        }

        $defaultRoles = ['Super Admin', 'Admin', 'Accounts'];
        $roles = $allowedRoles ?? $defaultRoles;

        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has specific role.
     */
    protected function userHasRole(string $role): bool
    {
        $user = auth()->user();
        if (!$user || !$user->roles) {
            return false;
        }

        return $user->roles->pluck('name')->contains($role);
    }

    /**
     * Get authenticated user with relationships.
     */
    protected function getAuthenticatedUser()
    {
        return auth()->user();
    }
}
