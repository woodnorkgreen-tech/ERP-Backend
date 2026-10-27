<?php

namespace App\Support;

use Illuminate\Support\Collection;

class WorkTrackingSummary
{
    public static function from(Collection $sessions, bool $completed = false, bool $showStopped = false): array
    {
        $now = now();
        $active = $sessions->first(fn ($session) => $session->ended_at === null);
        $lastClosed = $sessions->first(fn ($session) => $session->ended_at !== null);
        $seconds = $sessions->sum(function ($session) {
            if (!$session->ended_at) return 0;
            return max(0, $session->started_at->diffInSeconds($session->ended_at, false));
        });

        $stopped = $showStopped && $lastClosed && in_array($lastClosed->end_type, ['completed', 'cancelled', 'rejected'], true);
        $state = $active ? 'working' : ($completed ? 'completed' : ($stopped ? 'stopped' : ($lastClosed ? 'paused' : 'not_started')));
        $rounds = $sessions->groupBy(fn ($session) => $session->revision_round ?? 'earlier')->map(function ($group, $round) {
            return [
                'revision_round' => $round === 'earlier' ? null : (int) $round,
                'active_seconds' => (int) $group->sum(fn ($session) => $session->ended_at
                    ? max(0, $session->started_at->diffInSeconds($session->ended_at, false)) : 0),
                'session_count' => $group->count(),
            ];
        })->values();

        return [
            'state' => $state,
            'active_seconds' => (int) $seconds,
            'active_started_at' => $active?->started_at,
            'active_user_id' => $active?->user_id,
            'active_user_name' => $active?->user?->name,
            'active_revision_round' => $active?->revision_round,
            'last_stop_outcome' => $stopped ? $lastClosed->end_type : null,
            'last_stop_reason' => $stopped ? $lastClosed->stop_reason : null,
            'last_pause_reason_code' => $state === 'paused' ? $lastClosed?->pause_reason_code : null,
            'last_pause_reason_details' => $state === 'paused' ? $lastClosed?->pause_reason_details : null,
            'last_paused_at' => $state === 'paused' ? $lastClosed?->ended_at : null,
            'rounds' => $rounds,
            'sessions' => $sessions->map(fn ($session) => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'user_name' => $session->user?->name,
                'started_at' => $session->started_at,
                'ended_at' => $session->ended_at,
                'end_type' => $session->end_type,
                'revision_round' => $session->revision_round,
                'stop_reason' => $session->stop_reason,
                'pause_reason_code' => $session->pause_reason_code,
                'pause_reason_details' => $session->pause_reason_details,
                'ended_by' => $session->endedBy?->name,
            ])->values(),
        ];
    }
}
