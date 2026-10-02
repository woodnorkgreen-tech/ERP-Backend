<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use Illuminate\Database\Eloquent\Builder;

/** One read-only base-currency GL projection shared by reports and BANK_CASH. */
class BankCashReportingService
{
    private const SOURCE_TYPES = ['bank', 'mobile_money', 'card', 'petty_cash'];

    public function cashFlowReadiness(): array
    {
        return [
            'supported' => false,
            'status' => 'NOT_SUPPORTED',
            'reason' => 'The ledger has no approved operating/investing/financing classification method. Cash Movement reports posted cash-account activity, not a statutory Cash Flow Statement.',
            'method' => null,
        ];
    }

    /** Historical authority cannot be asserted: no approved opening-balance registry exists. */
    private function readiness(array $configuration): array
    {
        return [
            'readiness' => 'HISTORICAL_DATA_INCOMPLETE',
            'historical_data_complete' => false,
            'opening_balance_status' => 'NOT_CONFIGURED',
            'reason' => 'Approved migrated opening balances and complete historical cash/bank journals are not established. Positions and brought-forward ledger balances reflect recorded GL activity only.',
            'configuration_complete' => ! collect($configuration)->contains(fn ($row) => $row['status'] === 'NOT_CONFIGURED' || $row['status'] === 'INVALID_ACCOUNT'),
        ];
    }

    /** Eligible asset accounts are deduplicated by ID, including petty cash once. */
    private function configuration(): array
    {
        $accounts = [];
        $channels = [];
        $include = function (?ChartOfAccount $account, string $key, string $name, string $type, bool $active) use (&$accounts, &$channels): void {
            $valid = $account && $account->is_active && $account->is_postable
                && $account->category === 'asset' && $account->account_type === 'balance_sheet';
            $status = ! $active ? 'INACTIVE' : (! $account ? 'NOT_CONFIGURED' : ($valid ? 'CONFIGURED' : 'INVALID_ACCOUNT'));
            $channels[$key] = ['source_code' => $key, 'source_name' => $name, 'source_type' => $type,
                'account_id' => $status === 'CONFIGURED' ? $account->id : null, 'status' => $status];
            if ($status !== 'CONFIGURED') {
                return;
            }
            $accounts[$account->id] ??= [
                'account_id' => $account->id, 'account_code' => $account->code, 'account_name' => $account->name,
                'account_type' => $account->account_type, 'source_types' => [], 'sources' => [],
                'configuration_status' => 'CONFIGURED', 'readiness' => 'HISTORICAL_DATA_INCOMPLETE',
            ];
            $accounts[$account->id]['source_types'][] = $type;
            $accounts[$account->id]['sources'][] = $key;
        };

        foreach (PaymentSource::query()->whereIn('type', self::SOURCE_TYPES)->with('glAccount')->orderBy('code')->get() as $source) {
            $include($source->glAccount, $source->code, $source->name, $source->type, $source->is_active);
        }
        // Posting functions are authoritative even where no payment-source row exists.
        foreach ([
            'BANK_DEFAULT' => [FinanceAccountFunctions::BANK_DEFAULT, 'Default operating bank', 'bank'],
            'PETTY_CASH_FLOAT' => [FinanceAccountFunctions::PETTY_CASH_FLOAT, 'Petty cash float', 'petty_cash'],
        ] as $key => [$reference, $name, $type]) {
            $include(ChartOfAccount::query()->where('code', ChartAccountMap::local($reference))->first(), $key, $name, $type, true);
        }
        // These are existing channel identities, not fabricated chart accounts or balances.
        foreach (['MPESA' => ['Company M-Pesa', 'mobile_money'], 'CARD' => ['Company Card', 'card']] as $key => [$name, $type]) {
            if (! isset($channels[$key])) {
                $include(null, $key, $name, $type, true);
            }
        }
        foreach ($accounts as &$account) {
            $account['source_types'] = array_values(array_unique($account['source_types']));
        }
        unset($account);
        usort($accounts, fn ($a, $b) => strcmp($a['account_code'], $b['account_code']));

        return [array_values($accounts), array_values($channels)];
    }

    private function ledger(array $ids, string $to): Builder
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $ids)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereDate('journal_entries.posting_date', '<=', $to);
    }

    /** Decimal strings throughout: never sum monetary balances through binary floats. */
    private function balances(array $ids, string $to, ?string $from = null): array
    {
        $query = $this->ledger($ids, $to);
        if ($from !== null) {
            $query->whereDate('journal_entries.posting_date', '>=', $from);
        }
        return $query->groupBy('journal_lines.account_id')->select('journal_lines.account_id')
            ->selectRaw("SUM(CASE WHEN entry_type = 'debit' THEN base_amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN entry_type = 'credit' THEN base_amount ELSE 0 END) as credits")
            ->get()->keyBy('account_id')->map(fn ($row) => [
                'debits' => bcadd((string) $row->debits, '0', 2),
                'credits' => bcadd((string) $row->credits, '0', 2),
                'balance' => bcsub((string) $row->debits, (string) $row->credits, 2),
            ])->all();
    }

    public function position(string $asAt): array
    {
        [$accounts, $configuration] = $this->configuration();
        $balances = $this->balances(array_column($accounts, 'account_id'), $asAt);
        $total = '0.00';
        foreach ($accounts as &$account) {
            $balance = $balances[$account['account_id']] ?? ['debits' => '0.00', 'credits' => '0.00', 'balance' => '0.00'];
            $account += [
                'opening_or_brought_forward_position' => null,
                'debits_to_date' => $balance['debits'], 'credits_to_date' => $balance['credits'],
                'closing_balance' => $balance['balance'],
                'drill_down' => ['account_id' => $account['account_id'], 'to' => $asAt],
            ];
            $total = bcadd($total, $balance['balance'], 2);
        }
        unset($account);
        return [
            'as_at' => $asAt, 'currency' => 'KES', 'accounts' => $accounts,
            'total_cash_and_bank' => $total, 'configuration' => $configuration,
            'scope' => 'Unique configured bank, mobile-money, card and petty-cash asset GL accounts; petty cash is included once.',
            ...$this->readiness($configuration), 'generated_at' => now()->toIso8601String(),
        ];
    }

    public function movement(string $from, string $to): array
    {
        [$accounts, $configuration] = $this->configuration();
        $ids = array_column($accounts, 'account_id');
        $before = \Carbon\Carbon::parse($from)->subDay()->toDateString();
        $opening = $this->balances($ids, $before);
        $period = $this->balances($ids, $to, $from);
        $inflows = $outflows = $ledgerOpening = '0.00';
        foreach ($accounts as &$account) {
            $prior = $opening[$account['account_id']]['balance'] ?? '0.00';
            $current = $period[$account['account_id']] ?? ['debits' => '0.00', 'credits' => '0.00', 'balance' => '0.00'];
            $account += [
                'opening_balance' => null, 'ledger_brought_forward_balance' => $prior,
                'period_debits' => $current['debits'], 'period_credits' => $current['credits'],
                'net_movement' => $current['balance'], 'closing_balance' => bcadd($prior, $current['balance'], 2),
                'drill_down' => ['account_id' => $account['account_id'], 'from' => $from, 'to' => $to],
            ];
            $ledgerOpening = bcadd($ledgerOpening, $prior, 2);
            $inflows = bcadd($inflows, $current['debits'], 2);
            $outflows = bcadd($outflows, $current['credits'], 2);
        }
        unset($account);

        // Eliminate only balanced journals consisting entirely of eligible cash
        // legs on >=2 distinct accounts. Mixed journals (fees, FX, split settlement)
        // are not guessed to be transfers; their gross activity remains disclosed.
        $journals = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereDate('journal_entries.posting_date', '>=', $from)->whereDate('journal_entries.posting_date', '<=', $to)
            ->whereIn('journal_entries.id', $this->ledger($ids, $to)->whereDate('journal_entries.posting_date', '>=', $from)->select('journal_entries.id'))
            ->select('journal_lines.journal_entry_id')->groupBy('journal_lines.journal_entry_id')
            ->selectRaw('COUNT(DISTINCT account_id) as account_count')
            ->selectRaw('SUM(CASE WHEN account_id IN ('.(count($ids) ? implode(',', array_map('intval', $ids)) : 'NULL').') THEN 0 ELSE 1 END) as non_cash_lines')
            ->selectRaw("SUM(CASE WHEN entry_type = 'debit' THEN base_amount ELSE 0 END) as debits")
            ->selectRaw("SUM(CASE WHEN entry_type = 'credit' THEN base_amount ELSE 0 END) as credits")->get();
        $eliminated = '0.00';
        $transfers = 0;
        foreach ($journals as $journal) {
            if ((int) $journal->non_cash_lines === 0 && (int) $journal->account_count >= 2
                && bccomp((string) $journal->debits, (string) $journal->credits, 2) === 0) {
                $eliminated = bcadd($eliminated, (string) $journal->debits, 2);
                $transfers++;
            }
        }
        $net = bcsub($inflows, $outflows, 2);
        return [
            'period' => ['from' => $from, 'to' => $to], 'currency' => 'KES',
            'opening_cash_position' => null, 'ledger_opening_cash_position' => $ledgerOpening,
            'cash_inflows' => bcsub($inflows, $eliminated, 2), 'cash_outflows' => bcsub($outflows, $eliminated, 2),
            'gross_account_debits' => $inflows, 'gross_account_credits' => $outflows,
            'internal_transfers_eliminated' => $eliminated, 'internal_transfer_journals' => $transfers,
            'transfer_method' => 'Balanced journals with only configured cash/bank legs across at least two accounts are eliminated from both company inflows and outflows. Mixed journals retain gross activity; no transfer allocation is assumed.',
            'net_cash_movement' => $net, 'closing_cash_position' => bcadd($ledgerOpening, $net, 2),
            'accounts' => $accounts, 'configuration' => $configuration,
            ...$this->readiness($configuration), 'generated_at' => now()->toIso8601String(),
        ];
    }
}
