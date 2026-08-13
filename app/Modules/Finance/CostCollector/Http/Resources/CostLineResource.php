<?php

namespace App\Modules\Finance\CostCollector\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One cost line, as everything downstream reads it.
 *
 * This used to expose 20 of the table's 40-odd columns, and the omissions were
 * not incidental — the payee was missing, so the verification screen showed
 * what was bought and who typed it in but never who received the money.
 * Approving a payment without the payee is not a review, so the payload now
 * carries every field a verifier, an auditor or an exporter has to see.
 *
 * The reference names (cost centre, activity, cause, payee type, journal no)
 * come from `scopeWithReferenceNames`. They resolve to null when a caller has
 * not applied that scope, which is why each is emitted with `whenNotNull`
 * rather than a key that would otherwise assert "this cost has no cost centre".
 */
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
            'enquiry_id' => $this->project_enquiry_id,
            'description' => $this->description,
            'site' => $this->site,

            // ── Money ─────────────────────────────────────────────────────
            // `amount` is what the receipt says, `net_amount` is what the
            // project is charged, `wht_amount` is retained and owed to KRA.
            // All three are shown because the difference between them is the
            // thing a verifier is being asked to agree with.
            'amount' => $this->amount,
            'tax_amount' => $this->tax_amount,
            'net_amount' => $this->net_amount,
            'wht_amount' => $this->wht_amount,
            'payable_amount' => $this->payableAmount(),
            'currency' => $this->currency,
            'fx_rate' => $this->fx_rate,
            'base_net_amount' => $this->base_net_amount,

            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_rate' => $this->unit_rate,

            // ── Who was paid ──────────────────────────────────────────────
            'payee' => [
                'name' => $this->payee_name ?: $this->payee_supplier_name,
                'type' => $this->payee_type_name,
                'type_id' => $this->payee_type_id,
                'id' => $this->payee_id,
                'is_supplier' => filled($this->payee_supplier_name),
            ],

            // ── Coding ────────────────────────────────────────────────────
            'expense_code' => $this->whenLoaded('expenseCode', fn () => [
                'code' => $this->expenseCode->code,
                'expense_type' => $this->expenseCode->expense_type,
                'family' => $this->expenseCode->expense_family,
                'accounting_class' => $this->expenseCode->accounting_class,
                'key_control' => $this->expenseCode->key_control,
            ]),
            'cost_centre' => $this->whenNotNull($this->cost_centre_name),
            'cost_centre_id' => $this->cost_centre_id,
            'activity' => $this->whenNotNull($this->activity_name),
            'cost_cause' => $this->whenNotNull($this->cost_cause_name),
            'is_exception_cause' => (bool) $this->cost_cause_is_exception,

            // ── Tax ───────────────────────────────────────────────────────
            'vat_treatment_id' => $this->vat_treatment_id,
            'vat_treatment' => $this->whenLoaded('vatTreatment', fn () => [
                'id' => $this->vatTreatment?->id,
                'code' => $this->vatTreatment?->code,
                'name' => $this->vatTreatment?->name,
                'rate_percent' => $this->vatTreatment?->rate_percent,
                'is_recoverable' => (bool) $this->vatTreatment?->is_recoverable,
            ]),
            'wht_category_id' => $this->wht_category_id,
            'wht_category' => $this->whenLoaded('whtCategory', fn () => [
                'id' => $this->whtCategory?->id,
                'code' => $this->whtCategory?->code,
                'name' => $this->whtCategory?->name,
                'rate_percent' => $this->whtCategory?->rate_percent,
            ]),

            // ── Budget ────────────────────────────────────────────────────
            // The signal the Unbudgeted panel is built on: spend that claimed no
            // budget line, surfaced the moment it is recorded.
            'is_unbudgeted' => $this->isUnbudgeted(),
            'consumes_line_id' => $this->consumes_line_id,
            'consumes_line' => $this->when($this->consumes_line_id !== null, fn () => [
                'id' => $this->consumes_line_id,
                'description' => $this->consumes_line_description,
                'budgeted' => $this->consumes_line_budgeted,
            ]),
            'unbudgeted_reason' => $this->details['unbudgeted_reason'] ?? null,
            'budget_remaining_before' => $this->budget_remaining_before,
            'budget_remaining_after' => $this->budget_remaining_after,

            // ── Dates and ageing ──────────────────────────────────────────
            // `age_days` is measured from when the cost was incurred, not when
            // it was typed in: a receipt sat on for three weeks before capture
            // is exactly the backlog the queue is meant to expose.
            'incurred_at' => $this->incurred_at?->toIso8601String(),
            'posting_date' => $this->posting_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'age_days' => $this->incurred_at?->startOfDay()->diffInDays(now()->startOfDay()),

            // ── Trail ─────────────────────────────────────────────────────
            'submitted_by' => $this->submitted_by_name
                ?: $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->name),
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'query_note' => $this->query_note,

            // ── Provenance ────────────────────────────────────────────────
            // Whether a human captured this or a producer posted it decides
            // what a verifier can sensibly question — you do not query a GRN
            // accrual back to "the person who reported it".
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'origin' => $this->origin(),
            'voucher_id' => $this->voucher_id,
            'funding_voucher_id' => $this->funding_voucher_id,

            // ── GL ────────────────────────────────────────────────────────
            'journal_entry_id' => $this->journal_entry_id,
            'journal_entry_no' => $this->whenNotNull($this->journal_entry_no),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'accounting_period_id' => $this->accounting_period_id,

            'evidence_count' => count($this->evidence ?? []),
            'evidence' => collect($this->evidence ?? [])->map(fn (array $item) => [
                'key' => $item['key'] ?? null,
                'path' => $item['path'] ?? null,
                'url' => filled($item['path'] ?? null) ? Storage::disk('public')->url($item['path']) : null,
                'kind' => $this->evidenceKind($item['path'] ?? null),
            ])->values(),
            'details' => $this->details ?? (object) [],
            'latest_query_response' => collect($this->capture_meta['query_responses'] ?? [])->last(),
            'latest_revision' => collect($this->capture_meta['revisions'] ?? [])->last(),
        ];
    }

    /**
     * What actually leaves the business: the gross less anything withheld.
     *
     * Derived rather than stored — it is the credit leg `postCostLine` builds,
     * and computing it in two places is how the two drift apart.
     */
    private function payableAmount(): string
    {
        $gross = bcadd((string) ($this->net_amount ?? '0'), (string) ($this->tax_amount ?? '0'), 2);

        return bcsub($gross, (string) ($this->wht_amount ?? '0'), 2);
    }

    /**
     * Human capture or an upstream module.
     *
     * `source_type` holds a producer's class name, which is not something to
     * put in front of a verifier; the short form is what the screen filters on.
     */
    private function origin(): string
    {
        return match (true) {
            blank($this->source_type) => 'captured',
            str_contains($this->source_type, 'PettyCash') => 'petty_cash',
            str_contains($this->source_type, 'Procurement') => 'procurement',
            str_contains($this->source_type, 'Stores') => 'stores',
            default => 'system',
        };
    }

    /** Lets the client show a receipt inline instead of opening a new tab. */
    private function evidenceKind(?string $path): string
    {
        return match (true) {
            blank($path) => 'unknown',
            (bool) preg_match('/\.(png|jpe?g|gif|webp|heic|heif)$/i', $path) => 'image',
            (bool) preg_match('/\.pdf$/i', $path) => 'pdf',
            default => 'file',
        };
    }
}
