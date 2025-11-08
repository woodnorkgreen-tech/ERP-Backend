<?php

namespace App\Modules\Finance\PettyCash\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class PettyCashBalanceResource extends JsonResource
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
            'current_balance' => [
                'raw' => (float) $this->current_balance,
                'formatted' => 'KES ' . number_format($this->current_balance, 2),
            ],
            'status' => [
                'value' => $this->status,
                'label' => $this->getStatusLabel(),
                'color' => $this->getStatusColor(),
                'is_low' => $this->isLow(),
                'is_critical' => $this->isCritical(),
                'is_normal' => $this->status === 'normal',
            ],
            'thresholds' => [
                'low_threshold' => [
                    'raw' => 1000.00,
                    'formatted' => 'KES 1,000.00',
                ],
                'critical_threshold' => [
                    'raw' => 500.00,
                    'formatted' => 'KES 500.00',
                ],
            ],
            'last_transaction' => $this->when(
                $this->last_transaction,
                fn() => [
                    'id' => $this->last_transaction_id,
                    'type' => $this->last_transaction_type,
                    'type_label' => $this->getTransactionTypeLabel(),
                    'amount' => [
                        'raw' => (float) $this->last_transaction['amount'],
                        'formatted' => 'KES ' . number_format($this->last_transaction['amount'], 2),
                    ],
                    'description' => $this->last_transaction['description'] ?? null,
                    'receiver' => $this->last_transaction['receiver'] ?? null,
                    'created_at' => [
                        'raw' => $this->last_transaction['created_at']->toISOString(),
                        'formatted' => $this->last_transaction['created_at']->format('M j, Y g:i A'),
                        'human' => $this->last_transaction['created_at']->diffForHumans(),
                    ],
                ]
            ),
            'updated_at' => [
                'raw' => $this->updated_at->toISOString(),
                'formatted' => $this->updated_at->format('M j, Y g:i A'),
                'human' => $this->updated_at->diffForHumans(),
            ],
            
            // Health indicators
            'health_indicators' => [
                'days_since_last_top_up' => $this->getDaysSinceLastTopUp(),
                'average_daily_spending' => $this->getAverageDailySpending(),
                'estimated_days_remaining' => $this->getEstimatedDaysRemaining(),
                'needs_attention' => $this->needsAttention(),
            ],
            
            // Permissions
            'can_add_top_up' => $this->canAddTopUp($user),
            'can_create_disbursement' => $this->canCreateDisbursement($user),
            'can_recalculate' => $this->canRecalculate($user),
        ];
    }

    /**
     * Get the status label.
     */
    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'normal' => 'Normal',
            'low' => 'Low Balance',
            'critical' => 'Critical Balance',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get the status color for UI.
     */
    private function getStatusColor(): string
    {
        return match ($this->status) {
            'normal' => 'green',
            'low' => 'yellow',
            'critical' => 'red',
            default => 'gray',
        };
    }

    /**
     * Get the transaction type label.
     */
    private function getTransactionTypeLabel(): string
    {
        return match ($this->last_transaction_type) {
            'top_up' => 'Top-up',
            'disbursement' => 'Disbursement',
            default => ucfirst($this->last_transaction_type ?? ''),
        };
    }

    /**
     * Get days since last top-up.
     */
    private function getDaysSinceLastTopUp(): ?int
    {
        // This would require additional logic to find the last top-up
        // For now, return null as placeholder
        return null;
    }

    /**
     * Get average daily spending.
     */
    private function getAverageDailySpending(): ?float
    {
        // This would require additional logic to calculate average spending
        // For now, return null as placeholder
        return null;
    }

    /**
     * Get estimated days remaining based on current spending pattern.
     */
    private function getEstimatedDaysRemaining(): ?int
    {
        // This would require additional logic to estimate remaining days
        // For now, return null as placeholder
        return null;
    }

    /**
     * Check if balance needs attention.
     */
    private function needsAttention(): bool
    {
        return $this->isLow() || $this->isCritical();
    }

    /**
     * Check if user can add top-up.
     */
    private function canAddTopUp($user): bool
    {
        return $user && $user->can('finance.petty_cash.create_top_up');
    }

    /**
     * Check if user can create disbursement.
     */
    private function canCreateDisbursement($user): bool
    {
        if (!$user || $this->current_balance <= 0) {
            return false;
        }

        return $user->can('finance.petty_cash.create');
    }

    /**
     * Check if user can recalculate balance.
     */
    private function canRecalculate($user): bool
    {
        return $user && $user->can('finance.petty_cash.admin');
    }

    /**
     * Get additional data that should be wrapped.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'petty_cash_balance',
                'version' => '1.0',
            ],
        ];
    }
}