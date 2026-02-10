<?php

namespace App\Modules\Finance\PettyCash\Repositories;

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PettyCashRepository
{
    /**
     * Get all top-ups with optional filtering and pagination.
     */
    public function getTopUps(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PettyCashTopUp::with('creator', 'disbursements')
            ->latest();

        // Apply filters
        if (!empty($filters['payment_method'])) {
            $query->byPaymentMethod($filters['payment_method']);
        }

        if (!empty($filters['creator_id'])) {
            $query->byCreator($filters['creator_id']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('description', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('transaction_code', 'like', '%' . $filters['search'] . '%');
            });
        }

        // Apply archiving filters
        $showArchived = filter_var($filters['show_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query->where('is_archived', $showArchived);

        return $query->paginate($perPage);
    }

    /**
     * Get all disbursements with optional filtering and pagination.
     */
    public function getDisbursements(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PettyCashDisbursement::with('topUp', 'creator', 'voidedBy', 'requisition')
            ->latest();

        // Apply filters
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->active();
            } elseif ($filters['status'] === 'voided') {
                $query->voided();
            }
        }

        if (!empty($filters['classification'])) {
            $query->byClassification($filters['classification']);
        }

        if (!empty($filters['payment_method'])) {
            $query->byPaymentMethod($filters['payment_method']);
        }

        if (!empty($filters['project_name'])) {
            $query->byProject($filters['project_name']);
        }

        if (!empty($filters['creator_id'])) {
            $query->byCreator($filters['creator_id']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Apply archiving filters
        $showArchived = filter_var($filters['show_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query->where('is_archived', $showArchived);

        return $query->paginate($perPage);
    }

    /**
     * Get hierarchical transaction data (top-ups with their disbursements).
     */
    public function getHierarchicalTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PettyCashTopUp::with([
            'creator',
            'disbursements' => function ($q) use ($filters) {
                $q->with('creator', 'voidedBy', 'requisition');
                
                // Apply disbursement filters
                if (!empty($filters['disbursement_status'])) {
                    if ($filters['disbursement_status'] === 'active') {
                        $q->active();
                    } elseif ($filters['disbursement_status'] === 'voided') {
                        $q->voided();
                    }
                }
                
                if (!empty($filters['classification'])) {
                    $q->byClassification($filters['classification']);
                }
                
                $q->latest();
            }
        ])->latest();

        // Apply top-up filters
        if (!empty($filters['payment_method'])) {
            $query->byPaymentMethod($filters['payment_method']);
        }

        if (!empty($filters['creator_id'])) {
            $query->byCreator($filters['creator_id']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('description', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('transaction_code', 'like', '%' . $filters['search'] . '%')
                  ->orWhereHas('disbursements', function ($subQ) use ($filters) {
                      $subQ->search($filters['search']);
                  });
            });
        }

        // Apply archiving filters
        $showArchived = filter_var($filters['show_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query->where('is_archived', $showArchived);

        return $query->paginate($perPage);
    }

    /**
     * Find a specific top-up by ID.
     */
    public function findTopUp(int $id): ?PettyCashTopUp
    {
        return PettyCashTopUp::with('creator', 'disbursements.creator', 'disbursements.voidedBy')
            ->find($id);
    }

    /**
     * Find a specific disbursement by ID.
     */
    public function findDisbursement(int $id): ?PettyCashDisbursement
    {
        return PettyCashDisbursement::with('topUp', 'creator', 'voidedBy')
            ->find($id);
    }

    /**
     * Create a new top-up.
     */
    public function createTopUp(array $data): PettyCashTopUp
    {
        return PettyCashTopUp::create($data);
    }

    /**
     * Create a new disbursement.
     */
    public function createDisbursement(array $data): PettyCashDisbursement
    {
        return PettyCashDisbursement::create($data);
    }

    /**
     * Update a disbursement.
     */
    public function updateDisbursement(PettyCashDisbursement $disbursement, array $data): bool
    {
        return $disbursement->update($data);
    }

    /**
     * Void a disbursement.
     */
    public function voidDisbursement(PettyCashDisbursement $disbursement, int $voidedBy, string $reason): bool
    {
        return $disbursement->void($voidedBy, $reason);
    }

    /**
     * Get current balance.
     */
    public function getCurrentBalance(): PettyCashBalance
    {
        return PettyCashBalance::current();
    }

    /**
     * Get top-ups with available balance.
     */
    public function getTopUpsWithAvailableBalance(): Collection
    {
        return PettyCashTopUp::with(['creator', 'disbursements' => function($q) {
                $q->where('status', 'active');
            }])
            ->notArchived()
            ->get()
            ->filter(function ($topUp) {
                return $topUp->remaining_balance > 0;
            })
            ->values();
    }

    /**
     * Get transaction summary statistics.
     */
    public function getTransactionSummary(array $filters = []): array
    {
        $topUpQuery = PettyCashTopUp::notArchived();
        $disbursementQuery = PettyCashDisbursement::active()->notArchived();

        // Apply date filters - Aligning to created_at for consistency if date_disbursed/date_topped_up differs
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $topUpQuery->whereBetween('date_topped_up', [$filters['start_date'], $filters['end_date']]);
            $disbursementQuery->whereBetween('date_disbursed', [$filters['start_date'], $filters['end_date']]);
        }

        // Apply other filters
        if (!empty($filters['classification'])) {
            $disbursementQuery->byClassification($filters['classification']);
        }

        if (!empty($filters['project_name'])) {
            $disbursementQuery->byProject($filters['project_name']);
        }

        $totalTopUps = (float) $topUpQuery->sum('amount');
        $totalDisbursements = (float) $disbursementQuery->sum('amount');
        $topUpCount = $topUpQuery->count();
        $disbursementCount = $disbursementQuery->count();

        return [
            'total_top_ups' => $totalTopUps,
            'total_disbursements' => $totalDisbursements,
            'net_balance' => $totalTopUps - $totalDisbursements,
            'top_up_count' => $topUpCount,
            'disbursement_count' => $disbursementCount,
            'average_top_up' => $topUpCount > 0 ? $totalTopUps / $topUpCount : 0,
            'average_disbursement' => $disbursementCount > 0 ? $totalDisbursements / $disbursementCount : 0,
        ];
    }

    /**
     * Get spending by classification.
     */
    public function getSpendingByClassification(array $filters = []): Collection
    {
        $query = PettyCashDisbursement::active()
            ->notArchived()
            ->select('classification', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
            ->groupBy('classification');

        // Apply date filters - Aligning to date_disbursed
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date_disbursed', [$filters['start_date'], $filters['end_date']]);
        }

        return $query->get();
    }

    /**
     * Get spending by payment method.
     */
    public function getSpendingByPaymentMethod(array $filters = []): Collection
    {
        $query = PettyCashDisbursement::active()
            ->notArchived()
            ->select('payment_method', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
            ->groupBy('payment_method');

        // Apply date filters - Aligning to date_disbursed
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date_disbursed', [$filters['start_date'], $filters['end_date']]);
        }

        return $query->get();
    }

    /**
     * Get recent transactions (both top-ups and disbursements).
     */
    public function getRecentTransactions(int $limit = 10): array
    {
        $topUps = PettyCashTopUp::with('creator')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($topUp) {
                return [
                    'id' => $topUp->id,
                    'type' => 'top_up',
                    'amount' => $topUp->amount,
                    'description' => $topUp->description ?: 'Top-up via ' . $topUp->payment_method,
                    'created_at' => $topUp->created_at,
                    'creator' => $topUp->creator->name,
                ];
            });

        $disbursements = PettyCashDisbursement::with('creator')
            ->active()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($disbursement) {
                return [
                    'id' => $disbursement->id,
                    'type' => 'disbursement',
                    'amount' => $disbursement->amount,
                    'description' => $disbursement->description,
                    'receiver' => $disbursement->receiver,
                    'created_at' => $disbursement->created_at,
                    'creator' => $disbursement->creator->name,
                ];
            });

        // Merge and sort by created_at
        $allTransactions = $topUps->concat($disbursements)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();

        return $allTransactions->toArray();
    }

    /**
     * Search across all transaction fields.
     */
    public function searchTransactions(string $search, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // Search in disbursements (primary search)
        $disbursements = $this->getDisbursements(array_merge($filters, ['search' => $search]), $perPage);

        return $disbursements;
    }

    /**
     * Get unified flat transaction list (both top-ups and disbursements).
     */
    public function getFlatTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $page = $filters['page'] ?? 1;

        // Build Top-ups subquery
        $topUps = DB::table('petty_cash_top_ups')
            ->select(
                'id',
                DB::raw("'top_up' as type"),
                'amount',
                'previous_balance',
                'date_topped_up',
                'description',
                DB::raw('NULL as receiver'),
                DB::raw('NULL as account'),
                DB::raw('NULL as project_name'),
                'payment_method',
                DB::raw('NULL as classification'),
                DB::raw('NULL as job_number'),
                DB::raw("'active' as status"),
                'transaction_code',
                'created_at',
                'is_archived',
                'id as parent_id', // Group by its own ID
                DB::raw('1 as type_priority'), // Top-up comes first in its group
                // Union compatibility fields
                DB::raw('NULL as requisition_status'),
                DB::raw('NULL as received_at'),
                DB::raw('NULL as signature'),
                DB::raw('NULL as requisition_id')
            );

        // Build Disbursements subquery
        $disbursements = DB::table('petty_cash_disbursements')
            ->leftJoin('petty_cash_requisitions', 'petty_cash_disbursements.requisition_id', '=', 'petty_cash_requisitions.id')
            ->select(
                'petty_cash_disbursements.id',
                DB::raw("'disbursement' as type"),
                'petty_cash_disbursements.amount',
                DB::raw('NULL as previous_balance'),
                DB::raw('NULL as date_topped_up'),
                'petty_cash_disbursements.description',
                'petty_cash_disbursements.receiver',
                'petty_cash_disbursements.account',
                'petty_cash_disbursements.project_name',
                'petty_cash_disbursements.payment_method',
                'petty_cash_disbursements.classification',
                'petty_cash_disbursements.job_number',
                'petty_cash_disbursements.status',
                'petty_cash_disbursements.transaction_code',
                'petty_cash_disbursements.created_at',
                'petty_cash_disbursements.is_archived',
                'petty_cash_disbursements.top_up_id as parent_id', // Group by parent top-up ID
                DB::raw('2 as type_priority'), // Disbursements follow the top-up
                // Requisition details
                'petty_cash_requisitions.status as requisition_status',
                'petty_cash_requisitions.received_at as received_at',
                'petty_cash_requisitions.digital_signature as signature',
                'petty_cash_disbursements.requisition_id'
            );

        // Apply shared filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $topUps->whereBetween('date_topped_up', [$filters['start_date'], $filters['end_date']]);
            $disbursements->whereBetween('date_disbursed', [$filters['start_date'], $filters['end_date']]);
        }

        if (!empty($filters['payment_method'])) {
            $topUps->where('payment_method', $filters['payment_method']);
            $disbursements->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $topUps->where(function($q) use ($search) {
                $q->where('description', 'like', $search)
                  ->orWhere('transaction_code', 'like', $search);
            });
            $disbursements->where(function($q) use ($search) {
                $q->where('description', 'like', $search)
                  ->orWhere('receiver', 'like', $search)
                  ->orWhere('account', 'like', $search)
                  ->orWhere('project_name', 'like', $search)
                  ->orWhere('transaction_code', 'like', $search);
            });
        }

        if (!empty($filters['project_name'])) {
            $disbursements->where('project_name', 'like', '%' . $filters['project_name'] . '%');
            // Top-ups don't have project names
            $topUps->whereRaw('1 = 0');
        }

        // Apply archiving filters
        $showArchived = filter_var($filters['show_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $topUps->where('is_archived', $showArchived);
        $disbursements->where('is_archived', $showArchived);

        // Apply disbursement-specific filters
        if (!empty($filters['status'])) {
            $disbursements->where('status', $filters['status']);
            if ($filters['status'] !== 'active') {
                $topUps->whereRaw('1 = 0');
            }
        }

        if (!empty($filters['classification'])) {
            $disbursements->where('classification', $filters['classification']);
            $topUps->whereRaw('1 = 0');
        }

        // Combine using Union All
        $query = $disbursements->unionAll($topUps);

        // Sort by created_at DESC to show newest transactions first
        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Archive a specific disbursement.
     */
    public function archiveDisbursement(PettyCashDisbursement $disbursement, int $userId): bool
    {
        return $disbursement->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $userId
        ]);
    }

    /**
     * Bulk archive disbursements.
     */
    public function bulkArchiveDisbursements(array $ids, int $userId): int
    {
        return PettyCashDisbursement::whereIn('id', $ids)->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $userId
        ]);
    }

    /**
     * Archive a specific top-up.
     */
    public function archiveTopUp(PettyCashTopUp $topUp, int $userId): bool
    {
        return $topUp->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $userId
        ]);
    }

    /**
     * Archive a top-up and all its disbursements at once.
     */
    public function archiveGroup(int $topUpId, int $userId): bool
    {
        return DB::transaction(function () use ($topUpId, $userId) {
            // Archive the Top-up
            PettyCashTopUp::where('id', $topUpId)->update([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $userId
            ]);

            // Archive all related disbursements
            PettyCashDisbursement::where('top_up_id', $topUpId)->update([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $userId
            ]);

            return true;
        });
    }

    /**
     * Bulk archive multiple groups at once.
     */
    public function bulkArchiveGroups(array $topUpIds, int $userId): int
    {
        return DB::transaction(function () use ($topUpIds, $userId) {
            // Archive all Top-ups
            PettyCashTopUp::whereIn('id', $topUpIds)->update([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $userId
            ]);

            // Archive all related disbursements
            PettyCashDisbursement::whereIn('top_up_id', $topUpIds)->update([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $userId
            ]);

            return count($topUpIds);
        });
    }
    /**
     * Get voucher data for a specific date range (non-paginated).
     */
    public function getVoucherData(array $filters = []): array
    {
        $startDate = $filters['start_date'] ?? now()->startOfDay()->toDateTimeString();
        $endDate = $filters['end_date'] ?? now()->endOfDay()->toDateTimeString();

        $openingTopUps = PettyCashTopUp::notArchived()
            ->where('date_topped_up', '<', $startDate)
            ->sum('amount');
        
        // Opening Disbursements (only active ones)
        $openingDisbursements = PettyCashDisbursement::active()->notArchived()
            ->where('date_disbursed', '<', $startDate)
            ->sum('amount');
        
        $openingBalance = (float)$openingTopUps - (float)$openingDisbursements;

        // 2. Fetch Disbursements in range
        $disbursementQuery = PettyCashDisbursement::with(['topUp', 'creator'])
            ->active()
            ->notArchived()
            ->whereBetween('date_disbursed', [$startDate, $endDate])
            ->orderBy('date_disbursed', 'asc');

        if (!empty($filters['classification'])) {
            $disbursementQuery->byClassification($filters['classification']);
        }
        if (!empty($filters['project_name'])) {
            $disbursementQuery->byProject($filters['project_name']);
        }

        $disbursements = $disbursementQuery->get();

        // 3. Fetch Top-ups in range
        $topUps = PettyCashTopUp::with('creator')
            ->notArchived()
            ->whereBetween('date_topped_up', [$startDate, $endDate])
            ->orderBy('date_topped_up', 'asc')
            ->get();

        $totalIn = (float)$topUps->sum('amount');
        $totalOut = (float)$disbursements->sum('amount');

        return [
            'opening_balance' => $openingBalance,
            'top_ups' => $topUps,
            'disbursements' => $disbursements,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'closing_balance' => $openingBalance + $totalIn - $totalOut,
            'filters' => $filters,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    }
}