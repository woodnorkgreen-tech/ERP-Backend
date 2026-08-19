<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;

class SupportMetricsService
{
    /**
     * Desk-level service figures for the queue header.
     *
     * Every figure is scoped through `visibleTo`, so a requester sees the
     * service level on their own tickets and the desk sees the whole queue.
     * Unlike the per-request counters on the index endpoint, these are NOT
     * affected by the active search/status filters — they describe the desk,
     * not the current view.
     */
    public function forUser(User $user): array
    {
        $now = now();
        $weekStart = $now->copy()->subDays(7);
        $previousWeekStart = $now->copy()->subDays(14);

        $statusTotals = $this->scoped($user)
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $thisWeek = $this->scoped($user)->where('created_at', '>=', $weekStart)->count();
        $previousWeek = $this->scoped($user)
            ->whereBetween('created_at', [$previousWeekStart, $weekStart])
            ->count();

        return [
            'total' => [
                'value' => (int) $statusTotals->sum(),
                'this_week' => $thisWeek,
                // Undefined rather than +100% when the desk had no history to
                // compare against — the UI hides the trend line instead.
                'delta_pct' => $previousWeek === 0
                    ? null
                    : (int) round((($thisWeek - $previousWeek) / $previousWeek) * 100),
            ],
            'unassigned' => $this->scoped($user)
                ->whereNull('assigned_to')
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count(),
            'awaiting' => $this->awaitingFirstResponse($user),
            'resolved_today' => $this->resolvedToday($user),
            'breaches' => [
                // response_due_at was previously stored and never read; a first
                // response is the target a requester actually feels.
                'response' => $this->scoped($user)
                    ->whereNull('first_response_at')
                    ->whereNotIn('status', ['resolved', 'closed'])
                    ->where('response_due_at', '<', $now)
                    ->count(),
                'resolution' => $this->scoped($user)
                    ->whereNotIn('status', ['waiting_on_user', 'resolved', 'closed'])
                    ->where('resolution_due_at', '<', $now)
                    ->count(),
            ],
            'status_totals' => collect(SupportTicket::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($statusTotals[$status] ?? 0)])
                ->all(),
        ];
    }

    /**
     * Tickets the desk has not spoken to yet — the figure that best predicts an
     * angry follow-up. Wait is measured from submission to now.
     */
    private function awaitingFirstResponse(User $user): array
    {
        $awaiting = $this->scoped($user)
            ->whereIn('status', ['open', 'assigned'])
            ->whereNull('first_response_at');

        $count = (clone $awaiting)->count();

        return [
            'count' => $count,
            'avg_wait_minutes' => $count === 0 ? null : (int) round(
                (float) (clone $awaiting)
                    ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW())) as average')
                    ->value('average')
            ),
        ];
    }

    /**
     * Today's throughput, plus how much of it landed inside the resolution
     * target. Tickets with no target (pre-SLA records) are excluded from the
     * percentage rather than counted as misses.
     */
    private function resolvedToday(User $user): array
    {
        $resolved = $this->scoped($user)->whereDate('resolved_at', now()->toDateString());
        $measurable = (clone $resolved)->whereNotNull('resolution_due_at');
        $measurableCount = (clone $measurable)->count();

        return [
            'count' => (clone $resolved)->count(),
            'sla_hit_pct' => $measurableCount === 0 ? null : (int) round(
                ((clone $measurable)->whereColumn('resolved_at', '<=', 'resolution_due_at')->count() / $measurableCount) * 100
            ),
        ];
    }

    private function scoped(User $user): Builder
    {
        return SupportTicket::query()->visibleTo($user);
    }
}
