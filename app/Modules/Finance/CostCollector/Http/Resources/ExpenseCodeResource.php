<?php

namespace App\Modules\Finance\CostCollector\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the capture screen needs to render a cost form for one expense type.
 *
 * The GL account is deliberately absent. Finance owns that mapping centrally and
 * the person spending the money never sees it — exposing it here would invite a
 * client to start showing it, and then to start letting people choose it.
 */
class ExpenseCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'expense_type' => $this->expense_type,
            'expense_family' => $this->expense_family,
            'accounting_class' => $this->accounting_class,
            'simple_meaning' => $this->simple_meaning,
            'example' => $this->example,

            // Drives the form: whether a project may be attached at all, which
            // extra fields to render, which attachments to demand.
            'job_id_rule' => $this->job_id_rule,
            'job_id_rule_note' => $this->job_id_rule_note,
            'fields' => $this->detailFields(),
            'evidence' => $this->evidenceRequirements(),

            // Whether a purchase order can carry this at all.
            'is_procurable' => (bool) $this->is_procurable,
            'requires_supplier' => (bool) $this->requires_supplier,
            'requires_asset_record' => (bool) $this->requires_asset_record,
            'is_capex_review' => (bool) $this->is_capex_review,

            // Shown as guidance on the form, not as an editable field.
            'key_control' => $this->key_control,
        ];
    }
}
