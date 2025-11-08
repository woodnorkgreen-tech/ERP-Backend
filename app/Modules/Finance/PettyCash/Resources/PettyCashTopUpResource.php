<?php

namespace App\Modules\Finance\PettyCash\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class PettyCashTopUpResource extends JsonResource
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
            'amount' => [
                'raw' => (float) $this->amount,
                'formatted' => 'KES ' . number_format($this->amount, 2),
            ],
            'payment_method' => [
                'value' => $this->payment_method,
                'label' => $this->getPaymentMethodLabel(),
            ],
            'transaction_code' => $this->when(
                $this->shouldShowTransactionCode($user),
                $this->transaction_code
            ),
            'description' => $this->description,
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
            
            // Calculated fields
            'remaining_balance' => [
                'raw' => (float) $this->remaining_balance,
                'formatted' => 'KES ' . number_format($this->remaining_balance, 2),
            ],
            'total_disbursed' => [
                'raw' => (float) ($this->amount - $this->remaining_balance),
                'formatted' => 'KES ' . number_format($this->amount - $this->remaining_balance, 2),
            ],
            'utilization_percentage' => $this->amount > 0 
                ? round((($this->amount - $this->remaining_balance) / $this->amount) * 100, 2)
                : 0,
            'is_fully_disbursed' => $this->is_fully_disbursed,
            'has_available_balance' => $this->remaining_balance > 0,
            
            // Relationships
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
            'disbursements' => $this->when(
                $this->relationLoaded('disbursements'),
                fn() => PettyCashDisbursementResource::collection($this->disbursements)
            ),
            'active_disbursements' => $this->when(
                $this->relationLoaded('activeDisbursements'),
                fn() => PettyCashDisbursementResource::collection($this->activeDisbursements)
            ),
            
            // Summary data when disbursements are loaded
            'disbursements_summary' => $this->when(
                $this->relationLoaded('disbursements'),
                fn() => [
                    'total_count' => $this->disbursements->count(),
                    'active_count' => $this->disbursements->where('status', 'active')->count(),
                    'voided_count' => $this->disbursements->where('status', 'voided')->count(),
                    'total_amount' => [
                        'raw' => (float) $this->disbursements->where('status', 'active')->sum('amount'),
                        'formatted' => 'KES ' . number_format($this->disbursements->where('status', 'active')->sum('amount'), 2),
                    ],
                    'classifications' => $this->disbursements
                        ->where('status', 'active')
                        ->groupBy('classification')
                        ->map(function ($group, $classification) {
                            return [
                                'classification' => $classification,
                                'count' => $group->count(),
                                'total_amount' => [
                                    'raw' => (float) $group->sum('amount'),
                                    'formatted' => 'KES ' . number_format($group->sum('amount'), 2),
                                ],
                            ];
                        })
                        ->values(),
                ]
            ),
            
            // Permissions and actions
            'can_create_disbursement' => $this->canCreateDisbursement($user),
            'can_view_details' => $this->canViewDetails($user),
            'can_view_disbursements' => $this->canViewDisbursements($user),
        ];
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
     * Check if user can create disbursement from this top-up.
     */
    private function canCreateDisbursement($user): bool
    {
        if (!$user || $this->remaining_balance <= 0) {
            return false;
        }

        return $user->can('finance.petty_cash.create');
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
     * Check if user can view disbursements.
     */
    private function canViewDisbursements($user): bool
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
                'resource_type' => 'petty_cash_top_up',
                'version' => '1.0',
            ],
        ];
    }
}