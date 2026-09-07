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
            'job_number' => $this->job_number,
            'project' => $this->whenLoaded('project', function () {
                if (!$this->project) return null;
                return [
                    'id' => $this->project->id,
                    'project_id' => $this->project->project_id ?? null,
                    'name' => $this->project->enquiry->title ?? $this->project->project_id ?? 'N/A',
                ];
            }),
            // Enquiry-based project details (when requested_by_type = 'project' and no Project record exists)
            'project_enquiry' => $this->whenLoaded('projectEnquiry', function () {
                if (!$this->projectEnquiry) return null;
                $enquiry = $this->projectEnquiry;
                return [
                    'id'          => $enquiry->id,
                    'job_number'  => $enquiry->job_number ?? $enquiry->enquiry_number,
                    'title'       => $enquiry->title,
                    'venue'       => $enquiry->venue,
                ];
            }),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', function () {
                if (!$this->employee) return null;
                return [
                    'id' => $this->employee->id,
                    'name' => $this->employee->name ?? 'N/A',
                ];
            }),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', function () {
                if (!$this->department) return null;
                return [
                    'id' => $this->department->id,
                    'name' => $this->department->name ?? 'N/A',
                ];
            }),


            'urgency' => $this->urgency,
            'status' => $this->status,
            'total_amount' => (float) $this->total_amount,
            // Add purchase order info
            'purchaseOrder' => $this->when($this->purchaseOrder, function () {
                return [
                    'id' => $this->purchaseOrder->id,
                    'po_number' => $this->purchaseOrder->po_number,
                    'status' => $this->purchaseOrder->status,
                ];
            }),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,

            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'project_enquiry_id' => $item->project_enquiry_id,
                        'procurement_task_id' => $item->procurement_task_id,
                        'budget_data_id' => $item->budget_data_id,
                        'budget_element_id' => $item->budget_element_id,
                        'budget_element_persistent_id' => $item->budget_element_persistent_id,
                        'budget_item_id' => $item->budget_item_id,
                        'budget_item_persistent_id' => $item->budget_item_persistent_id,
                        'material_id' => $item->material_id,
                        'expense_code_id' => $item->expense_code_id,
                        // The id alone cannot be rendered. Every screen that
                        // shows a coded line had to either re-fetch the
                        // catalogue or show nothing, and the edit form showed
                        // an empty picker over a line that was in fact coded.
                        'expense_code' => $item->expenseCode ? [
                            'id' => $item->expenseCode->id,
                            'code' => $item->expenseCode->code,
                            'expense_type' => $item->expenseCode->expense_type,
                            'expense_family' => $item->expenseCode->expense_family,
                            'simple_meaning' => $item->expenseCode->simple_meaning,
                            'job_id_rule' => $item->expenseCode->job_id_rule,
                        ] : null,
                        'supplier_id' => $item->supplier_id,
                        'supplier' => $item->supplier ? [
                            'id' => $item->supplier->id,
                            'supplier_name' => $item->supplier->supplier_name,
                        ] : null,
                        'custom_description' => $item->custom_description,
                        'material_name' => $item->material ? $item->material->material_name : $item->custom_description,
                        'material' => $item->material ? [
                            'id' => $item->material->id,
                            'material_code' => $item->material->material_code,
                            'material_name' => $item->material->material_name,
                        ] : null,
                        'quantity' => $item->quantity,
                        'uom_id' => $item->uom_id,
                        'uom' => $item->uom ? ['id' => $item->uom->id, 'code' => $item->uom->code, 'name' => $item->uom->name] : null,
                        'unit_price' => (float) $item->unit_price,
                        'internal_budget_unit_price' => $item->internal_budget_unit_price !== null ? (float) $item->internal_budget_unit_price : null,
                        'total' => (float) $item->total,
                        'purpose' => $item->purpose,
                        'reason' => $item->reason,
                        'procurement_item_snapshot' => $item->procurement_item_snapshot,
                    ];
                });
            }),

            // Created By - Show in both index and details
            'createdBy' => $this->when($this->createdBy, function () {
                $user = $this->createdBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),

            // Approved By - Show in both index and details
            'approvedBy' => $this->when($this->approved_by && $this->approvedBy, function () {
                $user = $this->approvedBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}