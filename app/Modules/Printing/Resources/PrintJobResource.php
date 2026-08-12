<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_handoff_id' => $this->design_handoff_id,
            'design_item_id' => $this->design_item_id,
            'design_job_id' => $this->design_job_id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'project_id' => $this->project_id,
            'client_id' => $this->client_id,
            'job_number' => $this->job_number,
            'project_name' => $this->project_name,
            'client_name' => $this->client_name,
            'title' => $this->title,
            'description' => $this->description,
            'final_artwork_url' => $this->final_artwork_url,
            'final_artwork_document_id' => $this->final_artwork_document_id,
            'artwork_version' => $this->artwork_version,
            'order_type' => $this->order_type,
            'reprint_of_job_id' => $this->reprint_of_job_id,
            'reprint_reason' => $this->reprint_reason,
            'status' => $this->status,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'operator_id' => $this->operator_id,
            'operator_name' => $this->operator?->name,
            'machine_asset_id' => $this->machine_asset_id,
            'machine_name_snapshot' => $this->machine_name_snapshot,
            'remarks' => $this->remarks,
            'locked' => $this->isLocked(),
            'consumptions' => PrintJobConsumptionResource::collection($this->whenLoaded('consumptions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
