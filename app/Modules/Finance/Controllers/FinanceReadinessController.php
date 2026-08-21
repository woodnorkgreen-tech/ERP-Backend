<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
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
        $checks = collect([
            $this->check('accounting_period', 'Open accounting period',
                $period?->isOpen() === true,
                $period
                    ? sprintf('%s %d is %s.', $period->starts_on->format('F'), $period->year, $period->status)
                    : 'No accounting period covers today.',
                'Run the Finance reference seeder, then confirm the current month is open.'),
            $this->countCheck('chart_of_accounts', 'Postable accounts',
                DB::table('chart_of_accounts')->where('is_postable', true)->count(),
                'No postable accounts are configured.'),
            $this->countCheck('expense_codes', 'Expense catalogue',
                DB::table('expense_codes')->where('is_active', true)
                    ->whereNotNull('default_debit_account_id')->count(),
                'No active expense codes are available for cost capture.'),
            $this->countCheck('payment_sources', 'Payment sources',
                DB::table('payment_sources')->where('is_active', true)->whereNotNull('gl_account_id')->count(),
                'No active payment source is connected to a ledger account.'),
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

        $integrity = [
            'verified_costs_without_journal' => DB::table('cost_lines')
                ->where('status', 'verified')->whereNull('journal_entry_id')->count(),
            'posted_journals_without_period' => DB::table('journal_entries')
                ->where('status', 'posted')->whereNull('accounting_period_id')->count(),
            'unbalanced_journal_headers' => DB::table('journal_entries')
                ->whereColumn('total_debit', '!=', 'total_credit')->count(),
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
            'setup_command' => app()->environment(['local', 'testing'])
                ? 'php artisan db:seed --class="App\\Modules\\Finance\\Database\\Seeders\\FinanceReferenceSeeder"'
                : null,
            'ledger_scope' => [
                'label' => 'Operational cost ledger',
                'note' => 'This ledger covers verified costs and spend vouchers. Revenue, payroll, opening balances and ordinary bank movements remain in the statutory accounting package.',
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
