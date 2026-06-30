<?php

namespace App\Modules\Assets\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_code' => $this->asset_code,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'ownership_type' => $this->ownership_type,
            'client_name' => $this->client_name,
            'is_available' => (bool) $this->is_available,
            'category' => $this->category,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('assetCategory', function () {
                return $this->assetCategory?->name;
            }),
            'subcategory' => $this->subcategory,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'specifications' => $this->specifications,
            'qty' => (int) $this->qty,
            'status' => $this->status,
            'condition' => $this->condition,

            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', function () {
                return $this->assignee?->name;
            }),

            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', function () {
                return $this->department?->name;
            }),

            'location' => $this->location,
            'purchase_date' => $this->purchase_date?->format('Y-m-d'),
            'purchase_cost_kes' => $this->purchase_cost_kes !== null ? (float) $this->purchase_cost_kes : null,
            'purchase_cost_usd' => $this->purchase_cost_usd !== null ? (float) $this->purchase_cost_usd : null,
            'current_value' => $this->current_value !== null ? (float) $this->current_value : null,
            'supplier' => $this->supplier,
            'warranty_expiry' => $this->warranty_expiry?->format('Y-m-d'),
            'notes' => $this->notes,
            'is_active' => $this->is_active,

            'current_assignment' => $this->whenLoaded('activeHireRequest', function () {
                if (!$this->activeHireRequest) return null;
                $req = $this->activeHireRequest;
                return [
                    'hire_request_id' => $req->id,
                    'request_type' => $req->request_type,
                    'for_user_name' => $req->forUser?->name,
                    'project_title' => $req->project?->enquiry?->title,
                    'out_date' => $req->out_date?->format('Y-m-d'),
                    'expected_return_date' => $req->expected_return_date?->format('Y-m-d'),
                ];
            }),

            'assignment_history' => $this->whenLoaded('assignmentHistory', function () {
                return $this->assignmentHistory->map(fn ($h) => [
                    'held_by_name' => $h->heldBy?->name,
                    'assigned_by_name' => $h->assignedBy?->name,
                    'started_at' => $h->started_at?->format('Y-m-d'),
                    'ended_at' => $h->ended_at?->format('Y-m-d'),
                ]);
            }),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
