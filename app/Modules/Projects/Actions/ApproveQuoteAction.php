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

            $user = User::findOrFail($userId);

            if (!$user->hasPermissionTo(Permissions::FINANCE_QUOTE_APPROVE)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    'Only users with the finance quote approval permission can approve quotes.'
                );
            }

            $approval = DB::table('quote_approvals')
                ->where('enquiry_id', $enquiry->id)
                ->latest('updated_at')
                ->first();

            if (!$approval || $approval->approval_status !== 'approved' || (float) $approval->quote_amount <= 0) {
                throw new Exception('An approved quote snapshot with a valid amount is required before project activation.');
            }

            $jobNumber = $enquiry->generateJobNumber();

            $enquiry->update([
                'quote_approved'    => true,
                'quote_approved_at' => now(),
                'quote_approved_by' => $userId,
                'job_number'        => $jobNumber,
            ]);

            // Dispatch the event synchronously within the transaction.
            // If any listener fails, the entire transaction rolls back.
            event(new QuoteApproved($enquiry, $user));

            return $enquiry->fresh();
        });
    }
}
