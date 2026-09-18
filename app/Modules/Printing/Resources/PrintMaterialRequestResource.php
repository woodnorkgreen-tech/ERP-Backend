<?php

namespace App\Modules\Printing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintMaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fulfilled = (float) ($this->fulfilments_sum_issued_quantity_m
            ?? ($this->relationLoaded('fulfilments') ? $this->fulfilments->sum('issued_quantity_m') : 0));
        $requested = (float) $this->requested_quantity_m;

        return [
            'id' => $this->id,
            'material_id' => $this->material_id,
            'material_name' => $this->material?->material_name,
            'material_code' => $this->material?->material_code,
            'requested_quantity_m' => $this->requested_quantity_m !== null ? (float) $this->requested_quantity_m : null,
            'fulfilled_quantity_m' => round($fulfilled, 3),
            'remaining_quantity_m' => round(max(0, $requested - $fulfilled), 3),
            'available_quantity_m' => $this->availableMetres(),
            'project_id' => $this->project_id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'print_job_id' => $this->print_job_id,
            'urgency' => $this->urgency,
            'reason' => $this->reason,
            'status' => $this->status,
            'stores_inventory_log_id' => $this->stores_inventory_log_id,
            'job_number' => $this->printJob?->job_number,
            'project_name' => $this->printJob?->project_name,
            'requested_by_name' => $this->requester?->name,
            'fulfilments' => $this->whenLoaded('fulfilments', fn () => $this->fulfilments->map(fn ($item) => [
                'id' => $item->id,
                'inventory_log_id' => $item->inventory_log_id,
                'issued_quantity_m' => (float) $item->issued_quantity_m,
                'issued_by_name' => $item->issuer?->name,
                'issued_at' => $item->issued_at,
                'notes' => $item->notes,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function availableMetres(): ?float
    {
        $material = $this->material;
        if (! $material?->relationLoaded('stock') || ! $material->stock) return null;
        $availableBase = max(0, (float) $material->stock->quantity_on_hand - (float) $material->stock->quantity_reserved);
        $baseCode = strtolower((string) $material->baseUom?->code);
        if (in_array($baseCode, ['m', 'metre', 'meter'], true)) return round($availableBase, 3);
        $metre = $material->issueUom;
        if (! $metre || ! in_array(strtolower((string) $metre->code), ['m', 'metre', 'meter'], true)) return null;
        $conversion = $material->uomConversions->first(fn ($item) =>
            (int) $item->from_uom_id === (int) $metre->id && (int) $item->to_uom_id === (int) $material->base_uom_id
        );
        $factor = (float) ($conversion?->factor ?? 0);
        return $factor > 0 ? round($availableBase / $factor, 3) : null;
    }
}
