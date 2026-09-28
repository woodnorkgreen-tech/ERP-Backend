<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_item_id' => $this->design_item_id,
            'note' => $this->note,
            'created_by_name' => $this->creator?->name,
            'created_at' => $this->created_at,
        ];
    }
}
