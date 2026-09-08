<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignEnquirySummaryResource extends JsonResource
{
    /**
     * Deliberately lighter than App\Modules\Projects\Resources\EnquiryResource,
     * which unconditionally touches enquiryTasks (progress/finance rollups) and
     * N+1s badly across a list of design jobs. The Design module only ever
     * displays these fields, so it never needs enquiryTasks at all.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enquiry_number' => $this->enquiry_number,
            'job_number' => $this->job_number,
            'title' => $this->title,
            'description' => $this->description,
            'venue' => $this->venue,
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'status' => $this->status,
            'project_scope' => $this->project_scope,
            'client' => $this->whenLoaded('client'),
            // Project only the fields the Design module needs rather than serializing
            // the raw User model — User::$appends (is_manager/is_dept_lead) runs two
            // fresh exists() queries per model on every ->toArray(), which N+1s hard
            // across a list of jobs.
            'project_officer' => $this->whenLoaded('projectOfficer', fn () => [
                'id' => $this->projectOfficer->id,
                'name' => $this->projectOfficer->name,
                'email' => $this->projectOfficer->email,
            ]),
        ];
    }
}
