<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignHandoffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_item_id' => $this->design_item_id,
            'target_module' => $this->target_module,
            'target_record_id' => $this->target_record_id,
            'status' => $this->status,
            'payload_snapshot' => $this->payload_snapshot,
            'handed_off_by' => $this->handed_off_by,
            'handed_off_at' => $this->handed_off_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
