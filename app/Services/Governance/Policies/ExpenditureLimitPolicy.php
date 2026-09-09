<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Services\Governance\GateResult;

/**
 * Will this commitment take the project past its budget?
 *
 * Answered from `cost_lines`, the project cost account, and nowhere else.
 *
 * This policy used to build its own budget and its own commitment total from
 * sources the rest of Finance does not use: `budget_summary.grandTotal` for the
 * budget, falling back to `project_enquiries.budget` — a client-stated figure
 * captured at enquiry, next to `estimated_budget`, which is not a cost budget at
 * all — and a hand-rolled sum of purchase orders and procurement requisitions
 * for the exposure. Three consequences, all of which reached users:
 *
 *  1. Petty cash was invisible to the check that gates petty cash. An approved
 *     requisition has been a `committed` cost line since RecordPettyCashCommitment
 *     shipped, and this policy counted none of them — so a job could be approved
 *     past its budget one requisition at a time, each one blind to the last.
 *  2. The purchase-order sum read `where('requisition_id', fn ($q) => …)`, which
 *     compiles to `requisition_id = (subquery)`. On any job with more than one
 *     procurement requisition MySQL raised 1242, ProjectGovernanceService caught
 *     it, and the user was told the governance system was broken.
 *  3. Budget and exposure came from different places, so the figures in the
 *     block message could not be reconciled against the cost account screen that
 *     Finance actually works from.
 *
 * `counting()` is what makes this correct without any release logic here: a
 * commitment settled by a payment, or withdrawn when a requisition went back to
 * pending, is reversed rather than deleted, and a reversed line is not verified.
 * So committed + accrued + actual is exposure with nothing double counted —
 * the same sum CostAccountService reports.
 */
class ExpenditureLimitPolicy extends BasePolicy
{
    /** Exposure: money promised, received-not-invoiced, and spent. */
    private const EXPOSURE_NATURES = [
        CostLine::NATURE_COMMITTED,
        CostLine::NATURE_ACCRUED,
        CostLine::NATURE_ACTUAL,
    ];

    protected function runPolicy(ProjectEnquiry $enquiry, array $context = []): GateResult
    {
        $budget = $this->budgetFor($enquiry);

        if ($budget['amount'] <= 0) {
            return GateResult::blocked(
                'Project budget is KES 0.00. Finalize the project budget before approving this financial commitment.',
                ['budget' => 0.0, 'budget_source' => $budget['source']],
            );
        }

        $exposure = $this->exposureFor($enquiry, $context);
        $proposed = (float) ($context['amount'] ?? 0);
        $newTotal = $exposure + $proposed;

        $figures = [
            'budget' => $budget['amount'],
            'budget_source' => $budget['source'],
            'current_commitment' => $exposure,
            'proposed' => $proposed,
            'projected_exposure' => $newTotal,
            // Whether this budget has recently been moved, and by whom.
            //
            // The figure being measured against is not a constant, and someone
            // asked to authorise an overrun deserves to know if the line has
            // already been walked once to accommodate this spend. It is the
            // difference between "we are over budget" and "we are over budget
            // again, having raised it on Tuesday".
            'last_revision' => $this->lastRevisionFor($enquiry),
        ];

        if ($newTotal > $budget['amount']) {
            $overage = $newTotal - $budget['amount'];

            return GateResult::blocked(
                'This commitment (KES '.number_format($proposed, 2).') would exceed the project budget by KES '
                    .number_format($overage, 2).'. Budget: KES '.number_format($budget['amount'], 2)
                    .'. Already committed or spent: KES '.number_format($exposure, 2).'.',
                $figures + ['overage' => $overage],
            );
        }

        return GateResult::authorized($figures + ['remaining' => $budget['amount'] - $newTotal]);
    }

    /**
     * The budget, preferring the projected cost ledger over the budget JSON.
     *
     * Planned lines are the same budget — BudgetProjector mirrors task_budget_data
     * into them — but they are the version the cost account, the variance report
     * and `consumes_line_id` all work against, and they drop retired lines that
     * the JSON summary can still be carrying.
     *
     * The JSON summary stays as a fallback for one honest case: a budget saved
     * before projection existed, or one whose projection has not run. Reading
     * zero there and hard-blocking would turn a system gap into an accusation
     * against the person trying to approve. `source` is recorded so a figure that
     * later looks wrong can be traced to where it came from.
     *
     * @return array{amount: float, source: string}
     */
    private function budgetFor(ProjectEnquiry $enquiry): array
    {
        $planned = (float) CostLine::query()
            ->counting()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->sum('net_amount');

        if ($planned > 0) {
            return ['amount' => $planned, 'source' => 'cost_ledger'];
        }

        $budgetTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->where('type', 'budget')
            ->first();

        if (! $budgetTask) {
            return ['amount' => 0.0, 'source' => 'none'];
        }

        $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)
            ->latest()
            ->first();

        $summary = $budgetData?->budget_summary ?? [];
        $total = $summary['grandTotal'] ?? $summary['grand_total'] ?? null;

        return $total === null
            ? ['amount' => 0.0, 'source' => 'none']
            : ['amount' => (float) $total, 'source' => 'budget_summary'];
    }

    /**
     * The most recent recorded movement of this project's budget, if any.
     *
     * Written by BudgetRevisionRecorder, which observes the projection rather
     * than gating the save — the budget screen autosaves every two seconds, so
     * there is no write to hold. Absent for the great majority of jobs: a budget
     * revised before any money was committed is authorship, not a revision, and
     * is deliberately not recorded.
     *
     * @return array<string, mixed>|null
     */
    private function lastRevisionFor(ProjectEnquiry $enquiry): ?array
    {
        $row = \App\Models\GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)
            ->where('gate_type', \App\Services\Governance\BudgetRevisionRecorder::EVENT)
            ->latest('id')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'from' => (float) ($row->context['from'] ?? 0),
            'to' => (float) ($row->context['to'] ?? 0),
            'cleared_an_overrun' => (bool) ($row->context['cleared_an_overrun'] ?? false),
            'at' => $row->created_at?->toIso8601String(),
            'by' => $row->user?->name,
        ];
    }

    /**
     * What the job is already carrying.
     *
     * `exclude_source` lets the caller take its own open line out of the sum.
     * A requisition edited after approval goes back to pending, which releases
     * its commitment — but that release is queued, so on re-approval the line may
     * still be open and the job would be charged for the same requisition twice,
     * blocking an approval on the strength of itself.
     */
    private function exposureFor(ProjectEnquiry $enquiry, array $context): float
    {
        return (float) CostLine::query()
            ->counting()
            ->where('project_enquiry_id', $enquiry->id)
            ->whereIn('nature', self::EXPOSURE_NATURES)
            // Excluded by id via a subquery, not by negating the source match.
            // `NOT (source_type = ? AND source_id = ?)` is NULL for every line
            // that carries no source — a cost captured by hand, a planned line —
            // and a NULL predicate filters the row out. That silently dropped
            // every hand-captured cost from the exposure the moment an exclusion
            // was in play, which is the opposite of what this is for.
            ->when(
                $context['exclude_source_type'] ?? null,
                fn ($q, $type) => $q->whereNotIn('id', CostLine::query()
                    ->select('id')
                    ->where('source_type', $type)
                    ->where('source_id', $context['exclude_source_id'] ?? 0)),
            )
            ->sum('net_amount');
    }
}
