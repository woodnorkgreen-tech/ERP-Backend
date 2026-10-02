<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Support\LedgerCoverage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ledger-backed balance-sheet snapshot as of a date.
 *
 * This is intentionally a real posted-balance view of the accounts already in
 * the ledger, not a statutory statement: without opening balances, equity, and
 * depreciation the summary is a truthful snapshot of what the books contain
 * today, with the coverage caveat attached so it cannot be mistaken for a final
 * filed balance sheet.
 */
class BalanceSheetService
{
    /** @return array<string, mixed> */
    public function summary(string $asOf): array
    {
        $rows = $this->signedRows($asOf);

        $assets = $rows->where('category', 'asset');
        $liabilities = $rows->where('category', 'liability');
        $equity = $rows->where('category', 'equity');
        $unclassified = $rows->whereNotIn('category', ['asset', 'liability', 'equity']);

        $assetsTotal = $this->total($assets);
        $liabilitiesTotal = $this->total($liabilities);
        $equityTotal = $this->total($equity);
        $difference = bcsub($assetsTotal, bcadd($liabilitiesTotal, $equityTotal, 2), 2);
        $coverage = LedgerCoverage::describe();
        return [
            'as_of' => $asOf,
            'sections' => [
                'asset' => $this->present($assets),
                'liability' => $this->present($liabilities),
                'equity' => $this->present($equity),
                'unclassified' => array_map(fn (array $row) => $row + [
                    'review_status' => 'REQUIRES_ACCOUNTANT_REVIEW',
                    'review_reason' => 'The posted account classification is not a supported Balance Sheet category.',
                ], $this->present($unclassified)),
            ],
            'totals' => [
                'assets' => $assetsTotal,
                'liabilities' => $liabilitiesTotal,
                'equity' => $equityTotal,
                'total_assets' => $assetsTotal,
                'total_liabilities' => $liabilitiesTotal,
                'total_equity' => $equityTotal,
                'difference' => $difference,
                'balanced' => bccomp($difference, '0.00', 2) === 0,
            ],
            'readiness' => [
                'opening_balance_status' => 'NOT_CONFIGURED',
                'historical_data_complete' => false,
                'statement_readiness' => 'HISTORICAL_DATA_INCOMPLETE',
                'equity_complete' => false,
                'reason' => 'Opening balances and historical adjustments are not approved/configured; retained earnings are not generated.',
            ],
            'coverage' => $coverage,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function signedRows(string $asOf): Collection
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where(function ($query) {
                $query->whereIn('chart_of_accounts.category', ['asset', 'liability', 'equity'])
                    ->orWhereNotIn('chart_of_accounts.category', ['revenue', 'expense'])
                    ->orWhereNull('chart_of_accounts.category')
                    ->orWhere('chart_of_accounts.account_type', 'balance_sheet');
            })
            ->whereDate('journal_entries.posting_date', '<=', $asOf)
            ->groupBy(
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                'chart_of_accounts.account_type',
                'chart_of_accounts.normal_balance',
            )
            ->orderBy('chart_of_accounts.code')
            ->get([
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                'chart_of_accounts.account_type',
                'chart_of_accounts.normal_balance',
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'debit' THEN journal_lines.base_amount ELSE 0 END) as debit"),
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'credit' THEN journal_lines.base_amount ELSE 0 END) as credit"),
            ]);

        return $rows->map(function ($row) {
            $debit = number_format((float) $row->debit, 2, '.', '');
            $credit = number_format((float) $row->credit, 2, '.', '');

            // Statement signs follow the section, so credit-normal contra-assets
            // reduce assets rather than increasing their section total.
            $normalBalance = match ($row->category) {
                'asset' => 'debit',
                'liability', 'equity' => 'credit',
                default => $row->normal_balance ?? 'debit',
            };

            return [
                'account_id' => (int) $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'category' => in_array($row->category, ['asset', 'liability', 'equity'], true)
                    ? $row->category
                    : 'unclassified',
                'account_type' => $row->account_type,
                'account_code' => $row->code,
                'account_name' => $row->name,
                'amount' => $normalBalance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2),
                'balance' => $normalBalance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2),
            ];
        });
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function total(Collection $rows): string
    {
        return $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0.00');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        return $rows->values()->all();
    }
}
