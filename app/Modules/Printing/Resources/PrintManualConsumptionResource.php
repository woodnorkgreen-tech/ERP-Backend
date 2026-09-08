<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintManualConsumptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'print_roll_id' => $this->print_roll_id,
            'material_id' => $this->material_id,
            'project_id' => $this->project_id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'operator_id' => $this->operator_id,
            'reason' => $this->reason,
            'quantity_m' => $this->quantity_m !== null ? (float) $this->quantity_m : null,
            'notes' => $this->notes,
            'consumed_at' => $this->consumed_at,
            'roll' => $this->whenLoaded('roll', fn () => new PrintRollResource($this->roll)),
            'created_at' => $this->created_at,
        ];
    }
}
