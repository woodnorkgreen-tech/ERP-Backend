<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W6-5: Project Financial Closure.
 *
 * Financial closure is a Finance control — it prevents new costs from being
 * recorded against a project whose books have been settled. It does NOT force
 * any particular operational status on the project.
 *
 * Pre-closure checks surface items that MIGHT need attention but do NOT
 * automatically block closure. WNG has not yet classified each check as a
 * hard blocker or an advisory warning. All checks are returned with
 * policy_classification = 'pending_configuration' until WNG decides.
 *
 * W6-6: The finance.costs.reopen permission exists in the DB but is assigned
 * to no role. Reopening a closed project for a late cost requires that
 * permission plus a documented reason.
 */
class ProjectFinancialClosureService
{
    /**
     * Run the pre-closure check suite and return a structured result.
     *
     * None of these currently block closure — advisory until WNG classifies them.
     *
     * @return array<string, mixed>
     */
    public function checkPreClosure(ProjectEnquiry $enquiry): array
    {
        $checks = [];

        // Check 1: submitted (unverified) cost lines still pending.
        $submittedCount = CostLine::where('project_enquiry_id', $enquiry->id)
            ->where('status', CostLine::STATUS_SUBMITTED)
            ->count();
        $checks[] = [
            'key'                   => 'pending_cost_lines',
            'label'                 => 'Unverified cost lines',
            'value'                 => $submittedCount,
            'passes'                => $submittedCount === 0,
            'policy_classification' => 'pending_configuration',
            'note'                  => 'WNG has not yet classified this check as a hard blocker or an advisory warning.',
        ];

        // Check 2: queried cost lines awaiting resolution.
        $queriedCount = CostLine::where('project_enquiry_id', $enquiry->id)
            ->where('status', CostLine::STATUS_QUERIED)
            ->count();
        $checks[] = [
            'key'                   => 'queried_cost_lines',
            'label'                 => 'Queried cost lines awaiting resolution',
            'value'                 => $queriedCount,
            'passes'                => $queriedCount === 0,
            'policy_classification' => 'pending_configuration',
            'note'                  => 'WNG has not yet classified this check as a hard blocker or an advisory warning.',
        ];

        // Check 3: outstanding project invoices (draft or sent but not yet posted/void).
        $outstandingInvoices = DB::table('project_invoices')
            ->where('project_enquiry_id', $enquiry->id)
            ->whereIn('status', ['draft', 'sent'])
            ->count();
        $checks[] = [
            'key'                   => 'outstanding_invoices',
            'label'                 => 'Invoices not yet posted or void',
            'value'                 => $outstandingInvoices,
            'passes'                => $outstandingInvoices === 0,
            'policy_classification' => 'pending_configuration',
            'note'                  => 'WNG has not yet classified this check as a hard blocker or an advisory warning.',
        ];

        $allPass = collect($checks)->every(fn ($c) => $c['passes']);

        return [
            'enquiry_id'     => $enquiry->id,
            'job_number'     => $enquiry->job_number,
            'closure_status' => $enquiry->financial_closure_status ?? 'open',
            'checks'         => $checks,
            'all_pass'       => $allPass,
            'advisory_note'  => 'All checks are advisory until WNG Finance classifies each as a hard blocker or a warning. '
                . 'Closure proceeds regardless of check results.',
        ];
    }

    /**
     * Financially close the project.
     *
     * Does NOT enforce any specific check result — policy classification is
     * pending_configuration. The Finance user is responsible for reviewing the
     * pre-closure check before proceeding.
     *
     * @throws ValidationException
     */
    public function close(ProjectEnquiry $enquiry, int $actorId): void
    {
        if (($enquiry->financial_closure_status ?? 'open') === 'closed') {
            throw ValidationException::withMessages([
                'financial_closure_status' => ['This project is already financially closed.'],
            ]);
        }

        $enquiry->update([
            'financial_closure_status' => 'closed',
            'financially_closed_by'    => $actorId,
            'financially_closed_at'    => now(),
        ]);
    }

    /**
     * W6-6: Reopen a financially closed project to record a late cost.
     *
     * Requires finance.costs.reopen (checked by the controller). The permission
     * is unassigned to any role by default — WNG must explicitly grant it per event.
     *
     * @throws ValidationException
     */
    public function reopen(ProjectEnquiry $enquiry, int $actorId, string $reason): void
    {
        if (($enquiry->financial_closure_status ?? 'open') !== 'closed') {
            throw ValidationException::withMessages([
                'financial_closure_status' => ['This project is not financially closed.'],
            ]);
        }

        $enquiry->update([
            'financial_closure_status' => 'open',
            'closure_reopened_by'      => $actorId,
            'closure_reopened_at'      => now(),
            'closure_reopen_reason'    => $reason,
        ]);
    }
}
