<?php

namespace App\Modules\Production\Observers;

use App\Models\ActionLog;
use App\Models\ProjectEnquiry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class ProjectEnquiryObserver
{
    private const AUDITED_FIELDS = [
        'title', 'description', 'status', 'priority', 'client_id',
        'contact_person', 'project_officer_id', 'department_id',
        'estimated_budget', 'venue', 'expected_delivery_date', 'date_received',
        'follow_up_notes', 'site_survey_skipped', 'site_survey_skip_reason',
        'quote_approved', 'quote_approved_by', 'quote_approved_at',
        'job_number', 'workflow_preset_type', 'start_date', 'end_date',
        'budget', 'client_approved_quote',
    ];

    private function snapshot(ProjectEnquiry $enquiry): array
    {
        return $enquiry->only(self::AUDITED_FIELDS);
    }

    private function writeLog(ProjectEnquiry $enquiry, string $action, ?array $original, ?array $changed): void
    {
        ActionLog::create([
            'user_id'       => auth()->id(),
            'action'        => $action,
            'loggable_type' => ProjectEnquiry::class,
            'loggable_id'   => $enquiry->id,
            'original_data' => $original,
            'changed_data'  => $changed,
            'ip_address'    => Request::ip(),
            'user_agent'    => Request::userAgent(),
        ]);
    }

    /**
     * Handle the ProjectEnquiry "created" event.
     */
    public function created(ProjectEnquiry $enquiry): void
    {
        $this->writeLog($enquiry, 'created', null, $this->snapshot($enquiry));
    }

    /**
     * Handle the ProjectEnquiry "updated" event.
     */
    public function updated(ProjectEnquiry $enquiry): void
    {
        $dirty = array_intersect_key($enquiry->getDirty(), array_flip(self::AUDITED_FIELDS));

        if (empty($dirty)) {
            return;
        }

        $original = [];
        $changed  = [];

        foreach ($dirty as $field => $newValue) {
            $original[$field] = $enquiry->getOriginal($field);
            $changed[$field]  = $newValue;
        }

        $this->writeLog($enquiry, 'updated', $original, $changed);
    }

    /**
     * Handle the ProjectEnquiry "deleted" event.
     */
    public function deleted(ProjectEnquiry $enquiry): void
    {
        $this->writeLog($enquiry, 'deleted', $this->snapshot($enquiry), null);
    }

    /**
     * Handle the ProjectEnquiry "restored" event.
     */
    private function generateWorkOrderNumber(ProjectEnquiry $enquiry = null): string
    {
        $year = date('Y');
        $month = date('m');
        $sequence = WorkOrder::whereYear('created_at', $year)->whereMonth('created_at', $month)->count() + 1;
        
        return sprintf('WO-%s%s-%03d', $year, $month, $sequence);
    }

    /**
     * Determine the appropriate work order status based on enquiry state
     */
    private function determineWorkOrderStatusFromEnquiry(ProjectEnquiry $enquiry): string
    {
        $status = $enquiry->status;

        if ($status === \App\Constants\EnquiryConstants::STATUS_COMPLETED) {
            return 'completed';
        }

        // If enquiry is approved or formalized into a project
        $activeStatuses = [
            \App\Constants\EnquiryConstants::STATUS_QUOTE_APPROVED,
            \App\Constants\EnquiryConstants::STATUS_PLANNING,
            \App\Constants\EnquiryConstants::STATUS_IN_PROGRESS,
        ];

        if (in_array($status, $activeStatuses) || $enquiry->job_number) {
            return 'in_progress';
        }

        return 'pending';
    }

    /**
     * Map enquiry priority to work order priority
     */
    private function mapPriority(string $enquiryPriority): string
    {
        $map = [
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'urgent' => 'urgent',
        ];

        return $map[strtolower($enquiryPriority)] ?? 'medium';
    }
}
