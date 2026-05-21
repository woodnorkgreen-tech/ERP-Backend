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
            // Row-level lock the balance record
            $balance = PettyCashBalance::where('id', 1)->lockForUpdate()->first();
            if (!$balance) {
                $balance = PettyCashBalance::create(['id' => 1, 'current_balance' => 0.00]);
            }

            $data['previous_balance'] = (float)$balance->current_balance;
            
            // Add creator information
            $data['created_by'] = Auth::id();

            // Create the top-up (balance will be updated automatically via model events)
            $topUp = $this->repository->createTopUp($data);

            // Refresh balance
            $balance->refresh();

            // Post Ledger Credit entry
            DB::table('petty_cash_ledger_entries')->insert([
                'reference_number' => 'TOP-' . str_pad($topUp->id, 6, '0', STR_PAD_LEFT),
                'type' => 'credit',
                'amount' => $topUp->amount,
                'balance_snapshot' => $balance->current_balance,
                'metadata' => json_encode([
                    'payment_method' => $topUp->payment_method ?? 'cash',
                    'transaction_code' => $topUp->transaction_code ?? null,
                    'description' => $topUp->description ?? 'Top Up',
                    'created_by' => $topUp->created_by ?? null,
                ]),
                'posted_at' => $topUp->date_topped_up ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log activity
            $this->logActivity('created', 'top_up', $topUp->id, "Top-up of KES " . number_format($topUp->amount, 2) . " created", [
                'amount' => $topUp->amount,
                'payment_method' => $topUp->payment_method
            ]);

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

            // Log activity
            $this->logActivity('updated', 'top_up', $topUp->id, "Top-up updated", [
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'data' => $data
            ]);

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
            // Row-level lock the balance record
            $balance = PettyCashBalance::where('id', 1)->lockForUpdate()->first();
            if (!$balance) {
                $balance = PettyCashBalance::create(['id' => 1, 'current_balance' => 0.00]);
            }

            $totalToDeduct = (float)$data['amount'] + (float)($data['transaction_cost'] ?? 0);

            // Validate balance before creating disbursement (unless skipped)
            if (!($data['skip_balance_check'] ?? false)) {
                if ($balance->current_balance < $totalToDeduct) {
                    return [
                        'success' => false,
                        'errors' => [
                            'amount' => ["Insufficient balance. Current balance: KES " . number_format($balance->current_balance, 2) . ", Required (Amount + Cost): KES " . number_format($totalToDeduct, 2)]
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

            // Create the disbursement (balance is updated automatically via model events)
            $disbursement = $this->repository->createDisbursement($data);

            // Refresh balance
            $balance->refresh();

            // Post Ledger Debit entry
            DB::table('petty_cash_ledger_entries')->insert([
                'reference_number' => 'PCR-' . str_pad($disbursement->id, 6, '0', STR_PAD_LEFT),
                'type' => 'debit',
                'amount' => $totalToDeduct,
                'balance_snapshot' => $balance->current_balance,
                'metadata' => json_encode([
                    'amount' => (float)$disbursement->amount,
                    'receiver' => $disbursement->receiver,
                    'account' => $disbursement->account,
                    'description' => $disbursement->description,
                    'classification' => $disbursement->classification,
                    'payment_method' => $disbursement->payment_method,
                    'transaction_code' => $disbursement->transaction_code,
                    'transaction_cost' => (float)($disbursement->transaction_cost ?? 0),
                    'budget_category' => $disbursement->budget_category ?? null,
                    'created_by' => $disbursement->created_by ?? null,
                    'project_name' => $disbursement->project_name ?? null,
                    'venue' => $disbursement->venue ?? null,
                    'job_number' => $disbursement->job_number ?? null,
                    'requisition_id' => $disbursement->requisition_id ?? null,
                    'status' => $disbursement->status ?? 'active',
                ]),
                'posted_at' => $disbursement->date_disbursed ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // If this disbursement is linked to a requisition, update the requisition status
            if (!empty($data['requisition_id'])) {
                $this->syncRequisitionStatus((int)$data['requisition_id'], 'disbursed');
                
                // PHASE 2: Handle Bill Link
                $requisition = \App\Modules\Finance\PettyCash\Models\PettyCashRequisition::find($data['requisition_id']);
                if ($requisition && $requisition->bill_id) {
                    $this->createBillPaymentFromDisbursement($requisition, $disbursement);
                }
            }

            // Log activity
            $this->logActivity('created', 'disbursement', $disbursement->id, "Disbursement of KES " . number_format($disbursement->amount, 2) . " to " . $disbursement->receiver, [
                'amount' => $disbursement->amount,
                'receiver' => $disbursement->receiver,
                'top_up_id' => $disbursement->top_up_id
            ]);

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
        throw new Exception('Mutating past financial disbursements is strictly forbidden to preserve ledger integrity. Please void this transaction and create a new one instead.');
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

            // Row-level lock the balance record
            $balance = PettyCashBalance::where('id', 1)->lockForUpdate()->first();

            // Void the disbursement (balance will be updated automatically via model events)
            $result = $this->repository->voidDisbursement($disbursement, Auth::id(), $reason);

            // Refresh balance
            $balance->refresh();

            $totalRefunded = (float)$disbursement->amount + (float)($disbursement->transaction_cost ?? 0);

            // Post Ledger Offset Reversal (credit) entry
            DB::table('petty_cash_ledger_entries')->insert([
                'reference_number' => 'VOID-' . str_pad($disbursement->id, 6, '0', STR_PAD_LEFT),
                'type' => 'credit',
                'amount' => $totalRefunded,
                'balance_snapshot' => $balance->current_balance,
                'metadata' => json_encode([
                    'payment_method' => $disbursement->payment_method ?? 'cash',
                    'transaction_code' => $disbursement->transaction_code ?? null,
                    'description' => "Void Reversal: " . $reason,
                    'disbursement_id' => $disbursement->id,
                    'voided_by' => Auth::id(),
                    'reason' => $reason,
                ]),
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Sync requisition status if linked
            if ($disbursement->requisition_id) {
                $this->syncRequisitionStatus($disbursement->requisition_id);
            }

            // Log activity
            $this->logActivity('voided', 'disbursement', $disbursement->id, "Disbursement voided. Reason: " . $reason);

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
        throw new Exception('Deleting past financial disbursements is strictly forbidden to preserve ledger integrity. Please void this transaction instead.');
    }

    /**
     * Delete multiple disbursements at once.
     */
    public function bulkDeleteDisbursements(array $disbursementIds): array
    {
        throw new Exception('Bulk deleting financial disbursements is strictly forbidden to preserve ledger integrity.');
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

            // Log activity
            $this->logActivity('cleared', null, null, "All petty cash data cleared. Deleted {$topUpsCount} top-ups and {$disbursementsCount} disbursements.");

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
            
            // Log activity
            $this->logActivity('recalculated', null, null, "Balance recalculated. Old: KES " . number_format($oldBalance, 2) . ", New: KES " . number_format($newBalance, 2), [
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'difference' => $newBalance - $oldBalance
            ]);

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

    /**
     * Archive a disbursement.
     */
    public function archiveDisbursement(\App\Modules\Finance\PettyCash\Models\PettyCashDisbursement $disbursement): bool
    {
        $result = $this->repository->archiveDisbursement($disbursement, Auth::id());
        if ($result) {
            $this->logActivity('archived', 'disbursement', $disbursement->id, "Disbursement archived");
        }
        return $result;
    }

    /**
     * Bulk archive disbursements.
     */
    public function bulkArchiveDisbursements(array $ids): int
    {
        $count = $this->repository->bulkArchiveDisbursements($ids, Auth::id());
        if ($count > 0) {
            $this->logActivity('archived', 'disbursement', null, "Bulk archived {$count} disbursements", ['ids' => $ids]);
        }
        return $count;
    }

    /**
     * Archive a top-up.
     */
    public function archiveTopUp(\App\Modules\Finance\PettyCash\Models\PettyCashTopUp $topUp): bool
    {
        $result = $this->repository->archiveTopUp($topUp, Auth::id());
        if ($result) {
            $this->logActivity('archived', 'top_up', $topUp->id, "Top-up archived");
        }
        return $result;
    }

    /**
     * Archive a top-up and all its disbursements.
     */
    public function archiveGroup(int $topUpId): bool
    {
        $result = $this->repository->archiveGroup($topUpId, Auth::id());
        if ($result) {
            $this->logActivity('archived', 'group', $topUpId, "Top-up and related disbursements archived");
        }
        return $result;
    }

    /**
     * Bulk archive multiple groups.
     */
    public function bulkArchiveGroups(array $topUpIds): int
    {
        $count = $this->repository->bulkArchiveGroups($topUpIds, Auth::id());
        if ($count > 0) {
            $this->logActivity('archived', 'group', null, "Bulk archived {$count} groups", ['top_up_ids' => $topUpIds]);
        }
        return $count;
    }

    /**
     * Create a BillPayment record from a Petty Cash disbursement.
     */
    private function createBillPaymentFromDisbursement($requisition, $disbursement): void
    {
        try {
            $paymentMethodId = \App\Modules\ProcurementStores\Models\PaymentMethod::where('name', 'Petty Cash')
                ->orWhere('name', 'like', '%Cash%')
                ->value('id') ?? 1; // Fallback to 1

            \App\Modules\ProcurementStores\Models\BillPayment::create([
                'bill_id' => $requisition->bill_id,
                'amount_paid' => $disbursement->amount,
                'payment_date' => $disbursement->date_disbursed ?? now(),
                'payment_method_id' => $paymentMethodId,
                'reference_number' => "Paid via Petty Cash Req #{$requisition->requisition_number} (Disb #{$disbursement->id})",
                'user_id' => $disbursement->created_by ?? Auth::id(),
            ]);
            
            \Log::info("Automated BillPayment created for Bill #{$requisition->bill_id} from Requisition #{$requisition->id}");
        } catch (\Exception $e) {
            \Log::error("Failed to auto-create BillPayment for Requisition #{$requisition->id}: " . $e->getMessage());
        }
    }


    /**
     * Log activity to petty_cash_activity_logs.
     */
    public function logActivity(string $action, ?string $type = null, ?int $id = null, ?string $description = null, ?array $changes = null): void
    {
        try {
            \App\Modules\Finance\PettyCash\Models\PettyCashActivityLog::create([
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'action' => $action,
                'transaction_type' => $type,
                'transaction_id' => $id,
                'description' => $description,
                'changes' => $changes,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to log petty cash activity: " . $e->getMessage());
        }
    }
}