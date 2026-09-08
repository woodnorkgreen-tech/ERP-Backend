<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintMaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'material_id' => $this->material_id,
            'material_name' => $this->material?->material_name,
            'material_code' => $this->material?->material_code,
            'requested_quantity_m' => $this->requested_quantity_m !== null ? (float) $this->requested_quantity_m : null,
            'project_id' => $this->project_id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'print_job_id' => $this->print_job_id,
            'urgency' => $this->urgency,
            'reason' => $this->reason,
            'status' => $this->status,
            'stores_inventory_log_id' => $this->stores_inventory_log_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
