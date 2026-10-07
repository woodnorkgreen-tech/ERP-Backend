<?php

namespace App\Modules\Projects\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReceivablesEnquiryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'enquiry_number' => $this->enquiry_number,
            'job_number' => $this->job_number,
            'title' => $this->title,
            'status' => $this->status,
            'contact_person' => $this->contact_person,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'finance_released' => (bool) ($this->finance_released ?? false),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->full_name,
                // The register searches on company names the way the enquiry list
                // does; without it a client known only by its company name was
                // unfindable here.
                'company_name' => $this->client->company_name,
                'phone' => $this->client->phone,
            ] : null),
            'project_officer' => $this->whenLoaded('projectOfficer', fn () => $this->projectOfficer ? [
                'id' => $this->projectOfficer->id,
                'name' => $this->projectOfficer->name,
            ] : null),
            'finance_summary' => $this->finance_summary ?? null,
            // Releasing production is a row action on this register as well as a
            // control on the project's billing page, so the row carries the same
            // authority and the same refusal reason the page shows. The controller
            // still decides; this only tells the row what to offer.
            'actions' => [
                'release' => $this->release_action ?? null,
            ],
        ];
    }
}
