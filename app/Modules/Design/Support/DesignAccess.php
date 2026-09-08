<?php

namespace App\Modules\Design\Support;

use App\Models\User;

class DesignAccess
{
    public static function userCanAccessLeadViews(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        $departmentName = $user->department?->name ?? $user->employee?->department?->name;
        $isDesignDepartment = $departmentName === 'Design/Creatives';

        return $isDesignDepartment && $user->isDeptLead();
    }
}
