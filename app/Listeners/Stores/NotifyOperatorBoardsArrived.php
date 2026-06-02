<?php

namespace App\Listeners\Stores;

use App\Events\Stores\BoardsDispatchedToStation;
use App\Models\User;
use App\Notifications\Stores\BoardsArrivedAtStationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyOperatorBoardsArrived implements ShouldQueue
{
    public function handle(BoardsDispatchedToStation $event): void
    {
        // Notify the requester directly if they have a player ID
        $requesterId = $event->dispatchTask->boardRequest?->requested_by;

        if ($requesterId) {
            $requester = User::find($requesterId);
            if ($requester?->onesignal_player_id) {
                $requester->notify(new BoardsArrivedAtStationNotification(
                    $event->jobRef,
                    $event->boards->count(),
                    $event->dispatchedBy->name,
                ));
                return;
            }
        }

        // Fallback: notify all Production role users
        User::role('Production')
            ->whereNotNull('onesignal_player_id')
            ->get()
            ->each(fn (User $user) => $user->notify(
                new BoardsArrivedAtStationNotification(
                    $event->jobRef,
                    $event->boards->count(),
                    $event->dispatchedBy->name,
                )
            ));
    }
}
