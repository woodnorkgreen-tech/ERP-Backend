<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'project_id' => $this->project_id,
            'client_id' => $this->client_id,
            'job_number' => $this->job_number,
            'title' => $this->title,
            'source_type' => $this->source_type,
            'sync_origin' => $this->sync_origin,
            'auto_synced_at' => $this->auto_synced_at,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'enquiry' => $this->whenLoaded('enquiry', fn () => new DesignEnquirySummaryResource($this->enquiry)),
            'project' => $this->whenLoaded('project'),
            'items' => DesignItemResource::collection($this->whenLoaded('items')),
            'documents' => DesignDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
