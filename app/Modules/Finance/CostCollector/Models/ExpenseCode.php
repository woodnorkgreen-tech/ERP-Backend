<?php

namespace App\Modules\Finance\CostCollector\Models;

use App\Modules\Finance\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of WNG's expense catalogue.
 *
 * Everything the collector needs to classify, validate and render a cost is
 * read from here, which is why adding an expense type never requires a release.
 */
class ExpenseCode extends Model
{
    public const JOB_REQUIRED = 'required';
    public const JOB_OPTIONAL = 'optional';
    public const JOB_NOT_ALLOWED = 'not_allowed';
    public const JOB_CONDITIONAL = 'conditional';

    protected $fillable = [
        'code', 'accounting_class', 'expense_family', 'expense_type',
        'simple_meaning', 'example', 'recording_rule',
        'default_debit_gl', 'default_debit_account_id',
        'job_id_rule', 'job_id_rule_note',
        'default_cost_centre', 'default_cost_centre_id',
        'project_activity', 'default_activity_id',
        'inventory_treatment',
        'vat_default', 'default_vat_treatment_code',
        'wht_review', 'default_wht_category_code',
        'minimum_evidence', 'extra_operational_data',
        'key_control', 'pl_report_line', 'cash_flow_class', 'cash_flow_note',
        'requires_asset_record', 'requires_supplier', 'is_capex_review',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'minimum_evidence' => 'array',
        'extra_operational_data' => 'array',
        'requires_asset_record' => 'boolean',
        'requires_supplier' => 'boolean',
        'is_capex_review' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_debit_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function requiresJobId(): bool
    {
        return $this->job_id_rule === self::JOB_REQUIRED;
    }

    public function forbidsJobId(): bool
    {
        return $this->job_id_rule === self::JOB_NOT_ALLOWED;
    }

    /**
     * Field definitions the capture form renders and the collector validates.
     * Malformed entries are skipped rather than fatal: a bad row in the
     * catalogue must not make an otherwise valid cost impossible to record.
     *
     * @return array<int, array{key:string,label:string,type:string,required:bool}>
     */
    public function detailFields(): array
    {
        return collect($this->extra_operational_data ?? [])
            ->filter(fn ($field) => is_array($field) && filled($field['key'] ?? null))
            ->map(fn ($field) => [
                'key' => $field['key'],
                'label' => $field['label'] ?? $field['key'],
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => $field['options'] ?? null,
                'source' => $field['source'] ?? null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function requiredDetailKeys(): array
    {
        return collect($this->detailFields())
            ->where('required', true)
            ->pluck('key')
            ->all();
    }

    /** @return array<int, array{key:string,label:string,required:bool}> */
    public function evidenceRequirements(): array
    {
        return collect($this->minimum_evidence ?? [])
            ->filter(fn ($item) => is_array($item) && filled($item['key'] ?? null))
            ->map(fn ($item) => [
                'key' => $item['key'],
                'label' => $item['label'] ?? $item['key'],
                'required' => (bool) ($item['required'] ?? false),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function requiredEvidenceKeys(): array
    {
        return collect($this->evidenceRequirements())
            ->where('required', true)
            ->pluck('key')
            ->all();
    }
}
