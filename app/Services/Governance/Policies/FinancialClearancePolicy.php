<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Services\Governance\GateResult;
use App\Modules\Projects\Services\FinanceService;

class FinancialClearancePolicy extends BasePolicy
{
    protected FinanceService $financeService;

    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
    }

    protected function runPolicy(ProjectEnquiry $enquiry, array $context = []): GateResult
    {
        // 1. Check for manual override/advanced status
        // If the project has already been transitioned to Planning or later, it's considered released
        if (in_array($enquiry->status, ['planning', 'in_progress', 'completed'])) {
            return GateResult::authorized([
                'status' => $enquiry->status,
                'manual_override' => true
            ]);
        }

        // 2. Automated 70% Deposit Check
        $progress = $this->financeService->getPaymentProgress($enquiry);

        if ($progress['is_70_percent_met']) {
            return GateResult::authorized([
                'percentage' => $progress['percentage'],
                'total_paid' => $progress['total_paid'],
                'basis' => $progress['is_client_approved_basis'] ? 'client_approved' : 'system_generated'
            ]);
        }

        // Professional block message
        $remainingPercent = max(0, 70 - $progress['percentage']);
        $message = "Financial Gate Locked: This project requires a minimum 70% mobilization deposit before operational tasks can begin. Current coverage: {$progress['percentage']}% (Needs {$remainingPercent}% more).";
        
        return GateResult::blocked($message, [
            'enquiry_id' => $enquiry->id,
            'current_percentage' => $progress['percentage'],
            'threshold' => '70%',
            'is_client_approved_basis' => $progress['is_client_approved_basis']
        ]);
    }
}
