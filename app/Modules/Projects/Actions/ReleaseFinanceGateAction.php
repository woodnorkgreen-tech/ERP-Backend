<?php

namespace App\Modules\Projects\Actions;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Models\GovernanceAuditLog;
use App\Events\FinanceReleased;
use App\Modules\Projects\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Exception;

class ReleaseFinanceGateAction
{
    protected FinanceService $financeService;

    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
    }

    /**
     * Release the financial gate for an enquiry.
     *
     * @param ProjectEnquiry $enquiry
     * @param int $userId
     * @param string|null $notes
     * @return ProjectEnquiry
     * @throws Exception
     */
    public function execute(ProjectEnquiry $enquiry, int $userId, ?string $notes = null): ProjectEnquiry
    {
        if ($enquiry->finance_released) {
            return $enquiry->fresh();
        }

        $progress = $this->financeService->getPaymentProgress($enquiry);

        if (!$progress['has_approved_quote'] && !$progress['quote_requirement_waived']) {
            throw new Exception('Finance release requires an approved quote or a formally recorded no-quote exception.');
        }
        
        // Enforce justification if threshold not met
        if (!$progress['is_70_percent_met'] && empty(trim($notes ?? ''))) {
            throw new Exception("Justification is mandatory when releasing a project below its {$progress['threshold_percentage']}% mobilization target.");
        }

        return DB::transaction(function () use ($enquiry, $userId, $notes, $progress) {
            
            $enquiry->update([
                'status' => 'planning',
                'finance_released' => true,
                'finance_released_at' => now(),
                'follow_up_notes' => ($enquiry->follow_up_notes ? $enquiry->follow_up_notes . "\n" : "") . "Manually Released for Production on " . now()->format('d M Y') . ($notes ? ": $notes" : "")
            ]);

            $user = User::findOrFail($userId);

            // Capture Governance Audit Log
            GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => $userId,
                'gate_type' => 'financial',
                'action_status' => 'authorized', // manual authorization
                'message' => $progress['is_70_percent_met'] 
                    ? "Financial Gate Automatically Cleared ({$progress['percentage']}%)"
                    : "Manual Override: Financial Gate Bypassed",
                'context' => [
                    'reason' => $notes,
                    'current_percentage' => $progress['percentage'],
                    'threshold_met' => $progress['is_70_percent_met'],
                    'total_quote' => $progress['total_quote'],
                    'total_paid' => $progress['total_paid'],
                    'quote_requirement_waived' => $progress['quote_requirement_waived'],
                ],
                'ip_address' => request()->ip()
            ]);

            // Dispatch event for subsequent actions (like project activation)
            event(new FinanceReleased($enquiry, $user));

            return $enquiry->fresh();
        });
    }
}
