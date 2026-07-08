<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use App\Modules\Finance\PettyCash\Services\TopUpAllocator;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;

class PettyCashService
{
    protected $repository;
    protected ProjectIdentityResolver $projectIdentityResolver;

    public function __construct(PettyCashRepository $repository, ?ProjectIdentityResolver $projectIdentityResolver = null)
    {
        $this->repository = $repository;
        $this->projectIdentityResolver = $projectIdentityResolver ?? new ProjectIdentityResolver();
    }

    /**
     * Create a new top-up and update balance.
     */
    public function createTopUp(array $data): PettyCashTopUp
    {
        try {
            return DB::transaction(function () use ($data) {
                // Row-level lock the balance record
                $balance = PettyCashBalance::where('id', 1)->lockForUpdate()->first();
                if (!$balance) {
                    $balance = PettyCashBalance::create(['id' => 1, 'current_balance' => 0.00]);
                }

                $data['previous_balance'] = (float)$balance->current_balance;

                // Add creator information
                $data['created_by'] = Auth::id();

                $topUp = $this->repository->createTopUp($data);

                $ledger = new LedgerService();
                $ledger->post(LedgerEntry::creditForTopUp($topUp));

                // Refresh balance
                $balance->refresh();

                // Log activity
                $this->logActivity('created', 'top_up', $topUp->id, "Top-up of KES " . number_format($topUp->amount, 2) . " created", [
                    'amount' => $topUp->amount,
                    'payment_method' => $topUp->payment_method
                ]);

                return $topUp;
            });
        } catch (Exception $e) {
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
            $updatedTopUp = $topUp->fresh();

            if ($oldAmount !== $newAmount) {
                $difference = $newAmount - $oldAmount;
                $entry = LedgerEntry::custom(
                    'TOP-' . str_pad((string)$topUp->id, 6, '0', STR_PAD_LEFT) . '-ADJ-' . now()->timestamp,
                    $difference > 0 ? 'credit' : 'debit',
                    number_format(abs($difference), 2, '.', ''),
                    [
                        'payment_method' => $updatedTopUp->payment_method,
                        'description' => $updatedTopUp->description,
                        'note' => 'Top-up amount adjusted',
                        'old_amount' => $oldAmount,
                        'new_amount' => $newAmount,
                    ]
                );
                $entry->sourceType = 'top_up';
                $entry->sourceId = $topUp->id;
                (new LedgerService())->post($entry);
            }

            // Log activity
            $this->logActivity('updated', 'top_up', $topUp->id, "Top-up updated", [
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'data' => $data
            ]);

            DB::commit();

            return $updatedTopUp;
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
        try {
            return DB::transaction(function () use ($data) {
                $data = $this->projectIdentityResolver->resolve($data);

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
                $plannedAllocations = [];

                // Auto-assign top_up_id if not provided using FIFO allocator (may split across top-ups)
                if (empty($data['top_up_id'])) {
                    $allocator = new TopUpAllocator($this->repository);
                    try {
                        $allocations = $allocator->plan((float)$data['amount'], (float)($data['transaction_cost'] ?? 0));
                    } catch (Exception $e) {
                        return [
                            'success' => false,
                            'errors' => ['amount' => [$e->getMessage()]]
                        ];
                    }

                    // If single allocation, set top_up_id directly, otherwise we'll persist allocations after creating the disbursement
                    if (count($allocations) === 1) {
                        $data['top_up_id'] = $allocations[0]['top_up_id'];
                    } else {
                        // assign first top-up as primary pointer
                        $data['top_up_id'] = $allocations[0]['top_up_id'];
                        // remember planned allocations to persist after creating disbursement
                        $plannedAllocations = $allocations;
                    }
                } else {
                    $topUp = $this->repository->findTopUp((int) $data['top_up_id']);
                    if (!$topUp) {
                        return [
                            'success' => false,
                            'errors' => ['top_up_id' => ['Selected top-up does not exist.']]
                        ];
                    }

                    $availableBalance = (float) $topUp->remaining_balance;
                    if ($availableBalance < $totalToDeduct) {
                        return [
                            'success' => false,
                            'errors' => [
                                'amount' => [
                                    'Selected top-up has insufficient balance. Available: KES ' .
                                    number_format($availableBalance, 2) .
                                    ', Required (Amount + Cost): KES ' .
                                    number_format($totalToDeduct, 2)
                                ]
                            ]
                        ];
                    }
                }

                $disbursement = $this->repository->createDisbursement($data);

                // If we planned a split across multiple top-ups, persist allocation records
                if (!empty($plannedAllocations) && is_array($plannedAllocations) && count($plannedAllocations) > 1) {
                    foreach ($plannedAllocations as $alloc) {
                        PettyCashDisbursementAllocation::create([
                            'disbursement_id' => $disbursement->id,
                            'top_up_id' => $alloc['top_up_id'],
                            'amount' => $alloc['amount'],
                            'transaction_cost' => $alloc['transaction_cost'] ?? '0.00',
                        ]);
                    }
                }

                $ledger = new LedgerService();
                $ledger->post(LedgerEntry::debitForDisbursement($disbursement));

                // Refresh balance
                $balance->refresh();

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

                return ['success' => true, 'data' => $disbursement];
            });
        } catch (Exception $e) {
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
            if (!$balance) {
                $balance = PettyCashBalance::create(['id' => 1, 'current_balance' => 0.00]);
            }

            $result = $this->repository->voidDisbursement($disbursement, Auth::id(), $reason);

            $totalRefunded = bcadd((string)$disbursement->amount, (string)($disbursement->transaction_cost ?? '0'), 2);
            $ledger = new LedgerService();
            $entry = LedgerEntry::custom('PCR-' . str_pad((string)$disbursement->id, 6, '0', STR_PAD_LEFT) . '-VOID', 'credit', number_format($totalRefunded, 2, '.', ''), [
                'amount' => (float)$disbursement->amount,
                'transaction_cost' => (float)($disbursement->transaction_cost ?? 0),
                'receiver' => $disbursement->receiver,
                'account' => $disbursement->account,
                'description' => $disbursement->description,
                'note' => 'Disbursement voided',
                'reason' => $reason,
            ]);
            $entry->sourceType = 'disbursement';
            $entry->sourceId = $disbursement->id;
            $ledger->post($entry);

            // Refresh balance
            $balance->refresh();

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
            // Delete all dependent allocations first
            $allocationCount = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation::count();
            \App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation::query()->delete();

            // Delete all disbursements and top-ups
            $disbursementsCount = PettyCashDisbursement::count();
            PettyCashDisbursement::query()->delete();

            $topUpsCount = PettyCashTopUp::count();
            PettyCashTopUp::query()->delete();

            // Delete ledger history and rebuild balance projection from the remaining ledger (none)
            DB::table('petty_cash_ledger_entries')->delete();
            $ledger = new LedgerService();
            $ledger->rebuildFromLedger();

            // Log activity
            $this->logActivity('cleared', null, null, "All petty cash data cleared. Deleted {$topUpsCount} top-ups, {$disbursementsCount} disbursements, and {$allocationCount} allocations.");

            DB::commit();

            return [
                'success' => true,
                'allocations_deleted' => $allocationCount,
                'disbursements_deleted' => $disbursementsCount,
                'top_ups_deleted' => $topUpsCount,
                'message' => 'All petty cash data has been cleared and balance reset to zero via ledger rebuild.'
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

            $ledger = new LedgerService();
            $balance = $ledger->rebuildFromLedger();
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
                    $errors['amount'] = [
                        'Selected top-up has insufficient balance. Available: KES ' .
                        number_format((float) $availableBalance, 2) .
                        ', Required (Amount + Cost): KES ' .
                        number_format((float) $requestedTotal, 2)
                    ];
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
        DB::beginTransaction();

        try {
            $result = $this->repository->archiveDisbursement($disbursement, Auth::id());

            if ($result) {
                $this->logActivity('archived', 'disbursement', $disbursement->id, "Disbursement archived");
            }

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to archive disbursement: ' . $e->getMessage());
        }
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
        DB::beginTransaction();

        try {
            $result = $this->repository->archiveTopUp($topUp, Auth::id());

            if ($result) {
                $this->logActivity('archived', 'top_up', $topUp->id, "Top-up archived");
            }

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to archive top-up: ' . $e->getMessage());
        }
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
