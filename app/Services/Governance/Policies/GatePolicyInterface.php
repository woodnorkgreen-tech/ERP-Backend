<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Services\Governance\GateResult;

interface GatePolicyInterface
{
    /**
     * Evaluate the policy for a given enquiry.
     *
     * @param ProjectEnquiry $enquiry
     * @param array $context
     * @return GateResult
     */
    public function evaluate(ProjectEnquiry $enquiry, array $context = []): GateResult;
}
