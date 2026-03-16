<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Services\Governance\GateResult;

abstract class BasePolicy implements GatePolicyInterface
{
    /**
     * Check if the project is Internal or a Sponsorship.
     * These projects typically bypass all financial gates.
     */
    protected function isInternalOrSponsorship(ProjectEnquiry $enquiry): bool
    {
        return in_array($enquiry->workflow_preset_type, ['internal_job', 'sponsorship']);
    }

    /**
     * Common evaluation wrapper to handle universal exemptions.
     */
    public function evaluate(ProjectEnquiry $enquiry, array $context = []): GateResult
    {
        if ($this->isInternalOrSponsorship($enquiry)) {
            return GateResult::authorized(['exemption' => 'Internal/Sponsorship Bypass']);
        }

        return $this->runPolicy($enquiry, $context);
    }

    /**
     * The actual policy logic to be implemented by children.
     */
    abstract protected function runPolicy(ProjectEnquiry $enquiry, array $context = []): GateResult;
}
