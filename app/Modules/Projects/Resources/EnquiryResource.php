<?php

namespace App\Modules\Projects\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                     => $this->id,
            'enquiry_number'         => $this->enquiry_number,
            'job_number'             => $this->job_number,
            'project_id'             => $this->project_id ?? null,
            'title'                  => $this->title,
            'description'            => $this->description,
            'contact_person'         => $this->contact_person,
            'contact_email'          => $this->contact_email ?? null,
            'contact_phone'          => $this->contact_phone ?? null,
            'status'                 => $this->status,
            'priority'               => $this->priority,
            'date_received'          => $this->date_received ? $this->date_received->format('Y-m-d') : null,
            'expected_delivery_date' => $this->expected_delivery_date ? $this->expected_delivery_date->format('Y-m-d') : null,
            'estimated_budget'       => (float) ($this->estimated_budget ?? 0),
            'client_approved_quote'  => $this->client_approved_quote ? (float) $this->client_approved_quote : null,
            'venue'                  => $this->venue,

            // Structured scope items from the project_deliverables table
            'project_scope'          => $this->project_scope,

            // Workflow configuration
            'selected_workflow_tasks' => $this->selected_workflow_tasks ?? [],
            'workflow_preset_type'    => $this->workflow_preset_type,
            'site_survey_skipped'     => (bool) $this->site_survey_skipped,
            'site_survey_skip_reason' => $this->site_survey_skip_reason,

            // Quote approval state
            'quote_approved'          => (bool) ($this->quote_approved ?? false),
            'quote_approved_at'       => $this->quote_approved_at,
            'quote_approved_by'       => $this->quote_approved_by,
            'finance_released'        => (bool) ($this->finance_released ?? false),
            'finance_released_at'     => $this->finance_released_at,

            // Finance summary is attached by list endpoints that need billing context.
            'finance_summary'                  => $this->when(isset($this->finance_summary), $this->finance_summary),
            'payment_progress_percentage'      => $this->when(isset($this->payment_progress_percentage), (float) $this->payment_progress_percentage),
            'payment_total_quote'              => $this->when(isset($this->payment_total_quote), (float) $this->payment_total_quote),
            'payment_total_paid'               => $this->when(isset($this->payment_total_paid), (float) $this->payment_total_paid),
            'payment_remaining'                => $this->when(isset($this->payment_remaining), (float) $this->payment_remaining),
            'payment_threshold_amount'         => $this->when(isset($this->payment_threshold_amount), (float) $this->payment_threshold_amount),
            'payment_amount_required_for_threshold' => $this->when(isset($this->payment_amount_required_for_threshold), (float) $this->payment_amount_required_for_threshold),

            // Progress & attention metadata
            'progress_percentage'     => $this->calculateProgressPercentage(),
            'needs_attention'         => $this->needsAttention(),
            'is_overdue'              => $this->expected_delivery_date
                ? ($this->expected_delivery_date->isPast() && $this->status !== 'completed')
                : false,

            // Relationships
            'client'          => $this->whenLoaded('client'),
            'department'      => $this->whenLoaded('department'),
            'project_officer' => $this->whenLoaded('projectOfficer'),
            'creator'         => $this->whenLoaded('creator'),
            'enquiryTasks'    => $this->whenLoaded('enquiryTasks'),
            'tasks_count'     => $this->enquiryTasks->count(),

            'created_by'  => $this->created_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }

    /**
     * Determine whether this enquiry needs human attention:
     * overdue, stalled in early state, or missing a key actor.
     */
    private function needsAttention(): bool
    {
        // Overdue and not finished
        if ($this->expected_delivery_date && $this->expected_delivery_date->isPast()
            && !in_array($this->status, ['completed', 'closed', 'cancelled'])) {
            return true;
        }
        // No project officer assigned and not in terminal state
        if (!$this->project_officer_id && !in_array($this->status, ['completed', 'closed', 'cancelled'])) {
            return true;
        }
        return false;
    }

    /**
     * Calculate a rough progress percentage based on the current status.
     * 
     * @return int
     */
    private function calculateProgressPercentage(): int
    {
        $statusMap = [
            \App\Constants\EnquiryConstants::STATUS_ENQUIRY_LOGGED => 10,
            \App\Constants\EnquiryConstants::STATUS_SITE_SURVEY_COMPLETED => 25,
            \App\Constants\EnquiryConstants::STATUS_DESIGN_COMPLETED => 40,
            \App\Constants\EnquiryConstants::STATUS_QUOTE_PREPARED => 55,
            \App\Constants\EnquiryConstants::STATUS_QUOTE_APPROVED => 65,
            \App\Constants\EnquiryConstants::STATUS_MATERIALS_SPECIFIED => 75,
            \App\Constants\EnquiryConstants::STATUS_BUDGET_CREATED => 85,
            'completed' => 100,
        ];

        return $statusMap[$this->status] ?? 0;
    }
}
