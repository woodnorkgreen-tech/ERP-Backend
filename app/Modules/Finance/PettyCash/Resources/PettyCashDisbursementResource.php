<?php

namespace App\Modules\Finance\PettyCash\Resources;

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
            'top_up_id' => $this->top_up_id,
            'receiver' => $this->receiver,
            'account' => $this->account,
            'amount' => [
                'raw' => (float) $this->amount,
                'formatted' => 'KES ' . number_format($this->amount, 2),
            ],
            'description' => $this->description,
            'project_name' => $this->project_name,
            'classification' => [
                'value' => $this->classification,
                'label' => $this->getClassificationLabel(),
            ],
            'job_number' => $this->job_number,
            'payment_method' => [
                'value' => $this->payment_method,
                'label' => $this->getPaymentMethodLabel(),
            ],
            'transaction_code' => $this->when(
                $this->shouldShowTransactionCode($user),
                $this->transaction_code
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
     */
    private function getPaymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            'cash' => 'Cash',
            'mpesa' => 'M-Pesa',
            'bank_transfer' => 'Bank Transfer',
            'other' => 'Other',
            default => ucfirst($this->payment_method),
        };
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