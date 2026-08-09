<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\PettyCash\Services\ProjectIdentityResolver;
use App\Modules\Projects\Models\EnquiryTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns what a caller happened to know into every dimension a cost needs.
 *
 * This is the class that keeps capture down to an amount and a photo: the
 * project resolves from any one identifier, the activity from the project's live
 * task stage, and the accounting treatment from the catalogue. Nothing here is
 * asked of the person spending the money.
 */
class CostContextResolver
{
    public function __construct(
        private ProjectIdentityResolver $projectIdentity,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(CostContext $context, ExpenseCode $code): array
    {
        $identity = $this->resolveProject($context);
        $incurredAt = $context->incurredAt
            ? CarbonImmutable::parse($context->incurredAt)
            : CarbonImmutable::now();

        $postingDate = $incurredAt->toDateString();

        return [
            'project_id' => $identity['project_id'] ?? null,
            'project_enquiry_id' => $identity['project_enquiry_id'] ?? null,
            'job_number' => $identity['job_number'] ?? null,

            'expense_code_id' => $code->id,
            'cost_centre_id' => $code->default_cost_centre_id,
            'activity_id' => $this->resolveActivity($context, $code),
            'cost_cause_id' => $this->resolveCostCause($context),

            'vat_treatment_id' => $this->resolveEffective(
                'vat_treatments', $code->default_vat_treatment_code, $postingDate
            ),
            'wht_category_id' => $this->resolveEffective(
                'wht_categories', $code->default_wht_category_code, $postingDate
            ),
            'payee_type_id' => $this->resolvePayeeType($context),

            'incurred_at' => $incurredAt,
            'posting_date' => $postingDate,
            'accounting_period_id' => AccountingPeriod::forDate($incurredAt)?->id,
        ];
    }

    /**
     * Reuses the petty-cash identity adapter rather than re-deriving the mapping.
     * It is the one place in the codebase where the project_id / enquiry_id /
     * job_number triple is resolved consistently, and duplicating it is how the
     * two would drift.
     */
    private function resolveProject(CostContext $context): array
    {
        if (! $context->projectId && ! $context->enquiryId && blank($context->jobNumber)) {
            return [];
        }

        return $this->projectIdentity->resolve(array_filter([
            'project_id' => $context->projectId,
            'project_enquiry_id' => $context->enquiryId,
            'job_number' => $context->jobNumber,
        ]));
    }

    /**
     * Activity comes from the project's live task stage when a task is known,
     * which is what stops activity and workflow state from drifting apart. The
     * catalogue default is the fallback for non-project spend.
     */
    private function resolveActivity(CostContext $context, ExpenseCode $code): ?int
    {
        if ($context->taskId) {
            $taskType = EnquiryTask::whereKey($context->taskId)->value('type');

            if ($taskType) {
                $activityId = DB::table('activities')
                    ->where('workflow_task_type', $taskType)
                    ->where('is_active', true)
                    ->value('id');

                if ($activityId) {
                    return $activityId;
                }
            }
        }

        return $code->default_activity_id;
    }

    private function resolveCostCause(CostContext $context): ?int
    {
        $query = DB::table('cost_causes')->where('is_active', true);

        return filled($context->costCause)
            ? $query->where('code', $context->costCause)->value('id')
            : $query->where('is_default', true)->value('id');
    }

    private function resolvePayeeType(CostContext $context): ?int
    {
        if (blank($context->payeeType)) {
            return null;
        }

        return DB::table('payee_types')
            ->where('code', $context->payeeType)
            ->where('is_active', true)
            ->value('id');
    }

    /**
     * Effective-dated lookup. Resolving against the transaction date rather than
     * "the current row" is what stops a future rate change from silently
     * restating costs already recorded.
     */
    private function resolveEffective(string $table, ?string $code, string $onDate): ?int
    {
        if (blank($code)) {
            return null;
        }

        return DB::table($table)
            ->where('code', $code)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $onDate)
            ->where(function ($query) use ($onDate) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $onDate);
            })
            ->orderByDesc('effective_from')
            ->value('id');
    }
}
