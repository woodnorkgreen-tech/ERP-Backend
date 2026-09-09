<?php

namespace App\Services\Governance;

use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Support\Facades\Log;

/**
 * Records that a project's budget moved after money was already committed to it.
 *
 * There are two ways out of an over-budget block, and until this existed only
 * one of them left a trace. Authorising the overrun demands a permission, a
 * written reason and an audit row (see ExpenditureLimitPolicy and the
 * `expenditure_exception` event). Raising the budget until the block disappeared
 * cost nothing and recorded nothing — so the expensive, accountable door was the
 * honest one and the free door was the quiet one. People take the quiet door.
 *
 * WHY THIS IS NOT A GATE ON SAVING THE BUDGET.
 * The budget screen server-autosaves every two seconds while somebody is typing
 * (BudgetTask.vue). Demanding a reason at write time would reject a save
 * mid-keystroke and lose the user's work, and blocking budget edits is precisely
 * the friction that was deliberately removed on 2026-07-07 when internal budget
 * approval was retired. So this does not stop anything. It observes.
 *
 * WHAT IT OBSERVES, AND WHY THE LEDGER ALREADY HALF-KNEW.
 * `cost_lines` is already an append-only history of the budget: CostCollectorService
 * never edits a planned line in place — it reverses the old row ("Superseded by a
 * revised project budget line") and writes a new one, and BudgetProjector retires
 * removed lines the same way. So *what* the budget was, and when, was always
 * recoverable. What was missing is that nobody was told, nobody could see it as
 * an event, and no one recorded whether the change had quietly erased an
 * outstanding overrun. That last case is the whole point of this class.
 *
 * ONLY ONCE MONEY IS COMMITTED.
 * A budget with no spend against it is being written, not revised — editing it is
 * authorship and recording it would be noise, on top of an autosave that would
 * generate a row every couple of seconds. Once verified exposure exists, the same
 * edit changes a figure that governs approvals, and that is an event.
 */
class BudgetRevisionRecorder
{
    /** Successive saves by one person inside this window read as one revision. */
    private const COALESCE_MINUTES = 10;

    public const EVENT = 'budget_revised';

    /**
     * @param  string  $before  planned total before projection, 2dp
     * @param  string  $after   planned total after projection, 2dp
     */
    public function record(
        ProjectEnquiry $enquiry,
        string $before,
        string $after,
        ?int $actorId,
        ?int $budgetTaskId = null,
    ): ?GovernanceAuditLog {
        if (bccomp($before, $after, 2) === 0) {
            return null;
        }

        $exposure = $this->exposureFor($enquiry);

        // Nothing is riding on this budget yet, so moving it is authorship.
        if (bccomp($exposure, '0.00', 2) !== 1) {
            return null;
        }

        try {
            $existing = $this->openRevisionFor($enquiry, $actorId);

            // Keep the original starting figure when amending: the honest unit is
            // "the budget moved from what it was when this person started to what
            // it is now", not the last two-second autosave step.
            $from = $existing ? (string) ($existing->context['from'] ?? $before) : $before;

            $context = [
                'from' => $from,
                'to' => $after,
                'delta' => bcsub($after, $from, 2),
                'exposure' => $exposure,
                'budget_task_id' => $budgetTaskId,
                // The case this class exists for: the job was over budget before
                // the change and is not after it. A block disappeared, and the
                // reason it disappeared is that the line moved, not that the
                // spending changed.
                'cleared_an_overrun' => bccomp($exposure, $from, 2) === 1
                    && bccomp($exposure, $after, 2) !== 1,
            ];

            $message = 'Project budget revised from KES '.number_format((float) $from, 2)
                .' to KES '.number_format((float) $after, 2)
                .' with KES '.number_format((float) $exposure, 2).' already committed or spent.'
                .($context['cleared_an_overrun'] ? ' This revision cleared an outstanding overrun.' : '');

            if ($existing) {
                $existing->update(['context' => $context, 'message' => $message]);

                return $existing;
            }

            return GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => $actorId,
                'gate_type' => self::EVENT,
                'action_status' => 'authorized',
                'message' => $message,
                'context' => $context,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Same rule as every other audit write on this path: it observes a
            // budget projection and must never be able to fail one.
            Log::error('Governance: failed to record budget revision', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** The most recent revision still inside the coalescing window, same actor. */
    private function openRevisionFor(ProjectEnquiry $enquiry, ?int $actorId): ?GovernanceAuditLog
    {
        return GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)
            ->where('gate_type', self::EVENT)
            ->where('created_at', '>=', now()->subMinutes(self::COALESCE_MINUTES))
            ->when($actorId === null, fn ($q) => $q->whereNull('user_id'))
            ->when($actorId !== null, fn ($q) => $q->where('user_id', $actorId))
            ->latest('id')
            ->first();
    }

    /** Committed + accrued + actual, verified only — the same sum the gate uses. */
    private function exposureFor(ProjectEnquiry $enquiry): string
    {
        return (string) CostLine::query()
            ->counting()
            ->where('project_enquiry_id', $enquiry->id)
            ->whereIn('nature', [
                CostLine::NATURE_COMMITTED,
                CostLine::NATURE_ACCRUED,
                CostLine::NATURE_ACTUAL,
            ])
            ->sum('net_amount');
    }

    /** The planned total as the cost ledger currently holds it, 2dp. */
    public function plannedTotalFor(?int $enquiryId): string
    {
        if (! $enquiryId) {
            return '0.00';
        }

        return bcadd((string) CostLine::query()
            ->counting()
            ->where('project_enquiry_id', $enquiryId)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->sum('net_amount'), '0', 2);
    }
}
