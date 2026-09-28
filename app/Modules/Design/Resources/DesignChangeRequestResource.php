<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_item_id' => $this->design_item_id,
            'against_revision_id' => $this->against_revision_id,
            'against_version' => $this->againstRevision?->version_number,
            'addressed_by_revision_id' => $this->addressed_by_revision_id,
            'addressed_by_version' => $this->addressedByRevision?->version_number,
            'request_text' => $this->request_text,
            'requested_by_name' => $this->requested_by_name,
            'received_at' => $this->received_at,
            'status' => $this->status,
            'recorded_by_name' => $this->recorder?->name,
            'created_at' => $this->created_at,
        ];
    }
}
