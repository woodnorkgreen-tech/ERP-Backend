<?php

namespace App\Modules\Finance\PettyCash\Requests;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDisbursementRequest extends FormRequest
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
            'top_up_id' => [
                'required',
                'integer',
                'exists:petty_cash_top_ups,id',
            ],
            'receiver' => [
                'required',
                'string',
                'max:255',
            ],
            'account' => [
                'required',
                'string',
                'max:255',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ],
            'description' => [
                'required',
                'string',
                'max:1000',
            ],
            'project_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'classification' => [
                'required',
                'string',
                Rule::in(['agencies', 'admin', 'operations', 'other']),
            ],
            'job_number' => [
                'nullable',
                'string',
                'max:255',
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
            'top_up_id.required' => 'Please select a top-up source.',
            'top_up_id.exists' => 'The selected top-up does not exist.',
            'receiver.required' => 'The receiver field is required.',
            'receiver.max' => 'Receiver name cannot exceed 255 characters.',
            'account.required' => 'The account field is required.',
            'account.max' => 'Account cannot exceed 255 characters.',
            'amount.required' => 'The amount field is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.max' => 'The amount cannot exceed 999,999.99.',
            'description.required' => 'The description field is required.',
            'description.max' => 'Description cannot exceed 1000 characters.',
            'project_name.max' => 'Project name cannot exceed 255 characters.',
            'classification.required' => 'Please select a classification.',
            'classification.in' => 'The selected classification is invalid.',
            'job_number.max' => 'Job number cannot exceed 255 characters.',
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'transaction_code.required_unless' => 'Transaction code is required for non-cash payments.',
            'transaction_code.max' => 'Transaction code cannot exceed 255 characters.',
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
            'top_up_id' => 'top-up source',
            'payment_method' => 'payment method',
            'transaction_code' => 'transaction code',
            'job_number' => 'job number',
            'project_name' => 'project name',
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
            $topUpId = $this->input('top_up_id');
            $amount = $this->input('amount');
            $paymentMethod = $this->input('payment_method');
            $transactionCode = $this->input('transaction_code');

            // Check if top-up has sufficient balance
            if ($topUpId && $amount) {
                $topUp = PettyCashTopUp::find($topUpId);
                if ($topUp && $topUp->remaining_balance < $amount) {
                    $validator->errors()->add('amount', 
                        'Amount exceeds available balance in selected top-up. Available: KES ' . 
                        number_format($topUp->remaining_balance, 2)
                    );
                }
            }

            // Check overall balance
            if ($amount) {
                $balance = PettyCashBalance::current();
                if (!$balance->hasSufficientBalance($amount)) {
                    $validator->errors()->add('amount', 
                        'Insufficient overall balance. Current balance: KES ' . 
                        number_format($balance->getCurrentBalance(), 2)
                    );
                }
            }

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

            // Validate project name is required for certain classifications
            $projectName = $this->input('project_name');
            $classification = $this->input('classification');
            
            if (in_array($classification, ['agencies', 'operations']) && empty($projectName)) {
                $validator->errors()->add('project_name', 'Project name is required for ' . $classification . ' classification.');
            }
        });
    }
}