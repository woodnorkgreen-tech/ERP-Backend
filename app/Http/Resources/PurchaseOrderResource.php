<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'date' => $this->date->format('Y-m-d'),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', function () {
                return [
                    'id' => $this->supplier->id,
                    'supplier_name' => $this->supplier->supplier_name,
                    'email' => $this->supplier->email,
                ];
            }),
            'due_date' => $this->due_date->format('Y-m-d'),
            'delivery_address' => $this->delivery_address,
            'description' => $this->description,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
             'requisition' => $this->when($this->requisition, function () {
                return [
                    'id' => $this->requisition->id,
                    'requisition_number' => $this->requisition->requisition_number,
                    // What led to this requisition in the first place — project,
                    // office/department, or employee, and why — so a purchase
                    // order can be traced back to its origin without a second
                    // trip to the requisition screen.
                    'trigger' => $this->requisition->triggerContext(),
                ];
            }),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),

            // W2-1 / W2-6: the review position, as the backend decided it.
            // The screen shows these; it never works them out for itself.
            'senior_approval_required' => (bool) $this->senior_approval_required,
            'senior_approved_at' => $this->senior_approved_at?->format('Y-m-d H:i:s'),
            'senior_approved_by' => $this->whenLoaded('seniorApprovedBy', fn () => $this->seniorApprovedBy
                ? ['id' => $this->seniorApprovedBy->id, 'name' => $this->seniorApprovedBy->name] : null),
            'returned_at' => $this->returned_at?->format('Y-m-d H:i:s'),
            'return_reason' => $this->return_reason,
            'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy
                ? ['id' => $this->returnedBy->id, 'name' => $this->returnedBy->name] : null),
            'resubmitted_at' => $this->resubmitted_at?->format('Y-m-d H:i:s'),

            // What the current user may do to this order — the same Gate the
            // endpoints check, so a button is offered exactly when the server
            // would accept it. Visibility only; every endpoint re-checks.
            'abilities' => $this->abilities($request),
            
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            
            // Created By
            'createdBy' => $this->when($this->createdBy, function () {
                $user = $this->createdBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),
            
            // Approved By
            'approvedBy' => $this->when($this->approved_by && $this->approvedBy, function () {
                $user = $this->approvedBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),
            
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }

    /** @return array<string, bool> */
    private function abilities($request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $gate = \Illuminate\Support\Facades\Gate::forUser($user);
        $order = \App\Modules\ProcurementStores\Models\PurchaseOrder::class;
        $isRequester = (int) $this->user_id === (int) $user->id;
        $selfApproval = \App\Support\SelfApproval::allowedFor($user);
        $canCreate = $user->can(\App\Constants\Permissions::PROCUREMENT_ORDERS_CREATE);

        return [
            'approve' => $gate->allows('approveOrder', $order) && (! $isRequester || $selfApproval),
            'senior_approve' => $gate->allows('approveOrderSenior', $order) && ! $isRequester,
            'return_for_correction' => $gate->allows('returnOrder', $this->resource),
            'correct' => $gate->allows('correctOrder', $this->resource),
            'propose_amendment' => $canCreate,
            'decide_amendment' => $gate->allows('amendOrder', $this->resource),
            'attach_evidence' => $canCreate,
        ];
    }
}