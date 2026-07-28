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
            'finance_released' => (bool) ($this->finance_released ?? false),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->full_name,
                'phone' => $this->client->phone,
            ] : null),
            'finance_summary' => $this->finance_summary ?? null,
        ];
    }
}
