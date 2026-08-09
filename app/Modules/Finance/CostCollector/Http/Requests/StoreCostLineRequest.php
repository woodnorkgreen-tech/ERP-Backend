<?php

namespace App\Modules\Finance\CostCollector\Http\Requests;

use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-checks an incoming cost before the collector applies the catalogue rules.
 *
 * What this class does NOT accept is as important as what it does. `sourceType`,
 * `sourceId` and `sourceApproved` are never read from the request: they exist so
 * a server-side producer can post a cost that inherited approval from its own
 * document, and honouring them here would let a submitter approve their own
 * spending. `nature` is likewise fixed to `actual` — commitments and accruals
 * come from producers, never from a phone.
 */
class StoreCostLineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expense_code' => 'required|string|max:24',
            'amount' => 'required|numeric|gt:0|max:99999999.99',
            'tax_amount' => 'nullable|numeric|min:0|lte:amount',
            'currency' => 'nullable|string|size:3',
            'fx_rate' => 'nullable|numeric|gt:0',
            'incurred_at' => 'nullable|date|before_or_equal:now',
            'description' => 'nullable|string|max:255',
            'external_ref' => 'nullable|string|max:64',

            'project_id' => 'nullable|integer|exists:projects,id',
            'enquiry_id' => 'nullable|integer|exists:project_enquiries,id',
            'job_number' => 'nullable|string|max:64',
            'task_id' => 'nullable|integer|exists:enquiry_tasks,id',
            'consumes_line_id' => 'nullable|integer|exists:cost_lines,id',

            'cost_cause' => 'nullable|string|max:32',
            'payee_type' => 'nullable|string|max:32',
            'payee_id' => 'nullable|integer',
            'payee_name' => 'nullable|string|max:191',

            'details' => 'nullable|array',
            'evidence' => 'nullable|array',
            'evidence.*.key' => 'required_with:evidence|string|max:64',
            'evidence.*.path' => 'required_with:evidence|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'tax_amount.lte' => 'Tax cannot be more than the amount on the receipt.',
            'incurred_at.before_or_equal' => 'A cost cannot be dated in the future.',
        ];
    }

    public function toContext(): CostContext
    {
        return new CostContext(
            expenseCode: $this->string('expense_code')->toString(),
            amount: (string) $this->input('amount'),
            nature: CostLine::NATURE_ACTUAL,
            projectId: $this->integer('project_id') ?: null,
            enquiryId: $this->integer('enquiry_id') ?: null,
            jobNumber: $this->input('job_number'),
            taskId: $this->integer('task_id') ?: null,
            taxAmount: $this->filled('tax_amount') ? (string) $this->input('tax_amount') : null,
            incurredAt: $this->input('incurred_at'),
            currency: $this->input('currency', 'KES'),
            fxRate: $this->filled('fx_rate') ? (string) $this->input('fx_rate') : null,
            payeeType: $this->input('payee_type'),
            payeeId: $this->integer('payee_id') ?: null,
            payeeName: $this->input('payee_name'),
            consumesLineId: $this->integer('consumes_line_id') ?: null,
            details: $this->input('details', []),
            evidence: $this->input('evidence', []),
            description: $this->input('description'),
            costCause: $this->input('cost_cause'),
        );
    }
}
