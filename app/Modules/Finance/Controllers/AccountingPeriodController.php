<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Services\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Accounting periods — the months the ledger posts into.
 *
 * Until now a period could only be operated by a developer: `finance:close-period`
 * at a terminal, or an UPDATE against the table. Every one of the 36 seeded
 * periods was therefore still `open`, the guard that refuses a posting into a
 * shut month had never fired, and a cost could be back-dated into January 2024.
 *
 * A control nobody can operate is not a control. These endpoints hand the same
 * checklist the command runs to the people who own the decision.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(private PeriodCloseService $periods)
    {
    }

    /**
     * The months, newest first, with today's marked.
     *
     * Reading periods is gated on reporting rather than on managing them: a
     * person reading a trial balance needs to know which months are final, and
     * that is not the same authority as being able to make one final.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_REPORTS_VIEW)
                || $request->user()?->can(Permissions::FINANCE_PERIODS_MANAGE),
            403,
        );

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'status' => ['nullable', 'string', 'in:open,locked,closed'],
        ]);

        $query = AccountingPeriod::query()
            ->orderByDesc('year')
            ->orderByDesc('month');

        if ($year = $filters['year'] ?? null) {
            $query->where('year', $year);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $current = AccountingPeriod::forDate(now());

        return response()->json([
            'status' => 'success',
            'data' => $query->get()->map(fn (AccountingPeriod $period) => [
                'id' => $period->id,
                'year' => $period->year,
                'month' => $period->month,
                'label' => $period->starts_on->format('F Y'),
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'status' => $period->status,
                'locked_at' => $period->locked_at?->toIso8601String(),
                'reopened_at' => $period->reopened_at?->toIso8601String(),
                'reopen_reason' => $period->reopen_reason,
                'is_current' => $current && $current->id === $period->id,
            ]),
        ]);
    }

    /**
     * What is still unfinished in a month, without changing anything.
     *
     * The equivalent of `--dry-run`, and the screen's main view: Finance opens
     * this before deciding, which is the entire point of having a checklist
     * rather than a button.
     */
    public function checklist(Request $request, AccountingPeriod $period): JsonResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_REPORTS_VIEW)
                || $request->user()?->can(Permissions::FINANCE_PERIODS_MANAGE),
            403,
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->periods->checklist($period),
        ]);
    }

    /**
     * Declare a month final.
     *
     * `force` is accepted but never defaulted, and the response says plainly
     * when it was used — a forced close is a decision somebody made over a
     * warning, and it should read that way afterwards.
     */
    public function close(Request $request, AccountingPeriod $period): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PERIODS_MANAGE), 403);

        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->periods->close(
                $period,
                $request->user()->id,
                (bool) ($validated['force'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $this->periods->checklist($period),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['forced']
                ? $period->starts_on->format('F Y') . ' closed over ' . count($result['blockers']) . ' outstanding item(s).'
                : $period->starts_on->format('F Y') . ' is closed. Nothing more can be posted into it.',
            'data' => $result,
        ]);
    }

    /** Freeze a month during review, without declaring it final. */
    public function lock(Request $request, AccountingPeriod $period): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PERIODS_MANAGE), 403);

        try {
            $this->periods->lock($period, $request->user()->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $period->starts_on->format('F Y') . ' is locked. Postings are refused while you review it.',
        ]);
    }

    /**
     * Reopen a locked or closed month.
     *
     * A reason is required and is stored on the period. Reopening a month that
     * has been reported on is exactly what an auditor asks about, so the record
     * has to answer without anyone reconstructing it from memory.
     */
    public function reopen(Request $request, AccountingPeriod $period): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PERIODS_MANAGE), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->periods->reopen($period, $request->user()->id, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $period->starts_on->format('F Y') . ' is open again. The reason is recorded against the period.',
        ]);
    }
}
