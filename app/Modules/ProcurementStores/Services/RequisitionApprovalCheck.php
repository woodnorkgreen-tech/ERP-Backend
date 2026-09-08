<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\ProcurementStores\Models\Requisition;

/**
 * What stops this requisition being approved, if anything.
 *
 * These three rules lived inside `RequisitionController::approve()` and were
 * reachable only by pressing Approve and being refused. The approver therefore
 * found out that somebody *else* had mis-classified a line at the one moment
 * they could do least about it, and the register gave no hint beforehand —
 * every pending row looked equally ready.
 *
 * Holding them here lets the register ask the same question the button will
 * ask, so a row that cannot be approved says so before it is clicked. The
 * controller still calls this as the gate; the resource calls it to describe.
 * One rule, two readers — the point is that they cannot disagree.
 *
 * Returns human-readable sentences, not codes, because both readers show them
 * to a person. The machine-readable `code` stays on the controller's response
 * for anything that wants to branch on the kind of problem.
 */
class RequisitionApprovalCheck
{
    public const CODE_UNCODED = 'EXPENSE_CODE_REQUIRED';
    public const CODE_NOT_PROCURABLE = 'EXPENSE_CODE_NOT_PROCURABLE';
    public const CODE_JOB_RULE = 'EXPENSE_CODE_JOB_RULE';

    /**
     * The first thing wrong with this requisition, or null when nothing is.
     *
     * First rather than all: they are checked in order of bluntness — is it
     * classified, is it a purchase at all, is it legal for this job — and a
     * line that fails the earlier question has not yet been asked the later
     * one, so listing them together would invent problems.
     *
     * @return array{code:string, message:string}|null
     */
    public function firstBlocker(Requisition $requisition): ?array
    {
        $items = $requisition->relationLoaded('items')
            ? $requisition->items
            : $requisition->items()->with('expenseCode')->get();

        // One query for whatever is missing, never one per line — this runs
        // once per row of the register.
        $items->loadMissing('expenseCode');

        $uncoded = $items->whereNull('expense_code_id')->count();

        if ($uncoded > 0) {
            return [
                'code' => self::CODE_UNCODED,
                'message' => "{$uncoded} item(s) need a purchase category. "
                    .'Finance must select one on every line before approval.',
            ];
        }

        /*
         * The blunter question before the job rule: is this a purchase at all?
         *
         * The catalogue has to carry codes that are not — stores issues, VAT
         * remitted, a petty-cash float top-up — because the cost collector posts
         * all of them. A purchase order cannot. `is_procurable` is the
         * catalogue's own answer, so this reads it rather than re-deciding it.
         */
        $unbuyable = $items
            ->filter(fn ($item) => $item->expenseCode && ! $item->expenseCode->is_procurable)
            ->map(fn ($item) => $item->expenseCode->expense_type)
            ->unique()
            ->values();

        if ($unbuyable->isNotEmpty()) {
            return [
                'code' => self::CODE_NOT_PROCURABLE,
                'message' => 'These categories cannot be used on a purchase order: '
                    .$unbuyable->implode(', ').'. Choose a category for goods or supplier services. '
                    .'Staff payments belong in fund requisitions or payroll.',
            ];
        }

        /*
         * ...and the code must be legal for this requisition's job context.
         * The picker is the only other gate and it derives the context from a
         * job number that can be filled in *after* lines are already coded, so
         * a requisition can arrive here carrying a code it may not carry. The
         * cost collector enforces the same rule when it posts the receipt — far
         * too late, the order has been placed by then.
         */
        $hasJob = $requisition->requested_by_type === 'project';
        $illegalRule = $hasJob ? ExpenseCode::JOB_NOT_ALLOWED : ExpenseCode::JOB_REQUIRED;

        $mismatched = $items
            ->filter(fn ($item) => $item->expenseCode?->job_id_rule === $illegalRule)
            ->map(fn ($item) => $item->expenseCode->expense_type)
            ->unique()
            ->values();

        if ($mismatched->isNotEmpty()) {
            return [
                'code' => self::CODE_JOB_RULE,
                'message' => $hasJob
                    ? 'These expense types cannot be charged to a job: '.$mismatched->implode(', ')
                        .'. Re-classify those lines before approving.'
                    : 'These expense types require a job number, and this requisition has none: '
                        .$mismatched->implode(', ').'. Re-classify those lines, or raise the '
                        .'requisition against the project instead.',
            ];
        }

        return null;
    }
}
