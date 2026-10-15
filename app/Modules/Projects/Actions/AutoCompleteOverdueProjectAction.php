<?php

namespace App\Modules\Projects\Actions;

use App\Constants\EnquiryConstants;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Modules\Projects\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Time-based completion of delivery status; task and financial closure remain separate. */
class AutoCompleteOverdueProjectAction
{
    public const GRACE_DAYS = 3;

    public function candidates(): Builder
    {
        // A date-only deadline expires at midnight the following day. Keep
        // three full overdue days before completing on the fourth morning.
        $cutoff = CarbonImmutable::today(config('app.timezone'))->subDays(self::GRACE_DAYS + 1);

        // Match the Projects register's active group, including Planning.
        return ProjectEnquiry::query()
            ->whereIn('status', EnquiryConstants::getApprovedProjectStatuses())
            ->whereDate('expected_delivery_date', '<=', $cutoff->toDateString())
            ->whereHas('project')
            ->whereDoesntHave('openHold');
    }

    public function __construct(private readonly NotificationService $notifications) {}

    public function execute(int $enquiryId): bool
    {
        $completed = DB::transaction(function () use ($enquiryId) {
            // Recheck inside the lock: the date, status or hold may have
            // changed after the command selected this row.
            $enquiry = $this->candidates()->whereKey($enquiryId)->lockForUpdate()->first();
            if (! $enquiry) {
                return null;
            }

            GovernanceAuditLog::create([
                'project_enquiry_id' => $enquiry->id,
                'user_id' => null,
                'gate_type' => 'project_completed',
                'action_status' => 'authorized',
                'message' => 'Project automatically completed after three full overdue days.',
                'context' => [
                    'completion_method' => 'automatic_overdue',
                    'from_status' => $enquiry->status,
                    'expected_delivery_date' => $enquiry->expected_delivery_date->toDateString(),
                    'grace_days' => self::GRACE_DAYS,
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);

            $enquiry->status = EnquiryConstants::STATUS_COMPLETED;
            $enquiry->save();
            $enquiry->project()->update(['status' => EnquiryConstants::STATUS_COMPLETED]);

            return $enquiry;
        });

        if (! $completed) {
            return false;
        }

        // After the commit: a notice must never be sent for a completion that
        // rolled back, and a failed notice must never undo the completion.
        $daysOverdue = (int) abs(CarbonImmutable::today(config('app.timezone'))
            ->diffInDays($completed->expected_delivery_date->toDateString()));
        $this->notifications->sendProjectAutoCompleted($completed, $daysOverdue);

        return true;
    }
}
