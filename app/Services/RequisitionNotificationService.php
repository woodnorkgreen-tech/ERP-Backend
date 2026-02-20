<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\RequisitionPendingNotification;

class RequisitionNotificationService
{
    public static function notifyApprovers(
        string $requisitionNumber,
        string $requestedBy,
        string $urgency = 'normal'
    ): void {
        // Find all users who can approve (Finance/Accounts/Super Admin roles)
        $approvers = User::whereHas('roles', function ($query) {
            $query->whereIn('name', [
                'Super Admin',
                'Finance',
                'Accounts',
            ]);
        })
        ->whereNotNull('onesignal_player_id')
        ->where('is_active', true)
        ->get();

        foreach ($approvers as $approver) {
            $approver->notify(new RequisitionPendingNotification(
                $requisitionNumber,
                $requestedBy,
                $urgency
            ));
        }
    }
}