<?php

namespace App\Modules\Finance\PettyCash\Requests;

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDisbursementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $source = \App\Modules\Finance\Models\PaymentSource::find($this->input('payment_source_id'));

        if ($source) {
            $this->merge(['payment_method' => match ($source->type) {
                'petty_cash' => 'cash',
                'mobile_money' => 'mpesa',
                'bank' => 'bank_transfer',
                'card' => 'card',
                default => 'other',
            }]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', PettyCashDisbursement::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'top_up_id' => ['nullable', 'integer', 'exists:petty_cash_top_ups,id'],
            'receiver' => ['required', 'string', 'max:255'],
            'expense_code_id' => ['required', 'integer', 'exists:expense_codes,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'transaction_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'description' => ['required', 'string', 'max:1000'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_enquiry_id' => ['nullable', 'integer', 'exists:project_enquiries,id'],
            'planned_cost_line_id' => ['nullable', 'integer', 'exists:cost_lines,id'],
            'venue' => ['nullable', 'string', 'max:255'],
            'budget_category' => ['nullable', Rule::in(['materials', 'labour', 'logistics', 'expenses'])],
            'requisition_id' => ['nullable', 'integer', 'exists:petty_cash_requisitions,id'],
            'direct_payment_reason' => ['nullable', 'string', 'min:10', 'max:1000', 'required_without:requisition_id'],
            'classification' => ['nullable', Rule::in([
                'agencies', 'admin', 'operations', 'event_planners', 'corporates', 'crs', 'other',
            ])],
            'job_number' => ['nullable', 'string', 'max:100'],
            'date_disbursed' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mpesa', 'bank_transfer', 'card', 'other'])],
            'transaction_code' => ['nullable', 'string', 'max:255', 'required_unless:payment_method,cash'],
            'receipt_type' => ['required', Rule::in(['etr', 'non_etr', 'none'])],
            'receipt_number' => ['nullable', 'string', 'max:100', 'required_if:receipt_type,etr'],
            'tax_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('payment_method') === 'mpesa'
                && filled($this->input('transaction_code'))
                && ! preg_match('/^[A-Z0-9]{10}$/', (string) $this->input('transaction_code'))) {
                $validator->errors()->add(
                    'transaction_code',
                    'M-Pesa transaction code must be 10 uppercase letters or numbers.',
                );
            }
        });
    }
}
