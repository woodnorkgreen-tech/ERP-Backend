<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\Services\ClientFinancialPositionService;
use App\Modules\Finance\Services\ReceivablesAgeingService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinancePositions;
use App\Models\ProjectEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET api/finance/overview?section=… — the Finance Overview's read projection (Report 65).
 *
 * One section per request, so each block of the Overview loads, fails and is
 * permission-gated on its own. Every figure comes from an existing authority:
 * the ledger (posted and reversed journal lines, as the trial balance reads
 * them), FinancePositions (shared with the Receivables and Payables
 * workspaces), ReceivablesAgeingService, CostAccountService::portfolioMargin
 * and ClientFinancialPositionService. Nothing here posts, seeds or writes.
 */
class FinanceOverviewController extends Controller
{
    private const SECTIONS = [
        'controls' => null,
        'cash' => Permissions::FINANCE_REPORTS_VIEW,
        'receivables' => Permissions::FINANCE_RECEIVABLES_READ,
        'payables' => Permissions::FINANCE_PAYABLES_READ,
        'projects' => Permissions::FINANCE_COSTS_PORTFOLIO,
    ];

    /** Holding any of these is what "may open the Finance Overview" means. */
    private const OVERVIEW_ACCESS = [
        Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_PAYABLES_READ,
        Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_PETTY_CASH_VIEW, Permissions::FINANCE_COSTS_PORTFOLIO,
    ];

    /** The base presentation currency. Every journal line carries base_amount in it (fx_rate to KES). */
    public const BASE_CURRENCY = 'KES';

    /** Projects evaluated for the portfolio figures; CostAccountService::portfolioMargin's own limit. */
    private const PROJECT_LIMIT = 200;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAny(self::OVERVIEW_ACCESS), 403, 'You do not have access to the Finance Overview.');

        $section = (string) $request->validate(['section' => ['required', 'in:'.implode(',', array_keys(self::SECTIONS))]])['section'];
        $permission = self::SECTIONS[$section];
        abort_unless($permission === null || $user->can($permission), 403, 'You do not have access to this part of the Overview.');

        $data = match ($section) {
            'controls' => $this->controls($user),
            'cash' => $this->cash($user),
            'receivables' => $this->receivables(),
            'payables' => FinancePositions::payables(),
            'projects' => $this->projects($request),
        };

        return response()->json(['data' => $data, 'as_of' => now()->toIso8601String()]);
    }

    /** The control header: period, currency, WIP policy, paying-account configuration and ledger equality. */
    private function controls(User $user): array
    {
        $period = AccountingPeriod::forDate(now());
        $sources = PaymentSource::query()->where('is_active', true)->where('type', '!=', 'payable')->get(['id', 'type', 'gl_account_id', 'can_make_payment']);

        return [
            'period' => $period ? [
                'year' => (int) $period->year,
                'month' => (int) $period->month,
                'label' => $period->starts_on->format('M Y'),
                'status' => $period->status,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'days_left' => max(0, (int) now()->startOfDay()->diffInDays($period->ends_on->copy()->startOfDay(), false)),
            ] : null,
            'currency' => self::BASE_CURRENCY,
            'wip_policy' => \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy()['policy'],
            'chart_profile' => config('finance_accounts.profile') ?: null,
            'paying_accounts' => [
                'configured' => $sources->filter(fn ($s) => $s->gl_account_id !== null && $s->can_make_payment)->count(),
                'unlinked' => $sources->whereNull('gl_account_id')->count(),
            ],
            // The trial balance's own measure; only for those who may read it.
            'ledger' => $user->can(Permissions::FINANCE_REPORTS_VIEW) ? $this->ledgerTotals() : null,
        ];
    }

    /**
     * Posted and reversed journal lines in base currency, exactly as
     * JournalEntryController::trialBalance totals them.
     */
    private function ledgerTotals(): array
    {
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->selectRaw("coalesce(sum(case when journal_lines.entry_type = 'debit' then journal_lines.base_amount else 0 end),0) as debit")
            ->selectRaw("coalesce(sum(case when journal_lines.entry_type = 'credit' then journal_lines.base_amount else 0 end),0) as credit")
            ->first();
        $debit = $this->money($row->debit ?? 0);
        $credit = $this->money($row->credit ?? 0);

        return ['debit' => $debit, 'credit' => $credit, 'difference' => bcsub($debit, $credit, 2), 'balanced' => bccomp($debit, $credit, 2) === 0];
    }

    /**
     * Cash per the ledger: the balance of each distinct account a paying
     * source is linked to. Not a bank statement: bank balances are not held in
     * this system, and opening balances have not been loaded.
     */
    private function cash(User $user): array
    {
        $position = app(\App\Modules\Finance\Services\BankCashReportingService::class)->position(now()->toDateString());
        $channels = collect($position['configuration'])->keyBy('source_code');
        $sourceCodes = PaymentSource::query()->where('is_active', true)->pluck('code')->all();
        $accounts = collect($position['accounts'])->map(function ($account) use ($channels) {
            $sources = collect($account['sources'])->reject(fn ($code) => in_array($code, ['BANK_DEFAULT', 'PETTY_CASH_FLOAT'], true))
                ->map(fn ($code) => $channels[$code]['source_name'])->values();
            // The overview keeps its compact display; reporting exposes all configured
            // zero-balance function accounts. Both consume exactly the same GL projection.
            if ($sources->isEmpty() && bccomp($account['closing_balance'], '0', 2) === 0) {
                return null;
            }
            return [
                'account' => $account['account_code'].' '.$account['account_name'],
                'type' => $account['source_types'][0],
                'sources' => $sources->isEmpty() ? [$account['account_name']] : $sources->all(),
                'balance' => $account['closing_balance'],
            ];
        })->filter()->values();
        $byType = $accounts->groupBy('type')->map(fn ($rows) => $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['balance'], 2), '0.00'));
        $float = $user->can(Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE) ? PettyCashBalance::query()->find(1)?->current_balance : null;

        return [
            'basis' => 'ledger',
            'total' => $position['total_cash_and_bank'],
            'by_type' => $byType,
            'accounts' => $accounts,
            'unlinked_sources' => collect($position['configuration'])->whereIn('status', ['NOT_CONFIGURED', 'INVALID_ACCOUNT'])
                ->filter(fn ($source) => in_array($source['source_code'], $sourceCodes, true))
                ->map(fn ($source) => ['code' => $source['source_code'], 'name' => $source['source_name'], 'type' => $source['source_type']])->values(),
            'petty_cash_float' => $float === null ? null : $this->money($float),
            'readiness' => $position['readiness'],
            'opening_balance_status' => $position['opening_balance_status'],
        ];
    }

    private function receivables(): array
    {
        $ageing = app(ReceivablesAgeingService::class)->summary();
        $overdue = collect($ageing['buckets'])->reject(fn ($b) => $b['id'] === 'current');

        return [
            'as_of' => $ageing['as_of'],
            'outstanding' => ['count' => (int) $ageing['totals']['count'], 'value' => $this->money($ageing['totals']['value'])],
            'overdue' => ['count' => (int) $overdue->sum('count'), 'value' => $this->money($overdue->sum(fn ($b) => (float) $b['value']))],
            'buckets' => collect($ageing['buckets'])->map(fn ($b) => ['id' => $b['id'], 'label' => $b['label'], 'count' => (int) $b['count'], 'value' => $this->money($b['value'])])->values(),
            'receipts' => FinancePositions::receipts(),
        ];
    }

    /**
     * Portfolio direct margin and WIP across projects that are not financially
     * closed and carry verified cost or posted billing, plus one project's
     * position. Margins are CostAccountService's: direct and provisional, with
     * their cost-completeness flags, never fully loaded.
     */
    private function projects(Request $request): array
    {
        $validated = $request->validate(['project' => ['nullable', 'integer']]);
        $ids = ProjectEnquiry::query()
            ->where(fn ($q) => $q->whereNull('financial_closure_status')->orWhere('financial_closure_status', '!=', 'closed'))
            ->where(fn ($q) => $q
                ->whereIn('id', CostLine::query()->counting()->where('nature', CostLine::NATURE_ACTUAL)->whereNotNull('project_enquiry_id')->select('project_enquiry_id'))
                ->orWhereIn('id', DB::table('project_invoices')->whereNotNull('journal_entry_id')->where('status', '!=', 'void')->select('project_enquiry_id')))
            ->orderByDesc('id')->limit(self::PROJECT_LIMIT)->pluck('id')->all();

        $margins = app(CostAccountService::class)->portfolioMargin($ids);
        $wipAccounts = DB::table('chart_of_accounts')->whereIn('code', ChartAccountMap::localMany(self::wipCodes()))->pluck('id');
        $wip = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->whereIn('journal_lines.account_id', $wipAccounts)
            ->selectRaw("coalesce(sum(case when journal_lines.entry_type = 'debit' then journal_lines.base_amount else -journal_lines.base_amount end),0) as balance")
            ->value('balance');

        $sum = fn (string $key) => $this->money(collect($margins)->sum(fn ($m) => (float) $m[$key]));
        $selected = $validated['project'] ?? collect($margins)->sortByDesc(fn ($m) => (float) $m['cost_of_sales'])->keys()->first();
        $enquiry = $selected ? ProjectEnquiry::query()->find($selected) : null;

        return [
            'active_projects' => count($ids),
            'limited' => count($ids) >= self::PROJECT_LIMIT,
            'billed_revenue' => $sum('billed_revenue'),
            'direct_cost' => $sum('cost_of_sales'),
            'direct_margin' => $sum('margin'),
            'margin_type' => 'direct',
            'margin_status' => 'provisional',
            'wip_balance' => $this->money($wip ?? 0),
            'wip_policy' => \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy()['policy'],
            'incomplete_costing' => collect($margins)->filter(fn ($m) => in_array('not_included', $m['cost_completeness'], true))->count(),
            'snapshot' => $enquiry ? [
                'project' => ['id' => $enquiry->id, 'job_number' => $enquiry->job_number, 'title' => $enquiry->title],
                'position' => app(ClientFinancialPositionService::class)->forEnquiry($enquiry),
                'cost_completeness' => $margins[$enquiry->id]['cost_completeness'] ?? null,
            ] : null,
        ];
    }

    /** @return list<string> */
    private static function wipCodes(): array
    {
        return [
            FinanceAccountFunctions::WIP_DIRECT_MATERIALS, FinanceAccountFunctions::WIP_DIRECT_LABOUR, FinanceAccountFunctions::WIP_SUBCONTRACTORS,
            FinanceAccountFunctions::WIP_TRANSPORT_LOGISTICS, FinanceAccountFunctions::WIP_EQUIPMENT_SITE, FinanceAccountFunctions::WIP_PROJECT_UTILITIES,
            FinanceAccountFunctions::WIP_PROJECT_FACILITATION, FinanceAccountFunctions::WIP_VENUE_STATUTORY, FinanceAccountFunctions::WIP_REWORK_WARRANTY,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
