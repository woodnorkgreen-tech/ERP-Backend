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
            ->orderBy('date_topped_up', 'desc')
            ->orderBy('created_at', 'desc');

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
            ->orderBy('date_disbursed', 'desc')
            ->orderBy('created_at', 'desc');

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
     * Update a top-up.
     */
    public function updateTopUp(PettyCashTopUp $topUp, array $data): bool
    {
        return $topUp->update($data);
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
        $totalDisbursements = (float) $disbursementQuery->sum(DB::raw('amount + COALESCE(transaction_cost, 0)'));
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
            ->select('classification', DB::raw('SUM(amount + COALESCE(transaction_cost, 0)) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
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
            ->select('payment_method', DB::raw('SUM(amount + COALESCE(transaction_cost, 0)) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
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
        $query = DB::table('petty_cash_ledger_entries')
            ->orderBy('posted_at', 'desc')
            ->orderBy('id', 'desc');

        // Apply filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('posted_at', [$filters['start_date'], $filters['end_date']]);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', $search)
                  ->orWhere('metadata->description', 'like', $search)
                  ->orWhere('metadata->receiver', 'like', $search)
                  ->orWhere('metadata->transaction_code', 'like', $search);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform collection to match old flat transaction format
        $paginator->getCollection()->transform(function ($item) {
            $meta = json_decode($item->metadata, true) ?: [];
            
            // Reconstruct flat transaction columns
            return (object)[
                'id' => $item->id,
                'type' => $item->type === 'credit' ? 'top_up' : 'disbursement',
                'amount' => $item->type === 'credit' ? (float)$item->amount : (float)($meta['amount'] ?? ((float)$item->amount - (float)($meta['transaction_cost'] ?? 0))),
                'previous_balance' => (float)$item->balance_snapshot - (float)($item->type === 'credit' ? $item->amount : -($item->amount)),
                'transaction_date' => $item->posted_at,
                'description' => $meta['description'] ?? '',
                'receiver' => $meta['receiver'] ?? null,
                'account' => $meta['account'] ?? null,
                'project_name' => $meta['project_name'] ?? null,
                'venue' => $meta['venue'] ?? null,
                'payment_method' => $meta['payment_method'] ?? 'cash',
                'classification' => $meta['classification'] ?? null,
                'job_number' => $meta['job_number'] ?? null,
                'status' => $meta['status'] ?? 'active',
                'transaction_code' => $meta['transaction_code'] ?? null,
                'created_at' => $item->created_at,
                'is_archived' => false,
                'requisition_status' => $meta['requisition_status'] ?? null,
                'received_at' => $meta['received_at'] ?? null,
                'signature' => $meta['signature'] ?? null,
                'requisition_id' => $meta['requisition_id'] ?? null,
                'transaction_cost' => $meta['transaction_cost'] ?? null,
                'reference_number' => $item->reference_number
            ];
        });

        return $paginator;
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
            ->sum(DB::raw('amount + COALESCE(transaction_cost, 0)'));
        
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
        $totalOut = (float)$disbursements->sum(function($d) { return (float)$d->amount + (float)($d->transaction_cost ?? 0); });

        // 4. Group by classification for advanced reporting
        $classificationBreakdown = $disbursements->groupBy('classification')
            ->map(function ($items) {
                return [
                    'count' => $items->count(),
                    'total' => $items->sum(function($i) { return (float)$i->amount + (float)($i->transaction_cost ?? 0); })
                ];
            });

        return [
            'opening_balance' => $openingBalance,
            'top_ups' => $topUps,
            'disbursements' => $disbursements,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'classification_breakdown' => $classificationBreakdown,
            'closing_balance' => $openingBalance + $totalIn - $totalOut,
            'filters' => $filters,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    }

    /**
     * Get summary of project budgets vs actual petty cash spend with pagination and overall stats.
     */
    public function getProjectBudgetsSummary(array $filters = [], int $perPage = 15): array
    {
        // 1. Get approved project enquiries with their budget tasks (PAGINATED)
        $query = \App\Models\ProjectEnquiry::where("quote_approved", true)
            ->whereNotNull("job_number")
            ->with(["enquiryTasks" => function($q) {
                $q->where("type", "budget")
                  ->with("budgetData");
            }])
            ->orderBy('created_at', 'desc');

        $paginator = $query->paginate($perPage);

        // 2. Aggregate actual spent from petty cash disbursements by job_number and category
        $actualSpentRaw = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::active()
            ->notArchived()
            ->whereNotNull("job_number")
            ->select("job_number", "budget_category", \Illuminate\Support\Facades\DB::raw("SUM(amount + COALESCE(transaction_cost, 0)) as total_spent"))
            ->groupBy("job_number", "budget_category")
            ->get();

        $actualSpent = [];
        $totalActualSpentOverall = 0;
        foreach ($actualSpentRaw as $row) {
            if (!isset($actualSpent[$row->job_number])) {
                $actualSpent[$row->job_number] = [
                    'total' => 0,
                    'categories' => ['materials' => 0, 'labour' => 0, 'logistics' => 0, 'expenses' => 0]
                ];
            }
            $actualSpent[$row->job_number]['total'] += (float) $row->total_spent;
            $totalActualSpentOverall += (float) $row->total_spent;
            $cat = $row->budget_category ?: 'expenses';
            if (isset($actualSpent[$row->job_number]['categories'][$cat])) {
                $actualSpent[$row->job_number]['categories'][$cat] += (float) $row->total_spent;
            } else {
                $actualSpent[$row->job_number]['categories']['expenses'] += (float) $row->total_spent;
            }
        }

        // 3. Calculate Overall Budget Total (for stats)
        // This is done across all enquiries, not just the current page
        $totalBudgetOverall = 0;
        $allApprovedEnquiries = \App\Models\ProjectEnquiry::where("quote_approved", true)
            ->whereNotNull("job_number")
            ->with(["enquiryTasks" => function($q) {
                $q->where("type", "budget")
                  ->with("budgetData");
            }])
            ->get();

        foreach ($allApprovedEnquiries as $enquiry) {
            $budgetTask = $enquiry->enquiryTasks->filter(fn($t) => $t->type === "budget")->first();
            if ($budgetTask && $budgetTask->budgetData) {
                $totalBudgetOverall += (float) ($budgetTask->budgetData->budget_summary['grandTotal'] ?? 0);
            }
        }

        // 4. Transform data using the paginator's collection
        $paginator->getCollection()->transform(function ($enquiry) use ($actualSpent) {
            $budgetTask = $enquiry->enquiryTasks->filter(function($t) {
                return $t->type === "budget";
            })->first();
            
            $budgetData = $budgetTask ? $budgetTask->budgetData : null;
            $summary = $budgetData ? ($budgetData->budget_summary ?? []) : [];
            
            $projectSpent = $actualSpent[$enquiry->job_number] ?? [
                'total' => 0,
                'categories' => ['materials' => 0, 'labour' => 0, 'logistics' => 0, 'expenses' => 0]
            ];
            
            return [
                "id" => $enquiry->id,
                "job_number" => $enquiry->job_number,
                "project_id" => $enquiry->project_id,
                "title" => $enquiry->title,
                "budget_summary" => $summary,
                "actual_spent" => (float) $projectSpent['total'],
                "actual_spent_breakdown" => $projectSpent['categories'],
                "totals" => [
                    "materials" => (float) ($summary["materialsTotal"] ?? 0),
                    "labour" => (float) ($summary["labourTotal"] ?? 0),
                    "logistics" => (float) ($summary["logisticsTotal"] ?? 0),
                    "expenses" => (float) ($summary["expensesTotal"] ?? 0),
                    "grand_total" => (float) ($summary["grandTotal"] ?? 0),
                ]
            ];
        });

        return [
            'paginator' => $paginator,
            'stats' => [
                'total_budget' => $totalBudgetOverall,
                'total_spent' => $totalActualSpentOverall,
                'avg_utilization' => $totalBudgetOverall > 0 ? round(($totalActualSpentOverall / $totalBudgetOverall) * 100) : 0
            ]
        ];
    }
}