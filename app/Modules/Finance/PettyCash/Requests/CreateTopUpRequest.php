<?php

namespace App\Modules\Finance\PettyCash\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTopUpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization will be handled by middleware/permissions
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
                Rule::in(['cash', 'mpesa', 'bank_transfer', 'other']),
            ],
            'transaction_code' => [
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
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'transaction_code.required_unless' => 'Transaction code is required for non-cash payments.',
            'transaction_code.max' => 'Transaction code cannot exceed 255 characters.',
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
            'transaction_code' => 'transaction code',
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
            $transactionCode = $this->input('transaction_code');

            // Validate transaction code for specific payment methods
            if (in_array($paymentMethod, ['mpesa', 'bank_transfer']) && empty($transactionCode)) {
                $validator->errors()->add('transaction_code', 'Transaction code is required for ' . $paymentMethod . ' payments.');
            }

            // Validate transaction code format for M-Pesa
            if ($paymentMethod === 'mpesa' && $transactionCode) {
                if (!preg_match('/^[A-Z0-9]{10}$/', $transactionCode)) {
                    $validator->errors()->add('transaction_code', 'M-Pesa transaction code must be 10 characters long and contain only uppercase letters and numbers.');
                }
            }
        });
    }
}