<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\ProcurementStores\Services\StoresValuationReadinessService;
use Illuminate\Support\Facades\DB;

/** Read-only comparison of operational control totals with posted GL balances. */
class FinancialReconciliationService
{
    public const RECONCILED = 'RECONCILED';
    public const DIFFERENCE = 'DIFFERENCE';
    public const NOT_READY = 'NOT_READY';
    public const POLICY_BLOCKED = 'POLICY_BLOCKED';
    public const DATA_INCOMPLETE = 'DATA_INCOMPLETE';

    private const CONTROL_NAMES = [
        'AR' => 'Accounts receivable',
        'AP' => 'Accounts payable',
        'INVENTORY' => 'Inventory',
        'PAYROLL' => 'Payroll liabilities',
        'PETTY_CASH' => 'Petty cash float',
        'WIP' => 'Work in progress',
        'BANK_CASH' => 'System cash / GL',
    ];

    /** @return array{as_at:string,controls:array<int,array<string,mixed>>} */
    public function summary(string $asAt, ?string $selectedControl = null): array
    {
        $controls = array_keys(self::CONTROL_NAMES);
        if ($selectedControl !== null) {
            $controls = [$selectedControl];
        }

        return [
            'as_at' => $asAt,
            'controls' => array_map(fn (string $key) => match ($key) {
                'AR' => $this->accountsReceivable($asAt),
                'AP' => $this->notReady($key, $asAt, 'Supplier bills are not a complete as-at AP subledger; GRN accruals and voucher liabilities also post to AP control accounts.'),
                'INVENTORY' => $this->inventory($asAt),
                'PAYROLL' => $this->payroll($asAt),
                'PETTY_CASH' => $this->pettyCash($asAt),
                'WIP' => $this->workInProgress($asAt),
                'BANK_CASH' => $this->bankCash($asAt),
            }, $controls),
        ];
    }

    private function accountsReceivable(string $asAt): array
    {
        if ($asAt !== now()->toDateString()) {
            return $this->notReady('AR', $asAt, 'The verified receipt allocation projection is current-state, not a historical as-at subledger.');
        }

        $account = $this->controlAccount(FinanceAccountFunctions::ACCOUNTS_RECEIVABLE);
        if (! $account) {
            return $this->notReady('AR', $asAt, 'The configured Accounts Receivable GL control account is not active and postable.');
        }

        $ageing = app(ReceivablesAgeingService::class)->summary($asAt);
        $undatedOutstanding = ProjectInvoice::query()
            ->whereNotIn('status', ['draft', 'void'])
            ->whereNull('credits_invoice_id')
            ->whereNull('due_date')
            ->withVerifiedPaidAmount()
            ->withNetTotal()
            ->get()
            ->filter(fn (ProjectInvoice $invoice) => bccomp(
                bcsub(
                    number_format((float) ($invoice->net_total_amount ?? $invoice->total_amount), 2, '.', ''),
                    number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
                    2,
                ),
                '0.00',
                2,
            ) === 1);
        $subledger = $ageing['totals']['value'];
        $gl = $this->glBalance($account, $asAt);

        if ($undatedOutstanding->isNotEmpty()) {
            return $this->result('AR', $asAt, $subledger, $gl, null, self::DATA_INCOMPLETE, self::DATA_INCOMPLETE,
                'Issued invoices without due dates are excluded from receivables ageing; the resulting subledger is incomplete and no difference is asserted.', [
                    'undated_outstanding_invoices' => $undatedOutstanding->count(),
                    'subledger_source' => 'Receivables ageing projection; excludes outstanding invoices without due dates.',
                    'gl_source' => 'Posted and reversed journal lines on the configured Accounts Receivable account.',
                ], ['/finance/receivables', '/finance/ledger']);
        }

        return $this->comparison('AR', $asAt, $subledger, $gl, [
            'subledger_source' => 'Issued invoices less verified, non-reversed receipt allocations and issued credit notes.',
            'gl_source' => 'Posted and reversed journal lines on the configured Accounts Receivable account.',
            'invoice_count' => $ageing['totals']['count'],
        ], ['/finance/receivables', '/finance/ledger']);
    }

    private function inventory(string $asAt): array
    {
        if ($asAt !== now()->toDateString()) {
            return $this->notReady('INVENTORY', $asAt, 'Stores valuation is a current-state projection and cannot establish historical stock quantities or values.');
        }

        $valuation = app(StoresValuationReadinessService::class)->project();
        $account = $this->controlAccount(FinanceAccountFunctions::INVENTORY);
        if (! $account) {
            return $this->notReady('INVENTORY', $asAt, 'The configured Inventory Asset GL control account is not active and postable.', [
                'valuation' => $valuation['summary'],
            ]);
        }

        $gl = $this->glBalance($account, $asAt);
        $valued = collect($valuation['data'])->where('classification', StoresValuationReadinessService::VALUED);
        $subledger = number_format((float) $valued->sum('authoritative_value'), 2, '.', '');
        $complete = $valuation['summary']['unvalued'] === 0 && $valuation['summary']['requires_review'] === 0;
        if (! $complete) {
            return $this->result('INVENTORY', $asAt, $subledger, $gl, null, self::DATA_INCOMPLETE, 'DATA_INCOMPLETE',
                'Stores valuation readiness reports unvalued stock or stock requiring review; no variance is asserted from a partial subledger.', [
                    'subledger_source' => 'Authoritatively valued on-hand materials only.',
                    'gl_source' => 'Posted and reversed journal lines on the configured Inventory Asset account.',
                    'valuation' => $valuation['summary'],
                ], ['/finance/inventory', '/stores/inventory']);
        }

        return $this->comparison('INVENTORY', $asAt, $subledger, $gl, [
            'subledger_source' => 'Stores moving-weighted-average valuation, supported by priced receipt evidence.',
            'gl_source' => 'Posted and reversed journal lines on the configured Inventory Asset account.',
            'valuation' => $valuation['summary'],
        ], ['/finance/inventory', '/finance/ledger']);
    }

    private function payroll(string $asAt): array
    {
        if ($asAt !== now()->toDateString()) {
            return $this->notReady('PAYROLL', $asAt, 'Payroll Finance execution data is current-state and does not provide a historical as-at subledger.');
        }

        return $this->notReady('PAYROLL', $asAt,
            'Report 67 payroll execution/readiness does not establish a complete historical opening liability position. Only aggregate run counts are exposed; employee details are excluded.', [
                'payroll_runs' => PayrollRun::query()->count(),
                'posted_accrual_runs' => PayrollRun::query()->whereNotNull('accrual_journal_entry_id')->count(),
                'privacy' => 'Aggregate counts only; no employee salary, payslip, or bank details.',
            ]);
    }

    private function pettyCash(string $asAt): array
    {
        if ($asAt !== now()->toDateString()) {
            return $this->notReady('PETTY_CASH', $asAt, 'The petty-cash operational ledger has no approved historical opening-position policy for this as-at date.');
        }

        $account = $this->controlAccount(FinanceAccountFunctions::PETTY_CASH_FLOAT);
        if (! $account) {
            return $this->notReady('PETTY_CASH', $asAt, 'The configured Petty Cash Float GL control account is not active and postable.');
        }

        $subledger = DB::table('petty_cash_ledger_entries')
            ->where('posted_at', '<=', $asAt.' 23:59:59')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');

        return $this->comparison('PETTY_CASH', $asAt, $this->money($subledger), $this->glBalance($account, $asAt), [
            'subledger_source' => 'Petty Cash ledger credits less debits through the reporting date.',
            'gl_source' => 'Posted and reversed journal lines on the configured Petty Cash Float account.',
        ], ['/finance/petty-cash', '/finance/ledger']);
    }

    private function workInProgress(string $asAt): array
    {
        $policy = \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy()['policy'];
        if (! in_array($policy, ['capitalise', 'expense_on_capture'], true)) {
            return $this->result('WIP', $asAt, null, null, null, self::POLICY_BLOCKED, self::POLICY_BLOCKED,
                'Production WIP recognition policy is not configured/approved; no capitalisation assumption is made.', [
                    'configured_policy' => $policy,
                ], ['/finance/costs?tab=account', '/finance/setup?section=policies']);
        }

        return $this->notReady('WIP', $asAt, 'The WIP policy is configured, but no independent authoritative as-at WIP subledger is available for comparison.', [
            'configured_policy' => $policy,
        ]);
    }

    private function bankCash(string $asAt): array
    {
        $position = app(BankCashReportingService::class)->position($asAt);

        return $this->result('BANK_CASH', $asAt, null, $position['total_cash_and_bank'], null, self::NOT_READY, self::NOT_READY,
            'System Cash/GL only: the canonical Bank & Cash position identifies configured GL accounts but no independent authoritative cash subledger is available. No external bank-statement reconciliation is asserted.', [
                'subledger_source' => null,
                'gl_source' => 'BankCashReportingService: recorded posted and reversed cash/bank asset journal lines, including petty cash once.',
                'configured_gl_accounts' => count($position['accounts']),
                'configuration' => $position['configuration'],
                'position_readiness' => $position['readiness'],
                'opening_balance_status' => $position['opening_balance_status'],
                'external_statement_data_available' => false,
            ], ['/finance/reports?tab=bank-cash', '/finance/ledger']);
    }

    private function controlAccount(string $referenceCode): ?ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('code', ChartAccountMap::local($referenceCode))
            ->where('is_active', true)
            ->where('is_postable', true)
            ->first();
    }

    private function glBalance(ChartOfAccount $account, string $asAt): string
    {
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereDate('journal_entries.posting_date', '<=', $asAt)
            ->selectRaw("COALESCE(SUM(CASE WHEN journal_lines.entry_type = 'debit' THEN journal_lines.base_amount ELSE 0 END), 0) as debit")
            ->selectRaw("COALESCE(SUM(CASE WHEN journal_lines.entry_type = 'credit' THEN journal_lines.base_amount ELSE 0 END), 0) as credit")
            ->first();

        $debit = $this->money($row->debit ?? 0);
        $credit = $this->money($row->credit ?? 0);
        $normal = $account->normal_balance ?: ($account->category === 'asset' ? 'debit' : 'credit');

        return $normal === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }

    private function comparison(string $key, string $asAt, string $subledger, string $gl, array $evidence, array $drillDown): array
    {
        $difference = bcsub($subledger, $gl, 2);
        $status = bccomp($difference, '0.00', 2) === 0 ? self::RECONCILED : self::DIFFERENCE;

        return $this->result($key, $asAt, $subledger, $gl, $difference, $status, 'READY',
            $status === self::RECONCILED ? 'The authoritative subledger agrees with the configured GL control balance.' : 'The authoritative subledger differs from the configured GL control balance.',
            $evidence, $drillDown);
    }

    private function notReady(string $key, string $asAt, string $reason, array $evidence = []): array
    {
        return $this->result($key, $asAt, null, null, null, self::NOT_READY, self::NOT_READY, $reason, $evidence, []);
    }

    private function result(
        string $key,
        string $asAt,
        ?string $subledger,
        ?string $gl,
        ?string $difference,
        string $status,
        string $readiness,
        string $reason,
        array $evidence,
        array $drillDown,
    ): array {
        return [
            'control_key' => $key,
            'control_name' => self::CONTROL_NAMES[$key],
            'subledger_balance' => $subledger,
            'gl_balance' => $gl,
            'difference' => $difference,
            'status' => $status,
            'as_of_date' => $asAt,
            'readiness' => $readiness,
            'reason' => $reason,
            'evidence' => $evidence,
            'drill_down' => $drillDown,
        ];
    }

    private function money(string|int|float|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}