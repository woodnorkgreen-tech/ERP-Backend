<?php

namespace App\Modules\Finance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A journal entry as the ledger screens read it.
 *
 * `lines` is deliberately conditional: the index lists hundreds of entries and
 * needs only their headers, while the drill-down needs every leg with the
 * account it hit. Loading lines unconditionally is what turns a ledger list into
 * an N+1.
 */
class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_no' => $this->entry_no,
            'posting_date' => $this->posting_date?->toDateString(),
            'description' => $this->description,
            'status' => $this->status,

            // What produced this entry. `source_ref` is the human handle — a
            // cost line's ref or a voucher number — and is what someone
            // searching the ledger actually has in hand.
            'source_type' => $this->source_type ? class_basename($this->source_type) : null,
            'source_id' => $this->source_id,
            'source_ref' => $this->source_ref,
            'cost_line_id' => $this->cost_line_id,
            'spend_voucher_id' => $this->spend_voucher_id,

            // Stores posts one atomic cost line per material movement. Publish
            // the shared issue identity so the ledger can present the business
            // transaction once while retaining every material journal beneath
            // it for valuation, return and audit traceability.
            'project_cost' => $this->whenLoaded('costLine', function () {
                $details = $this->costLine?->details ?? [];
                if (! $this->costLine?->project_enquiry_id && ! $this->costLine?->project_id && blank($this->costLine?->job_number)) {
                    return null;
                }

                $projectKey = $this->costLine->project_enquiry_id
                    ?? $this->costLine->project_id
                    ?? $this->costLine->job_number
                    ?? 'no-project';

                return [
                    // Finance reads material cost at project level. Batch and
                    // material stay on the child row for Stores traceability.
                    'group_key' => "project-costs:{$projectKey}",
                    'batch_number' => $details['batch_number'] ?? null,
                    'stores_reference' => $details['stores_reference'] ?? null,
                    'job_number' => $this->costLine->projectEnquiry?->job_number
                        ?? $this->costLine->job_number,
                    'project_title' => $this->costLine->projectEnquiry?->title,
                    'material' => $details['material'] ?? $this->costLine->description,
                    'quantity' => $this->costLine->quantity ?? ($details['quantity'] ?? null),
                    'unit' => $this->costLine->unit ?? ($details['uom'] ?? null),
                    'unit_cost' => $this->costLine->unit_rate ?? ($details['unit_cost'] ?? null),
                    'element' => $details['element'] ?? null,
                    'is_material' => isset($details['inventory_log_id']),
                    'is_unbudgeted' => $this->costLine->consumes_line_id === null,
                    'unbudgeted_reason' => $details['unbudgeted_reason'] ?? null,
                ];
            }),

            'total_debit' => (string) $this->total_debit,
            'total_credit' => (string) $this->total_credit,
            // Surfaced rather than left for the client to recompute: an
            // unbalanced entry is the one thing a ledger reader must never miss.
            'is_balanced' => $this->isBalanced(),

            // Reversal runs both ways so a drill-down can say "this reverses X"
            // and "this was reversed by Y" without a second request.
            'reversal_of_id' => $this->reversal_of_id,
            'reversed_by_id' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->id),

            'accounting_period' => $this->whenLoaded('accountingPeriod', fn () => $this->accountingPeriod ? [
                'id' => $this->accountingPeriod->id,
                'year' => $this->accountingPeriod->year,
                'month' => $this->accountingPeriod->month,
                'status' => $this->accountingPeriod->status,
            ] : null),

            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_by' => $this->created_by,

            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
