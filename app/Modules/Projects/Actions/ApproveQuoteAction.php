<?php

namespace App\Modules\Projects\Actions;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Events\QuoteApproved;
use Illuminate\Support\Facades\DB;
use App\Constants\Permissions;
use Exception;

class ApproveQuoteAction
{
    /**
     * Approve the quote and dispatch the QuoteApproved event to handle subsequent workflows.
     *
     * @param ProjectEnquiry $enquiry
     * @param int $userId
     * @return ProjectEnquiry
     * @throws Exception
     */
    public function execute(ProjectEnquiry $enquiry, int $userId): ProjectEnquiry
    {
        return DB::transaction(function () use ($enquiry, $userId) {
            
            // Check permissions (commented out for testing as in the original code)
            // $user = User::find($userId);
            // if (!$user || !$user->hasPermissionTo(Permissions::FINANCE_QUOTE_APPROVE)) {
            //     throw new Exception('Unauthorized: Only users with finance approval permission can approve quotes');
            // }

            // Generate job number when quote is approved
            $jobNumber = $enquiry->generateJobNumber();

            // We do NOT set the final status here. We set a temporary/initial state.
            // The Event Listener "EvaluateFinancialRequirements" will determine 
            // the final status based on the financial gate logic.
            
            $enquiry->update([
                'quote_approved' => true,
                'quote_approved_at' => now(),
                'quote_approved_by' => $userId,
                'job_number' => $jobNumber,
                // Do not explicitly set status here; let the listener handle it for clean separation.
            ]);

            $user = User::findOrFail($userId);

            // Dispatch the event synchronously within the transaction.
            // If any listener fails, the entire transaction rolls back.
            event(new QuoteApproved($enquiry, $user));

            return $enquiry->fresh();
        });
    }
}
