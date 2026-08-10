<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells people what happened to a cost.
 *
 * The verification design rests on "query, not reject" — a query keeps the cost
 * alive and routes it back to whoever can answer. Without notification it routed
 * it back to nobody, and the reporter had to remember to go looking. The queue
 * had the same problem in reverse: nothing told a verifier it existed.
 *
 * Every send is wrapped. A notification that cannot be delivered must never roll
 * back the decision that triggered it — verifying a cost is the real work, and
 * telling someone about it is a courtesy that can fail on its own.
 */
class CostNotifier
{
    public function __construct(private NotificationService $notifications) {}

    /** A cost has been reported and is waiting for someone to look at it. */
    public function submitted(CostLine $line): void
    {
        $this->send(
            'cost_submitted',
            'Cost reported',
            sprintf('%s reported %s on %s.', $line->submitted_by_name ?: 'Someone',
                $this->money($line), $this->project($line)),
            $line,
            users: $this->verifierIds(),
        );
    }

    /**
     * Everyone who can act on the queue.
     *
     * Resolved here and passed as explicit recipients rather than using the
     * service's `permission:` broadcast, because that path additionally filters
     * on `userCanSeeModule`, which gates the finance module on holding a
     * *Finance* or *Accounts* ROLE. The cost collector is deliberately
     * permission-based — the whole point of replacing the hardcoded role checks
     * was that someone can be granted `finance.costs.verify` without one — so a
     * role filter would silently drop exactly the people who were granted the
     * right explicitly.
     *
     * Holding the permission is a stronger signal of "should see this" than
     * holding a role, so it is used directly.
     *
     * @return array<int, int>
     */
    private function verifierIds(): array
    {
        return User::query()
            ->permission(Permissions::FINANCE_COSTS_VERIFY)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }

    /** Sent back with a question. The reporter is the only person who can act. */
    public function queried(CostLine $line): void
    {
        $this->send(
            'cost_queried',
            'Query on your reported cost',
            sprintf('%s on %s needs clarifying: %s', $this->money($line),
                $this->project($line), $line->query_note ?: 'see the cost record'),
            $line,
            users: array_filter([$line->submitted_by_user_id]),
        );
    }

    public function verified(CostLine $line): void
    {
        $this->send(
            'cost_verified',
            'Cost verified',
            sprintf('%s on %s has been verified.', $this->money($line), $this->project($line)),
            $line,
            users: array_filter([$line->submitted_by_user_id]),
        );
    }

    public function rejected(CostLine $line): void
    {
        $this->send(
            'cost_rejected',
            'Cost rejected',
            sprintf('%s on %s was rejected: %s', $this->money($line),
                $this->project($line), $line->query_note ?: 'no reason given'),
            $line,
            users: array_filter([$line->submitted_by_user_id]),
        );
    }

    public function reversed(CostLine $line): void
    {
        $this->send(
            'cost_reversed',
            'Cost reversed',
            sprintf('%s on %s was reversed: %s', $this->money($line),
                $this->project($line), $line->query_note ?: 'no reason given'),
            $line,
            users: array_filter([$line->submitted_by_user_id]),
        );
    }

    /** @param array<int, int> $users */
    private function send(
        string $type,
        string $title,
        string $message,
        CostLine $line,
        array $users = [],
    ): void {
        // Nobody to tell is not a failure — a producer-posted cost has no human
        // reporter, and there is nothing to say to a null user.
        if (! $users) {
            return;
        }

        try {
            $this->notifications->dispatchNotification(
                type: $type,
                title: $title,
                message: $message,
                module: 'finance',
                data: [
                    'cost_line_id' => $line->id,
                    'ref' => $line->ref,
                    'job_number' => $line->job_number,
                    'net_amount' => $line->net_amount,
                    'status' => $line->status,
                ],
                users: $users,
            );
        } catch (Throwable $e) {
            // Logged at warning with the exception class, because this catch is
            // what let a dangling `permission: $permission` argument fail every
            // send in silence — a swallowed failure needs to be loud somewhere.
            Log::warning('Cost notification failed', [
                'type' => $type,
                'cost_line_id' => $line->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function money(CostLine $line): string
    {
        return 'KES ' . number_format((float) $line->net_amount, 2);
    }

    private function project(CostLine $line): string
    {
        return $line->job_number ?: 'a non-project cost';
    }
}
