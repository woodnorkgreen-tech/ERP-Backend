<?php

namespace App\Modules\Finance\CostCollector\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'nature' => $this->nature,
            'status' => $this->status,

            'job_number' => $this->job_number,
            'description' => $this->description,

            'amount' => $this->amount,
            'tax_amount' => $this->tax_amount,
            'net_amount' => $this->net_amount,
            'currency' => $this->currency,

            'incurred_at' => $this->incurred_at?->toIso8601String(),
            'expense_code' => $this->whenLoaded('expenseCode', fn () => [
                'code' => $this->expenseCode->code,
                'expense_type' => $this->expenseCode->expense_type,
            ]),

            // The signal the Unbudgeted panel is built on: spend that claimed no
            // budget line, surfaced the moment it is recorded.
            'is_unbudgeted' => $this->isUnbudgeted(),
            'budget_remaining_after' => $this->budget_remaining_after,

            'submitted_by' => $this->submitted_by_name,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'query_note' => $this->query_note,
            'evidence_count' => count($this->evidence ?? []),
        ];
    }
}
