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

        return $query->paginate($perPage);
    }

    /**
     * Get all disbursements with optional filtering and pagination.
     */
    public function getDisbursements(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PettyCashDisbursement::with('topUp', 'creator', 'voidedBy')
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
                $q->with('creator', 'voidedBy');
                
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
        try {
            // Simplified version - return all top-ups for now
            // TODO: Implement proper available balance calculation
            return PettyCashTopUp::with('creator')
                ->latest()
                ->get()
                ->map(function ($topUp) {
                    // Add mock available balance data
                    $topUp->remaining_balance = [
                        'raw' => $topUp->amount ?? 0,
                        'formatted' => 'KES ' . number_format($topUp->amount ?? 0, 2)
                    ];
                    return $topUp;
                });
        } catch (\Exception $e) {
            // Log the error and return empty collection
            \Log::error('Error fetching available top-ups: ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get transaction summary statistics.
     */
    public function getTransactionSummary(array $filters = []): array
    {
        $topUpQuery = PettyCashTopUp::query();
        $disbursementQuery = PettyCashDisbursement::active();

        // Apply date filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $topUpQuery->byDateRange($filters['start_date'], $filters['end_date']);
            $disbursementQuery->byDateRange($filters['start_date'], $filters['end_date']);
        }

        // Apply other filters
        if (!empty($filters['classification'])) {
            $disbursementQuery->byClassification($filters['classification']);
        }

        if (!empty($filters['project_name'])) {
            $disbursementQuery->byProject($filters['project_name']);
        }

        $totalTopUps = $topUpQuery->sum('amount');
        $totalDisbursements = $disbursementQuery->sum('amount');
        $topUpCount = $topUpQuery->count();
        $disbursementCount = $disbursementQuery->count();

        return [
            'total_top_ups' => (float) $totalTopUps,
            'total_disbursements' => (float) $totalDisbursements,
            'net_balance' => (float) ($totalTopUps - $totalDisbursements),
            'top_up_count' => $topUpCount,
            'disbursement_count' => $disbursementCount,
            'average_top_up' => $topUpCount > 0 ? (float) ($totalTopUps / $topUpCount) : 0,
            'average_disbursement' => $disbursementCount > 0 ? (float) ($totalDisbursements / $disbursementCount) : 0,
        ];
    }

    /**
     * Get spending by classification.
     */
    public function getSpendingByClassification(array $filters = []): Collection
    {
        $query = PettyCashDisbursement::active()
            ->select('classification', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
            ->groupBy('classification');

        // Apply date filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
        }

        return $query->get();
    }

    /**
     * Get spending by payment method.
     */
    public function getSpendingByPaymentMethod(array $filters = []): Collection
    {
        $query = PettyCashDisbursement::active()
            ->select('payment_method', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
            ->groupBy('payment_method');

        // Apply date filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->byDateRange($filters['start_date'], $filters['end_date']);
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
}