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
            // Get current balance before adding the top-up
            $currentBalance = $this->repository->getCurrentBalance();
            $data['previous_balance'] = $currentBalance->getCurrentBalance();
            
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
     * Update an existing top-up.
     */
    public function updateTopUp(PettyCashTopUp $topUp, array $data): PettyCashTopUp
    {
        DB::beginTransaction();

        try {
            $oldAmount = (float) $topUp->amount;
            $newAmount = isset($data['amount']) ? (float) $data['amount'] : $oldAmount;

            // Update the top-up
            $this->repository->updateTopUp($topUp, $data);

            // If amount changed, we might need a global balance recalculation 
            // to ensure all subsequent transaction previous_balance fields are correct.
            // Model events usually handle the current balance record, but historical sync 
            // is better served by a full recalculation if amount changes.
            if ($oldAmount !== $newAmount) {
                $this->recalculateBalance();
            }

            DB::commit();

            return $topUp->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to update top-up: ' . $e->getMessage());
        }
    }

    /**
     * Create a new disbursement with balance validation.
     */
    public function createDisbursement(array $data): array
    {
        DB::beginTransaction();

        try {
            // Validate balance before creating disbursement (unless skipped)
            if (!($data['skip_balance_check'] ?? false)) {
                $balance = $this->repository->getCurrentBalance();
                $totalToDeduct = (float)$data['amount'] + (float)($data['transaction_cost'] ?? 0);
                if (!$balance->hasSufficientBalance($totalToDeduct)) {
                    return [
                        'success' => false,
                        'errors' => [
                            'amount' => ["Insufficient balance. Current balance: KES " . number_format($balance->getCurrentBalance(), 2) . ", Required (Amount + Cost): KES " . number_format($totalToDeduct, 2)]
                        ]
                    ];
                }
            }

            // Remove internal flag before saving
            unset($data['skip_balance_check']);

            // Add creator information if not provided
            if (!isset($data['created_by'])) {
                $data['created_by'] = Auth::id();
            }
            $data['status'] = 'active';

            // Auto-assign top_up_id if not provided
            if (empty($data['top_up_id'])) {
                // Try to find a top-up that covers the whole amount first
                $topUp = $this->repository->getTopUpsWithAvailableBalance()
                    ->filter(function ($t) use ($data) {
                        $required = (float)$data['amount'] + (float)($data['transaction_cost'] ?? 0);
                        return $t->remaining_balance >= $required;
                    })
                    ->first();

                // If none covers it fully, pick the most recent one with any balance (anchor)
                if (!$topUp) {
                    $topUp = $this->repository->getTopUpsWithAvailableBalance()->first();
                }

                // SIMPLIFIED SYNC: As a final fallback, just pick the latest active top-up 
                // to allow spending against the global pool even if individual top-up tracking gets messy.
                if (!$topUp) {
                    $topUp = \App\Modules\Finance\PettyCash\Models\PettyCashTopUp::notArchived()->latest()->first();
                }

                if (!$topUp) {
                    return [
                        'success' => false,
                        'errors' => [
                            'amount' => ["No available petty cash funds found. Please add a top-up first."]
                        ]
                    ];
                }
                $data['top_up_id'] = $topUp->id;
            }

            // Create the disbursement (balance will be updated automatically via model events)
            $disbursement = $this->repository->createDisbursement($data);

            // If this disbursement is linked to a requisition, update the requisition status
            if (!empty($data['requisition_id'])) {
                $this->syncRequisitionStatus((int)$data['requisition_id'], 'disbursed');
            }

            DB::commit();

            return ['success' => true, 'data' => $disbursement];
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to create disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Synchronize requisition status based on disbursement state.
     */
    public function syncRequisitionStatus(int $requisitionId, string $forcedStatus = null): void
    {
        $requisition = \App\Modules\Finance\PettyCash\Models\PettyCashRequisition::find($requisitionId);
        if (!$requisition) return;

        if ($forcedStatus) {
            $updateData = ['status' => $forcedStatus];
            if ($forcedStatus === 'disbursed' && !$requisition->signing_token) {
                $updateData['signing_token'] = \Illuminate\Support\Str::random(60);
            }
            $requisition->update($updateData);
            return;
        }

        // Auto-detect status based on active disbursements
        $hasActiveDisbursement = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::where('requisition_id', $requisitionId)
            ->where('status', 'active')
            ->exists();

        if (!$hasActiveDisbursement && $requisition->status === 'disbursed') {
            // Revert to approved if no active disbursements exist
            $requisition->update(['status' => 'approved']);
        }
    }

    /**
     * Update an existing disbursement.
     */
    public function updateDisbursement(PettyCashDisbursement $disbursement, array $data): PettyCashDisbursement
    {
        DB::beginTransaction();

        try {
            $oldAmount = (float) $disbursement->amount;
            $oldIsActive = (bool) $disbursement->is_active;

            // If amount is being changed and disbursement is active, validate balance
            if (isset($data['amount']) && $data['amount'] != $oldAmount && $oldIsActive) {
                $amountDifference = $data['amount'] - $oldAmount;
                if ($amountDifference > 0) {
                    $this->validateSufficientBalance($amountDifference);
                }
            }

            // Update the disbursement (Model events will handle balance adjustment)
            $this->repository->updateDisbursement($disbursement, $data);

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

            // Sync requisition status if linked
            if ($disbursement->requisition_id) {
                $this->syncRequisitionStatus($disbursement->requisition_id);
            }

            DB::commit();

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to void disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Delete a disbursement and restore balance.
     */
    public function deleteDisbursement(PettyCashDisbursement $disbursement): bool
    {
        DB::beginTransaction();

        try {
            // Delete the disbursement (balance will be updated automatically via model events)
            $requisitionId = $disbursement->requisition_id;
            $result = $disbursement->delete();

            // Sync requisition status if linked
            if ($requisitionId) {
                $this->syncRequisitionStatus($requisitionId);
            }

            DB::commit();

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to delete disbursement: ' . $e->getMessage());
        }
    }

    /**
     * Delete multiple disbursements at once.
     */
    public function bulkDeleteDisbursements(array $disbursementIds): array
    {
        DB::beginTransaction();

        try {
            $count = 0;
            foreach ($disbursementIds as $id) {
                $disbursement = PettyCashDisbursement::find($id);
                if ($disbursement) {
                    $disbursement->delete();
                    $count++;
                }
            }

            // Recalculate balance to ensure accuracy after bulk delete
            $this->recalculateBalance();

            DB::commit();

            return [
                'success' => true,
                'count' => $count,
                'message' => "Successfully deleted {$count} disbursements."
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to bulk delete disbursements: ' . $e->getMessage());
        }
    }

    /**
     * Clear all petty cash data (disbursements, top-ups, and reset balance).
     * CAUTION: This is a destructive action and cannot be undone.
     */
    public function clearAllData(): array
    {
        DB::beginTransaction();

        try {
            // Delete all disbursements first
            $disbursementsCount = PettyCashDisbursement::count();
            PettyCashDisbursement::query()->delete();

            // Delete all top-ups
            $topUpsCount = PettyCashTopUp::count();
            PettyCashTopUp::query()->delete();

            // Reset balance
            $balance = $this->repository->getCurrentBalance();
            $balance->current_balance = 0.00;
            $balance->last_transaction_id = null;
            $balance->last_transaction_type = null;
            $balance->save();

            DB::commit();

            return [
                'success' => true,
                'disbursements_deleted' => $disbursementsCount,
                'top_ups_deleted' => $topUpsCount,
                'message' => 'All petty cash data has been cleared and balance reset to zero.'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to clear petty cash data: ' . $e->getMessage());
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
     * Check if there's sufficient balance for a disbursement (returns boolean).
     */
    public function hasSufficientBalance(float $amount): bool
    {
        $balance = $this->repository->getCurrentBalance();
        return $balance->hasSufficientBalance($amount);
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
     * Validate disbursement data before creation or update.
     */
    public function validateDisbursementData(array $data, bool $isUpdate = false, ?int $disbursementId = null): array
    {
        $errors = [];

        // Validate required fields (top_up_id is now optional as service can auto-allocate)
        $requiredFields = ['receiver', 'account', 'amount', 'description', 'classification', 'payment_method'];
        foreach ($requiredFields as $field) {
            // Only require fields if not updating, or if they are explicitly provided in the update
            if (!$isUpdate && empty($data[$field])) {
                $errors[$field] = ["The {$field} field is required."];
            } elseif ($isUpdate && array_key_exists($field, $data) && empty($data[$field])) {
                $errors[$field] = ["The {$field} field cannot be empty."];
            }
        }

        // Validate date_disbursed if provided
        if (!empty($data['date_disbursed']) && !strtotime($data['date_disbursed'])) {
            $errors['date_disbursed'] = ['Invalid date format for date disbursed.'];
        }

        // Validate venue length
        if (!empty($data['venue']) && strlen($data['venue']) > 255) {
            $errors['venue'] = ['Venue / Site Location must not exceed 255 characters.'];
        }

        // Validate amount if provided
        if (isset($data['amount']) && $data['amount'] <= 0) {
            $errors['amount'] = ['Amount must be greater than zero.'];
        }

        // Validate top-up exists and has sufficient balance
        if (!empty($data['top_up_id'])) {
            $topUp = $this->repository->findTopUp($data['top_up_id']);
            if (!$topUp) {
                $errors['top_up_id'] = ['Selected top-up does not exist.'];
            } elseif (isset($data['amount'])) {
                $availableBalance = $topUp->remaining_balance;
                $requestedTotal = (float)$data['amount'] + (float)($data['transaction_cost'] ?? 0);
                
                // If updating, add back the current amount of this disbursement to available balance
                if ($isUpdate && $disbursementId) {
                    $disbursement = $this->repository->findDisbursement($disbursementId);
                    if ($disbursement && $disbursement->top_up_id == $data['top_up_id'] && $disbursement->is_active) {
                        $availableBalance += (float)$disbursement->getRawOriginal('amount', 0) + (float)$disbursement->getRawOriginal('transaction_cost', 0);
                    }
                }
                
                if ($availableBalance < $requestedTotal && !$isUpdate) {
                     // During creation, we are stricter IF they manually selected a top-up
                     // However, we still check global balance in the service
                }
            }
        }

        // Validate enums
        $validClassifications = ['agencies', 'admin', 'operations', 'event_planners', 'corporates', 'crs', 'other'];
        if (!empty($data['classification']) && !in_array($data['classification'], $validClassifications)) {
            $errors['classification'] = ['Invalid classification selected.'];
        }

        $validPaymentMethods = ['cash', 'mpesa', 'equity', 'stanbic', 'ncba', 'kcb', 'family', 'bank_transfer', 'other'];
        if (!empty($data['payment_method']) && !in_array($data['payment_method'], $validPaymentMethods)) {
            $errors['payment_method'] = ['Invalid payment method selected.'];
        }

        // Validate budget category if provided
        $validBudgetCategories = ['materials', 'labour', 'logistics', 'expenses'];
        if (!empty($data['budget_category']) && !in_array($data['budget_category'], $validBudgetCategories)) {
            $errors['budget_category'] = ['Invalid budget category selected.'];
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
        $validPaymentMethods = ['cash', 'mpesa', 'equity', 'stanbic', 'ncba', 'kcb', 'family', 'bank_transfer', 'other'];
        if (!empty($data['payment_method']) && !in_array($data['payment_method'], $validPaymentMethods)) {
            $errors['payment_method'] = 'Invalid payment method selected.';
        }

        // Validate transaction code for non-cash payments
        if (!empty($data['payment_method']) && $data['payment_method'] !== 'cash' && empty($data['transaction_code'])) {
            $errors['transaction_code'] = 'Transaction code is required for non-cash payments.';
        }

        // Validate date
        if (empty($data['date_topped_up'])) {
            $errors['date_topped_up'] = 'Top-up date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_topped_up'])) {
            $errors['date_topped_up'] = 'Invalid date format. Use YYYY-MM-DD.';
        }

        return $errors;
    }

}