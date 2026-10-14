<?php

namespace App\Modules\Printing\Support;

use App\Models\User;
use App\Modules\HR\Models\Department;

final class PrintingAccess
{
    public static function isLead(?User $user): bool
    {
        return $user?->employee_id !== null
            && Department::query()
                ->where('name', 'Printing')
                ->where('manager_id', $user->employee_id)
                ->exists();
    }

    public static function allows(?User $user, string $permission, bool $lead = false): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $user->can($permission) && (! $lead || self::isLead($user));
    }
}
