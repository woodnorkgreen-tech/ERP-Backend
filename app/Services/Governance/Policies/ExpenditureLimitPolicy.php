<?php

namespace App\Services\Governance\Policies;

use App\Models\ProjectEnquiry;
use App\Services\Governance\GateResult;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;

class ExpenditureLimitPolicy extends BasePolicy
{
    protected function runPolicy(ProjectEnquiry $enquiry, array $context = []): GateResult
    {
        $budget = (float) $enquiry->budget;
        
        // If budget is zero, and it's not a special case, we block.
        if ($budget <= 0) {
            return GateResult::blocked("Expenditure Gate: The project budget is set to 0.00. Please finalize the budget before committing funds.");
        }

        // Calculate total commitments (POs + Requisitions)
        $poCommitments = PurchaseOrder::where('requisition_id', function($q) use ($enquiry) {
                $q->select('id')->from('requisitions')->where('project_id', $enquiry->id);
            })
            ->whereIn('status', ['pending_approval', 'approved'])
            ->sum('total_amount');

        // Note: Requisitions that don't have a PO yet
        $pendingRequisitions = Requisition::where('project_id', $enquiry->id)
            ->where('status', 'pending_approval')
            ->whereDoesntHave('purchaseOrder')
            ->sum('total_amount');

        $totalCommitment = $poCommitments + $pendingRequisitions;
        $proposedAmount = (float) ($context['amount'] ?? 0);
        
        $newTotal = $totalCommitment + $proposedAmount;

        if ($newTotal > $budget) {
            $overage = $newTotal - $budget;
            return GateResult::blocked(
                "Expenditure Gate: This commitment (GHS {$proposedAmount}) would exceed the project budget by GHS {$overage}. Total Limit: GHS {$budget}.",
                [
                    'budget' => $budget,
                    'current_commitment' => $totalCommitment,
                    'proposed' => $proposedAmount,
                    'overage' => $overage
                ]
            );
        }

        return GateResult::authorized([
            'budget' => $budget,
            'remaining' => $budget - $totalCommitment
        ]);
    }
}
