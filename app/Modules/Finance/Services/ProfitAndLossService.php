<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Support\LedgerCoverage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revenue minus Cost of Sales minus overheads, for a period.
 *
 * Same three-table join and status/date filters as
 * JournalEntryController::trialBalance() and LedgerExportService's export —
 * this is the same ledger read a third way, not a new source of truth. Money
 * throughout is bcmath (2 decimal places), never float, matching that
 * discipline.
 *
 * `category` (asset|liability|equity|revenue|expense) decides what is a P&L
 * account at all; `account_type` (direct_cost|overhead|opex|...) decides
 * which SECTION of the P&L an expense falls into. The two are deliberately
 * used at different points: `revenue`/`expense` totals, and therefore
 * `net_profit`, are summed from `category` alone, over every matching row —
 * so `net_profit` always equals revenue minus expense exactly as the trial
 * balance would show for the same period, however completely `account_type`
 * happens to be populated on WNG's live chart. Only the section BREAKDOWN
 * depends on `account_type`; a row with a NULL or unrecognised value there
 * lands in an explicit `unclassified` section rather than being dropped — a
 * less useful report is acceptable, a wrong total is not.
 *
 * Management accounts, not yet the statutory Stage 7 report: depreciation
 * and opening balances/equity are still missing (see LedgerCoverage), so
 * this is deliberately not exposed as a Balance Sheet's income side.
 */
class ProfitAndLossService
{
    private const EXPENSE_SECTIONS = ['direct_cost', 'overhead', 'opex'];

    /** @return array<string, mixed> */
    public function summary(string $from, string $to): array
    {
        $rows = $this->signedRows($from, $to);

        $revenue = $rows->where('category', 'revenue');
        $expense = $rows->where('category', 'expense');

        $directCost = $expense->where('account_type', 'direct_cost');
        $overhead = $expense->where('account_type', 'overhead');
        $opex = $expense->where('account_type', 'opex');
        $unclassified = $expense->whereNotIn('account_type', self::EXPENSE_SECTIONS);

        $revenueTotal = $this->total($revenue);
        $expenseTotal = $this->total($expense);
        $directCostTotal = $this->total($directCost);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'sections' => [
                'revenue' => $this->present($revenue),
                'direct_cost' => $this->present($directCost),
                'overhead' => $this->present($overhead),
                'opex' => $this->present($opex),
                'unclassified' => $this->present($unclassified),
            ],
            'totals' => [
                'revenue' => $revenueTotal,
                'direct_cost' => $directCostTotal,
                'gross_profit' => bcsub($revenueTotal, $directCostTotal, 2),
                'overhead' => $this->total($overhead),
                'opex' => $this->total($opex),
                'unclassified_expense' => $this->total($unclassified),
                // Deliberately NOT "gross_profit minus the totals above": summed
                // independently from `category`, so this can never drift from
                // revenue minus expense even if a future account_type value is
                // missed in the section breakdown.
                'net_profit' => bcsub($revenueTotal, $expenseTotal, 2),
            ],
            'coverage' => LedgerCoverage::describe(),
        ];
    }

    /**
     * One row per account, signed toward its own normal balance so revenue
     * and expense both read as positive figures that add the way a person
     * reading a P&L expects.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function signedRows(string $from, string $to): Collection
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereIn('chart_of_accounts.category', ['revenue', 'expense'])
            ->whereDate('journal_entries.posting_date', '>=', $from)
            ->whereDate('journal_entries.posting_date', '<=', $to)
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

            // normal_balance is expected to be populated for every
            // revenue/expense account — the seeder sets it on all of them —
            // but a category-based fallback keeps a gap from flipping a
            // figure's sign rather than merely mislabelling its section.
            $normalBalance = $row->normal_balance ?? ($row->category === 'revenue' ? 'credit' : 'debit');

            return [
                'account_id' => (int) $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'category' => $row->category,
                'account_type' => $row->account_type,
                'amount' => $normalBalance === 'credit' ? bcsub($credit, $debit, 2) : bcsub($debit, $credit, 2),
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
