<?php

namespace App\Modules\Projects\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'enquiry_number' => $this->enquiry_number,
            'job_number' => $this->job_number,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'contact_person' => $this->contact_person,
            'status' => $this->status,
            'priority' => $this->priority,
            'date_received' => $this->date_received ? $this->date_received->format('Y-m-d') : null,
            'expected_delivery_date' => $this->expected_delivery_date ? $this->expected_delivery_date->format('Y-m-d') : null,
            'estimated_budget' => (float) $this->estimated_budget,
            'venue' => $this->venue,
            'project_scope' => $this->project_scope,
            'project_deliverables' => $this->project_deliverables,
            
            // Progress Metadata
            'progress_percentage' => $this->calculateProgressPercentage(),
            'is_overdue' => $this->expected_delivery_date ? $this->expected_delivery_date->isPast() && $this->status !== 'completed' : false,

            // Relationships
            'client' => $this->whenLoaded('client'),
            'department' => $this->whenLoaded('department'),
            'project_officer' => $this->whenLoaded('projectOfficer'),
            'tasks_count' => $this->enquiryTasks->count(),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
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
            \App\Constants\EnquiryConstants::STATUS_MATERIALS_SPECIFIED => 55,
            \App\Constants\EnquiryConstants::STATUS_BUDGET_CREATED => 70,
            \App\Constants\EnquiryConstants::STATUS_QUOTE_PREPARED => 85,
            \App\Constants\EnquiryConstants::STATUS_QUOTE_APPROVED => 95,
            'completed' => 100,
        ];

        return $statusMap[$this->status] ?? 0;
    }
}
