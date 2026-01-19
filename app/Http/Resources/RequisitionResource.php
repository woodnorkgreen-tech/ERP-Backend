<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'requisition_number' => $this->requisition_number,
            'date' => $this->date?->format('Y-m-d'),
            'requested_by_type' => $this->requested_by_type,
            'project_id' => $this->project_id,
            'project' => $this->when($this->project, function () {
                return [
                    'id' => $this->project->id,
                    'project_id' => $this->project->project_id ?? null,
                    'name' => $this->project->enquiry?->title ?? 'N/A',  // ← FIXED: null-safe operator
                ];
            }),
            'employee_id' => $this->employee_id,
            'employee' => $this->when($this->employee, function () {
                return [
                    'id' => $this->employee->id,
                    'name' => $this->employee->name ?? 'N/A',
                ];
            }),
            'department_id' => $this->department_id,
            'department' => $this->when($this->department, function () {
                return [
                    'id' => $this->department->id,
                    'name' => $this->department->name ?? 'N/A',
                ];
            }),
            'urgency' => $this->urgency,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,
            'approvedBy' => $this->when($this->approved_by && $this->approvedBy, function () {
                $user = $this->approvedBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? $user->employee->first_name . ' ' . $user->employee->last_name
                        : $user->name,
                ];
            }),
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'material_id' => $item->material_id,
                        'material' => $item->material ? [
                            'id' => $item->material->id,
                            'material_code' => $item->material->material_code,
                            'material_name' => $item->material->material_name,
                        ] : null,
                        'quantity' => $item->quantity,
                        'purpose' => $item->purpose,
                        'reason' => $item->reason,
                    ];
                });
            }),
            'createdBy' => $this->when($this->createdBy, function () {
                $user = $this->createdBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? $user->employee->first_name . ' ' . $user->employee->last_name
                        : $user->name,
                ];
            }),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}