<?php

namespace App\Modules\Design\Resources;

use App\Modules\MaterialsLibrary\Resources\LibraryMaterialResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_job_id' => $this->design_job_id,
            'design_type_id' => $this->design_type_id,
            'project_deliverable_id' => $this->project_deliverable_id,
            'redesign_of_item_id' => $this->redesign_of_item_id,
            'redesign_of_print_job_id' => $this->redesign_of_print_job_id,
            'redesign_source' => $this->redesign_source,
            'redesign_reason' => $this->redesign_reason,
            'redesign_requested_at' => $this->redesign_requested_at,
            'stream' => $this->stream,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'assigned_to' => $this->assigned_to,
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'dimension_unit' => $this->dimension_unit,
            'length_value' => $this->length_value !== null ? (float) $this->length_value : null,
            'width_value' => $this->width_value !== null ? (float) $this->width_value : null,
            'height_value' => $this->height_value !== null ? (float) $this->height_value : null,
            'length_m' => $this->length_m !== null ? (float) $this->length_m : null,
            'width_m' => $this->width_m !== null ? (float) $this->width_m : null,
            'height_m' => $this->height_m !== null ? (float) $this->height_m : null,
            'print_material_id' => $this->print_material_id,
            'print_material' => $this->whenLoaded('printMaterial', fn () => new LibraryMaterialResource($this->printMaterial)),
            'print_notes' => $this->print_notes,
            'concept_notes' => $this->concept_notes,
            'technical_notes' => $this->technical_notes,
            'type' => $this->whenLoaded('type', fn () => new DesignTypeResource($this->type)),
            'job' => $this->whenLoaded('job', fn () => new DesignJobResource($this->job)),
            'documents' => DesignDocumentResource::collection($this->whenLoaded('documents')),
            'bom_items' => DesignBomItemResource::collection($this->whenLoaded('bomItems')),
            'handoffs' => DesignHandoffResource::collection($this->whenLoaded('handoffs')),
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'print_ready_at' => $this->print_ready_at,
            'production_ready_at' => $this->production_ready_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
