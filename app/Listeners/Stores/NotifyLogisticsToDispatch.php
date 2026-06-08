<?php

namespace App\Listeners\Stores;

use App\Events\Stores\BoardRequestFulfilled;
use App\Models\User;
use App\Notifications\Stores\BoardsReadyForDispatchNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyLogisticsToDispatch implements ShouldQueue
{
    public function handle(BoardRequestFulfilled $event): void
    {
        User::role('Logistics')
            ->whereNotNull('onesignal_player_id')
            ->get()
            ->each(fn (User $user) => $user->notify(
                new BoardsReadyForDispatchNotification(
                    $event->boardRequest,
                    $event->issuedBoards->count(),
                )
            ));
    }
}
