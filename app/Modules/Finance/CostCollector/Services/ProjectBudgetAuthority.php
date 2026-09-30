<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\TaskBudgetData;
use App\Modules\Projects\Models\EnquiryTask;

/**
 * The one definition of a project's budget, shared by W6 and W7.
 *
 * A project's budget is the `task_budget_data` row of its budget task
 * (enquiry_tasks.type = 'budget'). There is exactly one such row per budget
 * task — BudgetService saves with updateOrCreate — and one budget task per
 * project; if a project ever had more than one, the latest (highest id) wins,
 * so selection is deterministic. Snapshots in budget_versions are history, never
 * the budget itself.
 *
 * The budget is FINALIZED — authoritative for W7 labour — when its budget task
 * is `completed`. Internal budget approval was retired on 2026-07-07, and task
 * completion replaced it as the finalization signal (EnquiryWorkflowService::
 * validateTaskCompletion): completion is an explicit user action that requires a
 * saved, priced budget, autosave never completes it (AutoSyncTaskStateAction),
 * and a change to the approved materials reopens it (BudgetService::
 * syncFromMaterialsList). `task_budget_data.status` is no longer meaningful: every
 * budget saved since then stays 'draft'.
 *
 *   currentBudget()        — the planning basis. W6 projects it into planned
 *                            CostLines whether or not it is finalized, because
 *                            Stores, Petty Cash and verification already match
 *                            spend against those lines while a budget is worked on.
 *   authoritativeBudget()  — the same record, but only once finalized. W7 records
 *                            budgeted labour only against this.
 */
class ProjectBudgetAuthority
{
    public const STATE_NONE = 'none';
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_FINALIZED = 'finalized';

    public function budgetTask(int $enquiryId): ?EnquiryTask
    {
        return EnquiryTask::query()
            ->where('project_enquiry_id', $enquiryId)
            ->where('type', 'budget')
            ->orderByDesc('id')
            ->first();
    }

    /** The project's current budget — its planning basis. */
    public function currentBudget(int $enquiryId): ?TaskBudgetData
    {
        $task = $this->budgetTask($enquiryId);

        return $task
            ? TaskBudgetData::query()->where('enquiry_task_id', $task->id)->first()?->setRelation('task', $task)
            : null;
    }

    /** The current budget, only if its budget task has been completed. */
    public function authoritativeBudget(int $enquiryId): ?TaskBudgetData
    {
        $budget = $this->currentBudget($enquiryId);

        return $budget && $this->isFinalized($budget) ? $budget : null;
    }

    public function isFinalized(TaskBudgetData $budget): bool
    {
        return $budget->task?->status === 'completed';
    }

    /** True when $budget is the project's current budget (not an orphaned or superseded row). */
    public function isCurrent(TaskBudgetData $budget): bool
    {
        $enquiryId = $budget->task?->project_enquiry_id;

        return $enquiryId !== null && $this->budgetTask($enquiryId)?->id === $budget->enquiry_task_id;
    }

    public function state(int $enquiryId): string
    {
        $budget = $this->currentBudget($enquiryId);

        return match (true) {
            $budget === null => self::STATE_NONE,
            $this->isFinalized($budget) => self::STATE_FINALIZED,
            default => self::STATE_IN_PROGRESS,
        };
    }
}
