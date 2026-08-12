<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintRollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'material_id' => $this->material_id,
            'source_inventory_log_id' => $this->source_inventory_log_id,
            'print_material_request_id' => $this->print_material_request_id,
            'material_code_snapshot' => $this->material_code_snapshot,
            'material_name_snapshot' => $this->material_name_snapshot,
            'roll_code' => $this->roll_code,
            'display_label' => $this->display_label,
            'received_sequence' => $this->received_sequence,
            'received_at' => $this->received_at?->format('Y-m-d'),
            'received_length_m' => $this->received_length_m !== null ? (float) $this->received_length_m : null,
            'remaining_length_m' => $this->remaining_length_m !== null ? (float) $this->remaining_length_m : null,
            'roll_width_m' => $this->roll_width_m !== null ? (float) $this->roll_width_m : null,
            'status' => $this->status,
            'location' => $this->location,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
