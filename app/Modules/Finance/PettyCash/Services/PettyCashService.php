<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;

class PettyCashService
{
    protected $repository;

    public function __construct(PettyCashRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Create a new top-up and update balance.
     */
    public function createTopUp(array $data): PettyCashTopUp
    {
        DB::beginTransaction();

        try {
            // Add creator information
            $data['created_by'] = Auth::id();

            // Create the top-up (balance will be updated automatically via model events)
            $topUp = $this->repository->createTopUp($data);

            DB::commit();

            return $topUp;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to create top-up: ' . $e->getMessage());
        }
    }

    /**
     * Create a new disbursement with balance validation.
     */
    public function createDisbursement(array $data): PettyCashDisbursement
    {
        DB::beginTransaction();

        try {
            // Validate balance before creating disbursement
            $this->validateSufficientBalance($data['amount']);

            // Add creator information
            $data['created_by'] = Auth::id();
            $data['status'] = 'active';

            // Create the disbursement (balance will be updated automatically via model events)
            $disbursement = $this->repository->createDisbursement($data);

            DB::commit();

            return $disbursement;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to create disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing disbursement.
     */
    public function updateDisbursement(PettyCashDisbursement $disbursement, array $data): PettyCashDisbursement
    {
        DB::beginTransaction();

        try {
            // If amount is being changed and disbursement is active, validate balance
            if (isset($data['amount']) && $data['amount'] != $disbursement->amount && $disbursement->is_active) {
                $amountDifference = $data['amount'] - $disbursement->amount;
                if ($amountDifference > 0) {
                    $this->validateSufficientBalance($amountDifference);
                }
            }

            // Update the disbursement
            $this->repository->updateDisbursement($disbursement, $data);

            // If amount changed, manually update balance since model events won't handle the difference
            if (isset($data['amount']) && $data['amount'] != $disbursement->getOriginal('amount') && $disbursement->is_active) {
                $this->adjustBalanceForAmountChange($disbursement, $disbursement->getOriginal('amount'), $data['amount']);
            }

            DB::commit();

            return $disbursement->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to update disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Void a disbursement and restore balance.
     */
    public function voidDisbursement(PettyCashDisbursement $disbursement, string $reason): bool
    {
        DB::beginTransaction();

        try {
            if ($disbursement->is_voided) {
                throw new Exception('Disbursement is already voided.');
            }

            // Void the disbursement (balance will be updated automatically via model events)
            $result = $this->repository->voidDisbursement($disbursement, Auth::id(), $reason);

            DB::commit();

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to void disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Get current balance with status information.
     */
    public function getCurrentBalanceInfo(): array
    {
        $balance = $this->repository->getCurrentBalance();
        
        return [
            'current_balance' => $balance->getCurrentBalance(),
            'status' => $balance->status,
            'is_low' => $balance->isLow(),
            'is_critical' => $balance->isCritical(),
            'last_transaction' => $balance->last_transaction,
            'updated_at' => $balance->updated_at,
        ];
    }

    /**
     * Validate if there's sufficient balance for a disbursement.
     */
    public function validateSufficientBalance(float $amount): bool
    {
        $balance = $this->repository->getCurrentBalance();
        
        if (!$balance->hasSufficientBalance($amount)) {
            throw new Exception(
                "Insufficient balance. Current balance: KES " . number_format($balance->getCurrentBalance(), 2) . 
                ", Required: KES " . number_format($amount, 2)
            );
        }

        return true;
    }

    /**
     * Calculate available balance from a specific top-up.
     */
    public function getTopUpAvailableBalance(int $topUpId): float
    {
        $topUp = $this->repository->findTopUp($topUpId);
        
        if (!$topUp) {
            throw new Exception('Top-up not found.');
        }

        return $topUp->remaining_balance;
    }

    /**
     * Get transaction summary with analytics.
     */
    public function getTransactionSummary(array $filters = []): array
    {
        $summary = $this->repository->getTransactionSummary($filters);
        $currentBalance = $this->getCurrentBalanceInfo();
        
        return array_merge($summary, [
            'current_balance' => $currentBalance['current_balance'],
            'balance_status' => $currentBalance['status'],
        ]);
    }

    /**
     * Get spending analytics by classification.
     */
    public function getSpendingAnalytics(array $filters = []): array
    {
        $byClassification = $this->repository->getSpendingByClassification($filters);
        $byPaymentMethod = $this->repository->getSpendingByPaymentMethod($filters);
        
        return [
            'by_classification' => $byClassification->map(function ($item) {
                return [
                    'classification' => $item->classification,
                    'total_amount' => (float) $item->total_amount,
                    'transaction_count' => $item->transaction_count,
                    'percentage' => 0, // Will be calculated on frontend
                ];
            }),
            'by_payment_method' => $byPaymentMethod->map(function ($item) {
                return [
                    'payment_method' => $item->payment_method,
                    'total_amount' => (float) $item->total_amount,
                    'transaction_count' => $item->transaction_count,
                    'percentage' => 0, // Will be calculated on frontend
                ];
            }),
        ];
    }

    /**
     * Get recent transactions for dashboard.
     */
    public function getRecentTransactions(int $limit = 10): array
    {
        return $this->repository->getRecentTransactions($limit);
    }

    /**
     * Recalculate balance from all transactions (for data integrity).
     */
    public function recalculateBalance(): array
    {
        DB::beginTransaction();

        try {
            $balance = $this->repository->getCurrentBalance();
            $oldBalance = $balance->getCurrentBalance();
            
            $balance->recalculateBalance();
            $newBalance = $balance->getCurrentBalance();
            
            DB::commit();

            return [
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'difference' => $newBalance - $oldBalance,
                'recalculated_at' => now(),
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to recalculate balance: ' . $e->getMessage());
        }
    }

    /**
     * Get balance history and trends.
     */
    public function getBalanceTrends(int $days = 30): array
    {
        // This would require a balance history table for full implementation
        // For now, return current balance info
        $currentBalance = $this->getCurrentBalanceInfo();
        
        return [
            'current' => $currentBalance,
            'trend' => 'stable', // Would be calculated from historical data
            'days_analyzed' => $days,
        ];
    }

    /**
     * Validate disbursement data before creation.
     */
    public function validateDisbursementData(array $data): array
    {
        $errors = [];

        // Validate required fields
        $requiredFields = ['top_up_id', 'receiver', 'account', 'amount', 'description', 'classification', 'payment_method'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required.";
            }
        }

        // Validate amount
        if (isset($data['amount']) && $data['amount'] <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        }

        // Validate top-up exists and has sufficient balance
        if (!empty($data['top_up_id'])) {
            $topUp = $this->repository->findTopUp($data['top_up_id']);
            if (!$topUp) {
                $errors['top_up_id'] = 'Selected top-up does not exist.';
            } elseif (isset($data['amount']) && $topUp->remaining_balance < $data['amount']) {
                $errors['amount'] = 'Amount exceeds available balance in selected top-up.';
            }
        }

        // Validate enums
        $validClassifications = ['agencies', 'admin', 'operations', 'other'];
        if (!empty($data['classification']) && !in_array($data['classification'], $validClassifications)) {
            $errors['classification'] = 'Invalid classification selected.';
        }

        $validPaymentMethods = ['cash', 'mpesa', 'bank_transfer', 'other'];
        if (!empty($data['payment_method']) && !in_array($data['payment_method'], $validPaymentMethods)) {
            $errors['payment_method'] = 'Invalid payment method selected.';
        }

        return $errors;
    }

    /**
     * Validate top-up data before creation.
     */
    public function validateTopUpData(array $data): array
    {
        $errors = [];

        // Validate required fields
        $requiredFields = ['amount', 'payment_method'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required.";
            }
        }

        // Validate amount
        if (isset($data['amount']) && $data['amount'] <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        }

        // Validate payment method
        $validPaymentMethods = ['cash', 'mpesa', 'bank_transfer', 'other'];
        if (!empty($data['payment_method']) && !in_array($data['payment_method'], $validPaymentMethods)) {
            $errors['payment_method'] = 'Invalid payment method selected.';
        }

        // Validate transaction code for non-cash payments
        if (!empty($data['payment_method']) && $data['payment_method'] !== 'cash' && empty($data['transaction_code'])) {
            $errors['transaction_code'] = 'Transaction code is required for non-cash payments.';
        }

        return $errors;
    }

    /**
     * Adjust balance when disbursement amount is changed.
     */
    private function adjustBalanceForAmountChange(PettyCashDisbursement $disbursement, float $oldAmount, float $newAmount): void
    {
        $balance = $this->repository->getCurrentBalance();
        $difference = $newAmount - $oldAmount;
        
        // If new amount is higher, subtract the difference from balance
        // If new amount is lower, add the difference back to balance
        $balance->current_balance -= $difference;
        $balance->updated_at = now();
        $balance->save();
    }
}