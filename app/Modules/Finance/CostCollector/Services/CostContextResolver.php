<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
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

        $identity = $this->projectIdentity->resolve(array_filter([
            'project_id' => $context->projectId,
            'project_enquiry_id' => $context->enquiryId,
            'job_number' => $context->jobNumber,
        ]));

        $this->assertIdentityAgrees($identity);

        return $identity;
    }

    /**
     * The identity resolver fills gaps; it does not check that what it was given
     * agrees. That gap let a Stores movement post with a project, an enquiry and
     * a job number that each named a different job — the cost landed on another
     * project's budget line while still displaying the originating job number.
     *
     * A cost whose own identity fields contradict each other cannot be corrected
     * later by reading it, because there is no way to tell which field was right.
     * Refuse it at the boundary instead. For a producer this surfaces as a failed
     * outbox posting the operator can see and repair; nothing is silently lost,
     * and no stock movement is reversed by it.
     */
    private function assertIdentityAgrees(array $identity): void
    {
        $projectId = $identity['project_id'] ?? null;
        $enquiryId = $identity['project_enquiry_id'] ?? null;
        $jobNumber = $identity['job_number'] ?? null;

        if (! $projectId && ! $enquiryId) {
            return;
        }

        $project = $projectId ? Project::find($projectId) : null;
        $enquiry = $enquiryId ? ProjectEnquiry::find($enquiryId) : null;

        if ($projectId && ! $project) {
            throw CostValidationException::withErrors([
                'project' => ["Project #{$projectId} does not exist, so this cost has no owner."],
            ]);
        }

        if ($enquiryId && ! $enquiry) {
            throw CostValidationException::withErrors([
                'project' => ["Enquiry #{$enquiryId} does not exist, so this cost has no owner."],
            ]);
        }

        if ($project && $enquiry && (int) $project->enquiry_id !== (int) $enquiry->id) {
            throw CostValidationException::withErrors([
                'project' => [sprintf(
                    'Project #%d belongs to enquiry #%s, but this cost names enquiry #%d. Refusing to post a cost whose project and enquiry disagree.',
                    $project->id,
                    $project->enquiry_id ?? 'none',
                    $enquiry->id,
                )],
            ]);
        }

        if (blank($jobNumber)) {
            return;
        }

        // Both notations are legitimate: the enquiry's job number is canonical,
        // and the project's display code is what several modules write on their
        // own records. Either identifies the same job; anything else does not.
        $accepted = array_values(array_filter([
            $enquiry?->job_number,
            $project?->enquiry?->job_number,
            $project?->project_id,
        ]));

        if (! $accepted) {
            return;
        }

        $normalise = fn (?string $value) => PettyCashCostProducer::normaliseJobNumber((string) $value);
        $supplied = $normalise($jobNumber);

        foreach ($accepted as $candidate) {
            if ($normalise($candidate) === $supplied) {
                return;
            }
        }

        throw CostValidationException::withErrors([
            'project' => [sprintf(
                'Job number "%s" does not belong to the resolved job (%s). Refusing to post a cost whose job number contradicts its project.',
                $jobNumber,
                implode(' / ', array_unique($accepted)),
            )],
        ]);
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
