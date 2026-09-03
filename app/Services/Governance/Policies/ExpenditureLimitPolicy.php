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

        // Try to fetch budget from TaskBudgetData (the actual source of truth for Project budgets)
        $budgetTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->where('type', 'budget')
            ->first();

        if ($budgetTask) {
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->latest()->first();
                
            if ($budgetData && isset($budgetData->budget_summary['grandTotal'])) {
                $budget = (float) $budgetData->budget_summary['grandTotal'];
            } elseif ($budgetData && isset($budgetData->budget_summary['grand_total'])) {
                $budget = (float) $budgetData->budget_summary['grand_total'];
            }
        }
        
        // If budget is still zero, and it's not a special case, we block.
        if ($budget <= 0) {
            return GateResult::blocked("Project budget is KES 0.00. Finalize the project budget before approving this financial commitment.");
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
                "This commitment (KES ".number_format($proposedAmount, 2).") would exceed the project budget by KES ".number_format($overage, 2).". Budget: KES ".number_format($budget, 2).".",
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
