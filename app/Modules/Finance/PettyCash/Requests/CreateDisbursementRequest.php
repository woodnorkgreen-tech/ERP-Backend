<?php

namespace App\Modules\Finance\PettyCash\Requests;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Support\PaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment names two independent things: the account it left, and how it
 * reached the payee.
 *
 * This class used to derive the second from the first, overwriting whatever the
 * form submitted with a match() on the source type. That made cheque-from-bank,
 * RTGS-from-bank and M-Pesa-from-the-float unrecordable — and the float pays out
 * by M-Pesa often enough that the two live spend vouchers both do it. The
 * derivation is gone; what remains is a shortlist the form offers first.
 */
class CreateDisbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'top_up_id' => ['nullable', 'integer', 'exists:petty_cash_top_ups,id'],
            'payee_name' => ['required', 'string', 'max:255'],
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
            'payee_type' => ['nullable', 'string', 'max:32'],
            'payee_id' => ['nullable', 'integer'],
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'payment_type' => ['nullable', Rule::in(['direct', 'advance', 'refund'])],
            'payment_method' => ['required', Rule::in(PaymentMethods::values())],
            // Cash leaves no reference to quote back; everything else does.
            'external_reference' => ['nullable', 'string', 'max:255', 'required_unless:payment_method,cash'],
            'receipt_type' => ['required', Rule::in(['etr', 'non_etr', 'none'])],
            'receipt_number' => ['nullable', 'string', 'max:100', 'required_if:receipt_type,etr'],
            'tax_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('payment_method') === 'mpesa'
                && filled($this->input('external_reference'))
                && ! preg_match('/^[A-Z0-9]{10}$/', (string) $this->input('external_reference'))) {
                $validator->errors()->add(
                    'external_reference',
                    'M-Pesa transaction code must be 10 uppercase letters or numbers.',
                );
            }
        });
    }
}
