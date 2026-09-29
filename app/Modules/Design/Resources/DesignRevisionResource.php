<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_item_id' => $this->design_item_id,
            'version_number' => (int) $this->version_number,
            'change_summary' => $this->change_summary,
            'status' => $this->status,
            'creator_name' => $this->creator?->name,
            'approver_name' => $this->approver?->name,
            'approval_evidence' => $this->approval_evidence,
            'approved_at' => $this->approved_at,
            'handed_off_at' => $this->handed_off_at,
            'documents' => DesignDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
        ];
    }
}
