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
        $query = PettyCashDisbursement::with('topUp', 'creator', 'voidedBy', 'requisition', 'project.enquiry', 'enquiry', 'plannedCostLine')
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
                $q->with('creator', 'voidedBy', 'requisition', 'project.enquiry', 'enquiry');
                
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
        $directDisbursements = DB::table('petty_cash_disbursements as d')
            ->select('d.top_up_id', DB::raw('SUM(d.amount + COALESCE(d.transaction_cost, 0)) as total'))
            ->where('d.status', 'active')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('petty_cash_disbursement_allocations as a')
                    ->whereRaw('a.disbursement_id = d.id');
            })
            ->groupBy('d.top_up_id');

        $allocations = DB::table('petty_cash_disbursement_allocations as a')
            ->join('petty_cash_disbursements as d', 'd.id', '=', 'a.disbursement_id')
            ->select('a.top_up_id', DB::raw('SUM(a.amount + COALESCE(a.transaction_cost, 0)) as total'))
            ->where('d.status', 'active')
            ->groupBy('a.top_up_id');

        return PettyCashTopUp::with('creator')
            ->leftJoinSub($directDisbursements, 'direct_disbursements', function ($join) {
                $join->on('petty_cash_top_ups.id', '=', 'direct_disbursements.top_up_id');
            })
            ->leftJoinSub($allocations, 'allocations', function ($join) {
                $join->on('petty_cash_top_ups.id', '=', 'allocations.top_up_id');
            })
            ->select('petty_cash_top_ups.*')
            ->selectRaw(
                '(petty_cash_top_ups.amount - COALESCE(direct_disbursements.total, 0) - COALESCE(allocations.total, 0)) as calculated_remaining_balance'
            )
            ->notArchived()
            ->whereRaw('(petty_cash_top_ups.amount - COALESCE(direct_disbursements.total, 0) - COALESCE(allocations.total, 0)) > 0')
            ->orderBy('date_topped_up')
            ->orderBy('id')
            ->get()
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

        // Everything below reads out of the metadata JSON, which is where the
        // ledger keeps the disbursement's own fields. These four filters were
        // collected by the controller and then silently dropped here, so the
        // panel returned unfiltered rows while reporting itself as active.

        // A top-up entry carries no status key; it is active by construction.
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'voided') {
                $query->where('metadata->status', 'voided');
            } else {
                // Deliberately not whereNull('metadata->status'): Laravel expands
                // that to json_type(...) = 'NULL', and the literal picks up the
                // connection collation while json_type() returns the server's —
                // an illegal mix of collations on these utf8mb3 tables.
                $query->where(function ($q) {
                    $q->whereRaw("JSON_EXTRACT(metadata, '$.status') IS NULL")
                      ->orWhere('metadata->status', 'active');
                });
            }
        }

        if (!empty($filters['classification'])) {
            $query->where('metadata->classification', $filters['classification']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('metadata->payment_method', $filters['payment_method']);
        }

        if (!empty($filters['creator_id'])) {
            $query->where('metadata->created_by', (int) $filters['creator_id']);
        }

        // Archive state lives on the source rows, never on the ledger — a posted
        // entry is immutable and carries no archive flag. reference_number is the
        // only link back, and it embeds the zero-padded source id at a fixed
        // offset, so the join is recoverable without touching the frozen schema.
        // It is applied in SQL rather than after the fact because pagination has
        // to count the filtered set, not the whole ledger.
        $archived = $this->archivedSourceIds();
        $hasArchived = $archived['disbursement'] || $archived['top_up'];

        if (!empty($filters['show_archived'])) {
            $hasArchived
                ? $query->where(fn ($q) => $this->matchArchivedSources($q, $archived))
                : $query->whereRaw('1 = 0');
        } elseif ($hasArchived) {
            $query->whereNot(fn ($q) => $this->matchArchivedSources($q, $archived));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform collection to match old flat transaction format
        $paginator->getCollection()->transform(function ($item) use ($archived) {
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
                // The running balance the ledger already stores. Additive field:
                // it is what makes the transaction list read as a cashbook rather
                // than an undated pile of payments.
                'balance_after' => (float) $item->balance_snapshot,
                'is_archived' => $this->isArchivedEntry($item->reference_number, $archived),
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
     * Ids of the source records an operator has archived, per source type.
     */
    private function archivedSourceIds(): array
    {
        return [
            'disbursement' => PettyCashDisbursement::where('is_archived', true)->pluck('id')->all(),
            'top_up' => PettyCashTopUp::where('is_archived', true)->pluck('id')->all(),
        ];
    }

    /**
     * Constrain a ledger query to entries whose source record is archived.
     *
     * Every reference the ledger writes puts the padded source id at a known
     * offset — PCR-000123 and its PCR-000123-VOID reversal, TOP-000045 and its
     * TOP-000045-ADJ-… adjustment, REV-TOP-000045 — so one substring per prefix
     * covers the derivatives too. Archiving a record hides its reversals with it,
     * which is the behaviour an operator expects.
     */
    private function matchArchivedSources($query, array $archived): void
    {
        $query->whereRaw('1 = 0');

        if ($archived['disbursement']) {
            $query->orWhere(fn ($q) => $q
                ->where('reference_number', 'like', 'PCR-%')
                ->whereIn(DB::raw('CAST(SUBSTRING(reference_number, 5, 6) AS UNSIGNED)'), $archived['disbursement']));
        }

        if ($archived['top_up']) {
            $query->orWhere(fn ($q) => $q
                ->where('reference_number', 'like', 'TOP-%')
                ->whereIn(DB::raw('CAST(SUBSTRING(reference_number, 5, 6) AS UNSIGNED)'), $archived['top_up']));
            $query->orWhere(fn ($q) => $q
                ->where('reference_number', 'like', 'REV-TOP-%')
                ->whereIn(DB::raw('CAST(SUBSTRING(reference_number, 9, 6) AS UNSIGNED)'), $archived['top_up']));
        }
    }

    /**
     * The row-level answer to the same question matchArchivedSources() asks in SQL.
     */
    private function isArchivedEntry(?string $reference, array $archived): bool
    {
        if (! $reference) {
            return false;
        }

        if (str_starts_with($reference, 'REV-TOP-')) {
            return in_array((int) substr($reference, 8, 6), $archived['top_up'], true);
        }
        if (str_starts_with($reference, 'TOP-')) {
            return in_array((int) substr($reference, 4, 6), $archived['top_up'], true);
        }
        if (str_starts_with($reference, 'PCR-')) {
            return in_array((int) substr($reference, 4, 6), $archived['disbursement'], true);
        }

        return false;
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

}
