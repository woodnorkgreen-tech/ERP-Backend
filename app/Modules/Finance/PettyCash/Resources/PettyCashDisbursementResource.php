<?php

namespace App\Modules\Finance\PettyCash\Resources;

use App\Modules\Finance\Support\PaymentMethods;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class PettyCashDisbursementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = Auth::user();
        
        return [
            'id' => $this->id,
            // The ERP's own identifier for this payment. Before it existed the
            // payee's M-Pesa code was the only reference a payment had.
            'payment_no' => $this->payment_no,
            'payment_type' => $this->payment_type,
            'top_up_id' => $this->top_up_id,
            'payee_name' => $this->payee_name,
            'account' => $this->account,
            'expense_code_id' => $this->expense_code_id,
            'amount' => [
                'raw' => (float) $this->amount,
                'formatted' => 'KES ' . number_format($this->amount, 2),
            ],
            'description' => $this->description,
            'project_name' => $this->project_name,
            'project_id' => $this->project_id,
            'project_enquiry_id' => $this->project_enquiry_id,
            'classification' => [
                'value' => $this->classification,
                'label' => $this->getClassificationLabel(),
            ],
            'job_number' => $this->job_number,
            'planned_cost_line_id' => $this->planned_cost_line_id,
            'budget_category' => $this->budget_category,
            'payment_method' => [
                'value' => $this->payment_method,
                'label' => $this->getPaymentMethodLabel(),
            ],
            'payment_source_id' => $this->payment_source_id,
            // The account the money left, named. A list that shows only an id
            // makes every caller look the account up again.
            'payment_source' => $this->whenLoaded('paymentSource', fn () => [
                'id' => $this->paymentSource->id,
                'code' => $this->paymentSource->code,
                'name' => $this->paymentSource->name,
                'type' => $this->paymentSource->type,
            ]),
            'receipt_type' => $this->receipt_type,
            'receipt_number' => $this->receipt_number,
            'tax_amount' => $this->tax_amount,
            'transaction_cost' => $this->transaction_cost,
            'direct_payment_reason' => $this->direct_payment_reason,
            'external_reference' => $this->when(
                $this->shouldShowTransactionCode($user),
                $this->external_reference
            ),
            'status' => [
                'value' => $this->status,
                'label' => $this->getStatusLabel(),
                'is_active' => $this->is_active,
                'is_voided' => $this->is_voided,
            ],
            'void_reason' => $this->when($this->is_voided, $this->void_reason),
            'voided_at' => $this->when($this->is_voided, [
                'raw' => $this->voided_at?->toISOString(),
                'formatted' => $this->voided_at?->format('M j, Y g:i A'),
                'human' => $this->voided_at?->diffForHumans(),
            ]),
            'date_disbursed' => [
                'raw' => $this->date_disbursed?->format('Y-m-d'),
                'formatted' => $this->date_disbursed?->format('M j, Y'),
            ],
            'created_at' => [
                'raw' => $this->created_at->toISOString(),
                'formatted' => $this->created_at->format('M j, Y g:i A'),
                'human' => $this->created_at->diffForHumans(),
                'date_only' => $this->created_at->format('Y-m-d'),
            ],
            'updated_at' => [
                'raw' => $this->updated_at->toISOString(),
                'formatted' => $this->updated_at->format('M j, Y g:i A'),
                'human' => $this->updated_at->diffForHumans(),
            ],
            
            // Relationships
            'top_up' => $this->when(
                $this->relationLoaded('topUp'),
                fn() => new PettyCashTopUpResource($this->topUp)
            ),
            'creator' => $this->when(
                $this->relationLoaded('creator'),
                fn() => [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'email' => $this->when(
                        $this->shouldShowCreatorEmail($user),
                        $this->creator->email
                    ),
                ]
            ),
            'voided_by' => $this->when(
                $this->relationLoaded('voidedBy') && $this->voidedBy,
                fn() => [
                    'id' => $this->voidedBy->id,
                    'name' => $this->voidedBy->name,
                    'email' => $this->when(
                        $this->shouldShowVoidedByEmail($user),
                        $this->voidedBy->email
                    ),
                ]
            ),
            
            // Computed fields
            'can_edit' => $this->canEdit($user),
            'can_void' => $this->canVoid($user),
            'can_view_details' => $this->canViewDetails($user),
            'budget_category' => $this->budget_category,
        ];
    }

    /**
     * Get the classification label.
     */
    private function getClassificationLabel(): string
    {
        return match ($this->classification) {
            'agencies' => 'Agencies',
            'admin' => 'Administration',
            'operations' => 'Operations',
            'other' => 'Other',
            default => ucfirst($this->classification),
        };
    }

    /**
     * Get the payment method label.
     *
     * From PaymentMethods, not a local match(). The copy this replaced knew
     * only cash, M-Pesa and bank transfer, so cheque, RTGS, EFT and card fell
     * through to ucfirst() and rendered as "Rtgs" and "Eft".
     */
    private function getPaymentMethodLabel(): string
    {
        return PaymentMethods::label((string) $this->payment_method);
    }

    /**
     * Get the status label.
     */
    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'voided' => 'Voided',
            default => ucfirst($this->status),
        };
    }

    /**
     * Check if user should see transaction code.
     */
    private function shouldShowTransactionCode($user): bool
    {
        // Show transaction code if user has permission or is the creator
        return $user && (
            $user->can('finance.petty_cash.view_transaction_codes') ||
            $user->id === $this->created_by
        );
    }

    /**
     * Check if user should see creator email.
     */
    private function shouldShowCreatorEmail($user): bool
    {
        return $user && (
            $user->can('finance.petty_cash.view_user_details') ||
            $user->id === $this->created_by
        );
    }

    /**
     * Check if user should see voided by email.
     */
    private function shouldShowVoidedByEmail($user): bool
    {
        return $user && $user->can('finance.petty_cash.view_user_details');
    }

    /**
     * Check if user can edit this disbursement.
     */
    private function canEdit($user): bool
    {
        if (!$user || $this->is_voided) {
            return false;
        }

        return $user->can('finance.petty_cash.update') ||
               ($user->can('finance.petty_cash.update_own') && $user->id === $this->created_by);
    }

    /**
     * Check if user can void this disbursement.
     */
    private function canVoid($user): bool
    {
        if (!$user || $this->is_voided) {
            return false;
        }

        return $user->can('finance.petty_cash.void') ||
               ($user->can('finance.petty_cash.void_own') && $user->id === $this->created_by);
    }

    /**
     * Check if user can view full details.
     */
    private function canViewDetails($user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can('finance.petty_cash.view') ||
               ($user->can('finance.petty_cash.view_own') && $user->id === $this->created_by);
    }

    /**
     * Get additional data that should be wrapped.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'petty_cash_disbursement',
                'version' => '1.0',
            ],
        ];
    }
}
