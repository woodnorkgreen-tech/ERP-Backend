<?php

namespace App\Modules\Finance\PettyCash\Requests;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDisbursementRequest extends FormRequest
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
                'sometimes',
                'integer',
                'exists:petty_cash_top_ups,id',
            ],
            'receiver' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'account' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'amount' => [
                'sometimes',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ],
            'description' => [
                'sometimes',
                'string',
                'max:1000',
            ],
            'project_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'classification' => [
                'sometimes',
                'string',
                Rule::in(['agencies', 'admin', 'operations', 'other']),
            ],
            'job_number' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payment_method' => [
                'sometimes',
                'string',
                Rule::in(['cash', 'mpesa', 'bank_transfer', 'other']),
            ],
            'transaction_code' => [
                'nullable',
                'string',
                'max:255',
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
            'top_up_id.exists' => 'The selected top-up does not exist.',
            'receiver.max' => 'Receiver name cannot exceed 255 characters.',
            'account.max' => 'Account cannot exceed 255 characters.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.max' => 'The amount cannot exceed 999,999.99.',
            'description.max' => 'Description cannot exceed 1000 characters.',
            'project_name.max' => 'Project name cannot exceed 255 characters.',
            'classification.in' => 'The selected classification is invalid.',
            'job_number.max' => 'Job number cannot exceed 255 characters.',
            'payment_method.in' => 'The selected payment method is invalid.',
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
            $disbursement = $this->route('disbursement');
            
            // Check if disbursement exists and is not voided
            if ($disbursement instanceof PettyCashDisbursement && $disbursement->is_voided) {
                $validator->errors()->add('status', 'Cannot update voided disbursement.');
                return;
            }

            $topUpId = $this->input('top_up_id');
            $amount = $this->input('amount');
            $paymentMethod = $this->input('payment_method');
            $transactionCode = $this->input('transaction_code');

            // Check if top-up has sufficient balance (considering current disbursement amount)
            if ($topUpId && $amount && $disbursement) {
                $topUp = PettyCashTopUp::find($topUpId);
                if ($topUp) {
                    $availableBalance = $topUp->remaining_balance;
                    
                    // If changing top-up source, use the new top-up's balance
                    if ($topUpId != $disbursement->top_up_id) {
                        $requiredBalance = $amount;
                    } else {
                        // If same top-up, add back the current disbursement amount
                        $requiredBalance = $amount - $disbursement->amount;
                    }
                    
                    if ($availableBalance < $requiredBalance) {
                        $validator->errors()->add('amount', 
                            'Amount exceeds available balance in selected top-up. Available: KES ' . 
                            number_format($availableBalance, 2)
                        );
                    }
                }
            }

            // Check overall balance for amount changes
            if ($amount && $disbursement) {
                $balance = PettyCashBalance::current();
                $amountDifference = $amount - $disbursement->amount;
                
                if ($amountDifference > 0 && !$balance->hasSufficientBalance($amountDifference)) {
                    $validator->errors()->add('amount', 
                        'Insufficient balance for amount increase. Current balance: KES ' . 
                        number_format($balance->getCurrentBalance(), 2)
                    );
                }
            }

            // Validate transaction code for specific payment methods
            $currentPaymentMethod = $paymentMethod ?: ($disbursement ? $disbursement->payment_method : null);
            if (in_array($currentPaymentMethod, ['mpesa', 'bank_transfer'])) {
                $currentTransactionCode = $transactionCode ?: ($disbursement ? $disbursement->transaction_code : null);
                
                if (empty($currentTransactionCode)) {
                    $validator->errors()->add('transaction_code', 'Transaction code is required for ' . $currentPaymentMethod . ' payments.');
                }
                
                // Validate M-Pesa transaction code format
                if ($currentPaymentMethod === 'mpesa' && $currentTransactionCode) {
                    if (!preg_match('/^[A-Z0-9]{10}$/', $currentTransactionCode)) {
                        $validator->errors()->add('transaction_code', 'M-Pesa transaction code must be 10 characters long and contain only uppercase letters and numbers.');
                    }
                }
            }

            // Validate project name is required for certain classifications
            $classification = $this->input('classification') ?: ($disbursement ? $disbursement->classification : null);
            $projectName = $this->input('project_name') !== null ? $this->input('project_name') : ($disbursement ? $disbursement->project_name : null);
            
            if (in_array($classification, ['agencies', 'operations']) && empty($projectName)) {
                $validator->errors()->add('project_name', 'Project name is required for ' . $classification . ' classification.');
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Remove null values to avoid overriding existing data with null
        $this->merge(array_filter($this->all(), function ($value) {
            return $value !== null;
        }));
    }
}