<?php

namespace App\Modules\Finance\PettyCash\Requests;

use App\Modules\Finance\Support\PaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTopUpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Gated in PettyCashTopUpController::store with an explicit
        // `finance.petty_cash.create_top_up` check, alongside the identical
        // checks on update and destroy. Leaving this `true` is deliberate, not
        // an oversight — but it is only safe while that check stays there.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ],
            'payment_method' => [
                'required',
                'string',
                // The one canonical list, shared with payments and bills.
                Rule::in(PaymentMethods::values()),
            ],
            'external_reference' => [
                'nullable',
                'string',
                'max:255',
                'required_unless:payment_method,cash',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            // The form has always sent this and the service persists it. It has
            // to be declared here or validated() would silently drop it — the
            // top-up would save with no date at all.
            'date_topped_up' => [
                'required',
                'date',
                'before_or_equal:today',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'The amount field is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.max' => 'The amount cannot exceed 999,999.99.',
            'date_topped_up.required' => 'Please give the date the float was topped up.',
            'date_topped_up.before_or_equal' => 'A top-up cannot be dated in the future.',
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'external_reference.required_unless' => 'Transaction code is required for non-cash payments.',
            'external_reference.max' => 'Transaction code cannot exceed 255 characters.',
            'description.max' => 'Description cannot exceed 1000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payment_method' => 'payment method',
            'external_reference' => 'transaction code',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Additional custom validation logic can be added here
            $paymentMethod = $this->input('payment_method');
            $transactionCode = $this->input('external_reference');

            // Validate transaction code for specific payment methods
            if (PaymentMethods::requiresReference($paymentMethod) && empty($transactionCode)) {
                $validator->errors()->add(
                    'external_reference',
                    'An external reference is required for ' . PaymentMethods::label($paymentMethod) . ' payments.',
                );
            }

            // Validate transaction code format for M-Pesa
            if ($paymentMethod === 'mpesa' && $transactionCode) {
                if (!preg_match('/^[A-Z0-9]{10}$/', $transactionCode)) {
                    $validator->errors()->add('external_reference', 'M-Pesa transaction code must be 10 characters long and contain only uppercase letters and numbers.');
                }
            }
        });
    }
}