<?php

namespace App\Services\Governance;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ProjectGovernanceService
{
    /**
     * The registry of available policies.
     */
    protected array $policies = [
        'financial' => \App\Services\Governance\Policies\FinancialClearancePolicy::class,
        'technical' => \App\Services\Governance\Policies\TechnicalReadinessPolicy::class,
        'expenditure' => \App\Services\Governance\Policies\ExpenditureLimitPolicy::class,
    ];

    /**
     * Check if a specific action or state is authorized for a project.
     *
     * @param ProjectEnquiry $enquiry
     * @param string $gateType
     * @param array $context Additional data needed for the check
     * @return GateResult
     */
    public function checkGate(ProjectEnquiry $enquiry, string $gateType, array $context = []): GateResult
    {
        Log::info("Governance check: Gate [{$gateType}] for Enquiry ID [{$enquiry->id}]");

        if (!isset($this->policies[$gateType])) {
            Log::warning("Governance: Policy [{$gateType}] not found. Defaulting to AUTHORIZED.");
            return GateResult::authorized();
        }

        try {
            $policy = app($this->policies[$gateType]);
            $result = $policy->evaluate($enquiry, $context);

            // Log the decision
            $this->logDecision($enquiry, $gateType, $result, $context);

            return $result;
        } catch (\Exception $e) {
            Log::error("Governance: Error evaluating policy [{$gateType}]: " . $e->getMessage());
            return GateResult::blocked("System Error: Unable to verify governance policy [{$gateType}].");
        }
    }

    /**
     * Record the governance decision for audit.
     */
    protected function logDecision(ProjectEnquiry $enquiry, string $gateType, $result, array $context): void
    {
        try {
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => auth()->id(),
                'gate_type' => $gateType,
                'action_status' => $result->isAuthorized() ? 'authorized' : 'blocked',
                'message' => $result->getMessage(),
                'context' => array_merge($context, $result->context),
                'ip_address' => request()->ip()
            ]);
        } catch (\Exception $e) {
            Log::error("Governance: Failed to log decision: " . $e->getMessage());
        }
    }

    /**
     * Helper to evaluate a specific task's readiness.
     */
    public function evaluateTask(EnquiryTask $task): GateResult
    {
        $enquiry = $task->projectEnquiry;
        if (!$enquiry) {
            return GateResult::authorized();
        }

        // Map task types or attributes to gates
        $gateMapping = [
            'procurement' => 'financial',
            'production' => 'financial',
            'logistics' => 'financial',
            'setup' => 'financial',
            'setdown' => 'financial',
        ];

        $gateType = $gateMapping[$task->type] ?? null;

        if ($gateType) {
            return $this->checkGate($enquiry, $gateType, ['task' => $task]);
        }

        return GateResult::authorized();
    }
}
