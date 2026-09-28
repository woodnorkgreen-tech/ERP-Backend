<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Privacy-safe W7 response. Never serialize full User or Employee models: only a
 * user's id/name and an employee's id/name/staff number (W7-11). No salary, pay,
 * deduction, bank, or statutory data exists anywhere in this payload.
 */
class ProjectLabourActualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = static fn ($value) => $value ? ['id' => $value->id, 'name' => $value->name] : null;
        $when = fn (string $relation) => $this->resource->relationLoaded($relation);
        $rateSource = $this->rate_source;

        return [
            'id' => $this->id, 'project_enquiry_id' => $this->project_enquiry_id,
            'budget_line_id' => $this->budget_line_id, 'budget_id' => $this->budget_id,
            'consumes_cost_line_id' => $this->consumes_cost_line_id,
            'cost_line_id' => $this->cost_line_id, 'labour_role' => $this->labour_role,
            'labour_category' => $this->labour_category, 'budget_unit' => $this->budget_unit,
            'unit_rate' => $this->unit_rate, 'actual_quantity' => $this->actual_quantity,
            'actual_days' => $this->actual_days, 'actual_hours' => $this->actual_hours,
            'calculated_cost' => $this->calculated_cost, 'work_date' => $this->work_date?->toDateString(),
            'employee' => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->name, 'staff_number' => $this->employee->staff_number] : null,
            'is_unbudgeted' => $this->is_unbudgeted, 'unbudgeted_reason' => $this->unbudgeted_reason,
            'rate_resolution_status' => $this->rate_resolution_status,
            'rate_source' => is_array($rateSource) ? array_intersect_key($rateSource, array_flip([
                'type', 'id', 'line_id', 'resolved_by_name', 'resolved_at', 'rate',
                'source_description', 'authorization_reference', 'reason',
            ])) : null,
            'rework_type' => $this->rework_type, 'status' => $this->status,
            'recorder' => $user($this->recorder), 'recorded_at' => $this->recorded_at?->toIso8601String(),
            'po_verified_by' => $user($this->poVerifier), 'po_verified_at' => $this->po_verified_at?->toIso8601String(),
            'po_notes' => $this->po_notes, 'finance_verified_by' => $user($this->financeVerifier),
            'finance_verified_at' => $this->finance_verified_at?->toIso8601String(), 'finance_notes' => $this->finance_notes,
            'returned_by' => $user($this->returnedBy), 'returned_at' => $this->returned_at?->toIso8601String(),
            'return_reason' => $this->return_reason,
            'resubmitted_by' => $when('resubmittedBy') ? $user($this->resubmittedBy) : null,
            'resubmitted_at' => $this->resubmitted_at?->toIso8601String(),
            'resubmission_count' => (int) $this->resubmission_count,
            'return_history' => $when('returns') ? $this->returns->map(fn ($row) => [
                'cycle' => $row->cycle,
                'returned_from_status' => $row->returned_from_status,
                'returned_by' => $user($row->returner),
                'returned_at' => $row->returned_at?->toIso8601String(),
                'return_reason' => $row->return_reason,
                'resubmitted_by' => $user($row->resubmitter),
                'resubmitted_at' => $row->resubmitted_at?->toIso8601String(),
                'changes' => $row->changes ?? [],
            ])->values() : [],
            'reversal_of_id' => $this->reversal_of_id,
            'superseded_by_id' => $this->superseded_by_id, 'correction_reason' => $this->correction_reason,
            // The original this corrects, so a reviewer sees original vs proposed.
            'correction_of' => $when('reversalOf') && $this->reversalOf ? [
                'id' => $this->reversalOf->id,
                'calculated_cost' => $this->reversalOf->calculated_cost,
                'actual_quantity' => $this->reversalOf->actual_quantity,
                'actual_days' => $this->reversalOf->actual_days,
                'actual_hours' => $this->reversalOf->actual_hours,
                'status' => $this->reversalOf->status,
            ] : null,
            // A pending or completed correction of this actual.
            'pending_correction' => $when('correction') && $this->correction ? [
                'id' => $this->correction->id,
                'status' => $this->correction->status,
                'calculated_cost' => $this->correction->calculated_cost,
                'correction_reason' => $this->correction->correction_reason,
                'recorded_at' => $this->correction->recorded_at?->toIso8601String(),
            ] : null,
            'correction_transfer' => $when('correctionTransfer') && $this->correctionTransfer ? [
                'id' => $this->correctionTransfer->id,
                'out_ref' => $this->correctionTransfer->outLine?->ref,
                'out_amount' => $this->correctionTransfer->outLine?->net_amount,
                'in_ref' => $this->correctionTransfer->inLine?->ref,
                'in_amount' => $this->correctionTransfer->inLine?->net_amount,
            ] : null,
            'reclassification' => $when('reclassificationTransfer') && $this->reclassificationTransfer ? [
                'transfer_id' => $this->reclassificationTransfer->id,
                'destination_enquiry_id' => $this->reclassificationTransfer->inLine?->project_enquiry_id,
                'destination_job_number' => $this->reclassificationTransfer->inLine?->job_number,
                'reason' => $this->reclassificationTransfer->reason,
            ] : null,
            // What this actual contributes to Project Costing right now.
            'authoritative_amount' => $this->status === 'finance_verified' && !$this->reclassification_transfer_id
                ? $this->calculated_cost : '0.00',
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
