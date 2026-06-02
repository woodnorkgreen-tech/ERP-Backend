<?php

namespace App\Listeners\Stores;

use App\Events\Stores\BoardRequestRaised;
use App\Models\User;
use App\Notifications\Stores\BoardRequestPendingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStorekeepersOfPendingRequest implements ShouldQueue
{
    public function handle(BoardRequestRaised $event): void
    {
        User::role('Stores')
            ->whereNotNull('onesignal_player_id')
            ->get()
            ->each(fn (User $user) => $user->notify(
                new BoardRequestPendingNotification($event->boardRequest)
            ));
    }
}
