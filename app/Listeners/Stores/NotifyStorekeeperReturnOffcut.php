<?php

namespace App\Listeners\Stores;

use App\Events\Stores\OffcutRegistered;
use App\Models\User;
use App\Notifications\Stores\OffcutReturnRequiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStorekeeperReturnOffcut implements ShouldQueue
{
    public function handle(OffcutRegistered $event): void
    {
        User::role('Stores')
            ->whereNotNull('onesignal_player_id')
            ->get()
            ->each(fn (User $user) => $user->notify(
                new OffcutReturnRequiredNotification($event->offcut)
            ));
    }
}
