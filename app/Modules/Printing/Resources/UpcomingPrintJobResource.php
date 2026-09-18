<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpcomingPrintJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'design_item_id' => $this->id,
            'design_job_id' => $this->design_job_id,
            'job_number' => $this->job?->job_number,
            'project_name' => $this->job?->enquiry?->title ?? $this->job?->title,
            'client_name' => $this->job?->enquiry?->client?->full_name ?? $this->job?->client?->full_name,
            'title' => $this->title,
            'status' => $this->status,
            'designer_name' => $this->assignedUser?->name,
            'material_name' => $this->printMaterial?->material_name,
            'width_m' => $this->width_m !== null ? (float) $this->width_m : null,
            'length_m' => $this->length_m !== null ? (float) $this->length_m : null,
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'due_date' => $this->job?->due_date?->format('Y-m-d'),
            'is_redesign' => $this->redesign_of_item_id !== null || $this->redesign_of_print_job_id !== null,
        ];
    }
}
