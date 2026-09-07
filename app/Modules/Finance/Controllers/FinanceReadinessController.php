<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A read-only pre-flight check for the reference data Finance depends on. */
class FinanceReadinessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $today = now();
        $period = AccountingPeriod::forDate($today);
        $requiredAccounts = ['1030', '1200', '1300', '1330', '2100', '2120', '2150'];
        $availableRequiredAccounts = DB::table('chart_of_accounts')
            ->whereIn('code', $requiredAccounts)->where('is_postable', true)->where('is_active', true)->pluck('code');
        $missingRequiredAccounts = array_values(array_diff($requiredAccounts, $availableRequiredAccounts->all()));
        $unmappedExpenseCodes = DB::table('expense_codes as ec')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ec.default_debit_account_id')
            ->where('ec.is_active', true)
            ->where(function ($query) {
                $query->whereNull('coa.id')->orWhere('coa.is_postable', false)->orWhere('coa.is_active', false);
            })->count();
        $invalidPaymentSources = DB::table('payment_sources as ps')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ps.gl_account_id')
            ->where('ps.is_active', true)
            ->where(function ($query) {
                $query->whereNull('coa.id')->orWhere('coa.is_postable', false)->orWhere('coa.is_active', false);
            })->count();

        $checks = collect([
            $this->check('accounting_period', 'Open accounting period',
                $period?->isOpen() === true,
                $period
                    ? sprintf('%s %d is %s.', $period->starts_on->format('F'), $period->year, $period->status)
                    : 'No accounting period covers today.',
                'Run the Finance reference seeder, then confirm the current month is open.'),
            $this->countCheck('chart_of_accounts', 'Postable accounts',
                DB::table('chart_of_accounts')->where('is_postable', true)->where('is_active', true)->count(),
                'No postable accounts are configured.'),
            $this->check('required_accounts', 'Required control accounts',
                $missingRequiredAccounts === [],
                $missingRequiredAccounts === []
                    ? 'All required cash, advance, payable and tax control accounts are postable.'
                    : 'Missing or non-postable account code(s): '.implode(', ', $missingRequiredAccounts).'.',
                'Configure every named control account before posting.'),
            $this->check('expense_codes', 'Expense catalogue',
                DB::table('expense_codes')->where('is_active', true)->exists() && $unmappedExpenseCodes === 0,
                $unmappedExpenseCodes === 0
                    ? 'Every active expense code maps to a postable debit account.'
                    : number_format($unmappedExpenseCodes).' active expense code(s) have no postable debit account.',
                'Map or deactivate every unusable expense code.'),
            $this->check('payment_sources', 'Payment sources',
                DB::table('payment_sources')->where('is_active', true)->exists() && $invalidPaymentSources === 0,
                $invalidPaymentSources === 0
                    ? 'Every active payment source maps to a postable ledger account.'
                    : number_format($invalidPaymentSources).' active payment source(s) have no postable ledger account.',
                'Map or deactivate every unusable payment source.'),
            $this->countCheck('vat_treatments', 'VAT treatments',
                DB::table('vat_treatments')->where('is_active', true)->count(),
                'No active VAT treatments are configured.'),
            $this->countCheck('wht_categories', 'Withholding tax categories',
                DB::table('wht_categories')->where('is_active', true)->count(),
                'No active withholding-tax categories are configured.'),
            $this->countCheck('cost_centres', 'Cost centres',
                DB::table('cost_centres')->where('is_active', true)->count(),
                'No active cost centres are configured.'),
            $this->countCheck('activities', 'Project activities',
                DB::table('activities')->where('is_active', true)->count(),
                'No active Finance activities are configured.'),
        ]);

        $lineTotals = DB::table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->groupBy('je.id', 'je.total_debit', 'je.total_credit')
            ->selectRaw("je.id, je.total_debit, je.total_credit, COALESCE(SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.amount ELSE 0 END), 0) AS line_debit, COALESCE(SUM(CASE WHEN jl.entry_type = 'credit' THEN jl.amount ELSE 0 END), 0) AS line_credit")
            ->get();

        // One statement reads both sides from the same database snapshot, so a
        // concurrent disbursement cannot manufacture a reconciliation failure.
        $cash = DB::table('petty_cash_ledger_entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS ledger_balance")
            ->selectRaw('(SELECT current_balance FROM petty_cash_balances WHERE id = 1) AS cached_balance')
            ->first();

        $integrity = [
            'petty_cash_balance_mismatch' => bccomp(
                (string) $cash->ledger_balance, (string) ($cash->cached_balance ?? '0.00'), 2
            ) === 0 ? 0 : 1,
            // Budgets and commitments do not post: only accrued and actual costs
            // are accounting events. Match the verification service's posting gate.
            'verified_costs_without_journal' => DB::table('cost_lines')
                ->where('status', 'verified')
                ->whereIn('nature', [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL])
                ->whereNull('journal_entry_id')->count(),
            'posted_journals_without_period' => DB::table('journal_entries')
                ->where('status', 'posted')->whereNull('accounting_period_id')->count(),
            'unbalanced_journal_headers' => DB::table('journal_entries')
                ->whereColumn('total_debit', '!=', 'total_credit')->count(),
            'unbalanced_or_mismatched_journal_lines' => $lineTotals->filter(fn ($entry) =>
                bccomp((string) $entry->line_debit, (string) $entry->line_credit, 2) !== 0
                || bccomp((string) $entry->line_debit, (string) $entry->total_debit, 2) !== 0
                || bccomp((string) $entry->line_credit, (string) $entry->total_credit, 2) !== 0
            )->count(),
            'posted_vouchers_without_journal' => DB::table('spend_vouchers as sv')
                ->leftJoin('journal_entries as je', 'je.spend_voucher_id', '=', 'sv.id')
                ->where('sv.status', 'posted')->whereNull('je.id')->count(),
            'failed_stores_postings' => DB::table('stores_finance_postings')
                ->where('status', 'failed')->count(),
        ];

        $blockingIntegrity = array_sum($integrity);
        $ready = $checks->every(fn (array $check) => $check['ready']) && $blockingIntegrity === 0;

        return response()->json(['data' => [
            'ready' => $ready,
            'checked_at' => now()->toIso8601String(),
            'summary' => $ready
                ? 'Finance setup is ready for controlled posting.'
                : 'Finance setup needs attention before live posting.',
            'checks' => $checks->values(),
            'integrity' => $integrity,
            'operations' => app(\App\Modules\ProcurementStores\Services\OperationsReadinessService::class)->report(),
            'setup_command' => app()->environment(['local', 'testing'])
                ? 'php artisan db:seed --class="App\\Modules\\Finance\\Database\\Seeders\\FinanceReferenceSeeder"'
                : null,
            'ledger_scope' => [
                'label' => 'Operational cost ledger',
                'note' => 'This ledger covers verified costs, spend vouchers and payroll explicitly posted from HR. Revenue, opening balances and other bank movements remain in the statutory accounting package.',
            ],
        ]]);
    }

    private function countCheck(string $key, string $label, int $count, string $missing): array
    {
        return $this->check($key, $label, $count > 0,
            $count > 0 ? number_format($count).' active record(s) available.' : $missing,
            'Run the Finance reference seeder and review the resulting records with Finance.');
    }

    private function check(string $key, string $label, bool $ready, string $message, string $directive): array
    {
        return compact('key', 'label', 'ready', 'message', 'directive');
    }
}
