<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Support\Facades\DB;

/** Read-only readiness projection. A passed reference check never approves policy or history. */
class FinanceControlCentreService
{
    public function report(array $checks, array $functions): array
    {
        $check = collect($checks)->keyBy('key');
        $domain = fn (string $key, string $label, string $state, string $message, string $href) => compact('key', 'label', 'state', 'message', 'href');
        $passed = fn (array $keys) => collect($keys)->every(fn ($key) => ($check->get($key)['ready'] ?? false) === true);
        $classificationGaps = DB::table('chart_of_accounts')->where('is_active', true)->where('is_postable', true)
            ->where(fn ($q) => $q->whereNull('account_type')->orWhereNull('normal_balance'))->count();
        $period = AccountingPeriod::forDate(now());
        $channels = PaymentSource::query()->with('glAccount')->orderBy('code')->get()->map(function ($source) {
            $account = $source->glAccount;
            $valid = $account && $account->is_active && $account->is_postable && $account->category === 'asset';
            return [
                'code' => $source->code, 'name' => $source->name, 'type' => $source->type,
                'active' => $source->is_active, 'can_make_payment' => $source->can_make_payment,
                'account' => $account ? ['code' => $account->code, 'name' => $account->name] : null,
                'state' => $source->type === 'payable' ? 'NOT_SUPPORTED' : ($valid ? ($source->is_active ? 'READY' : 'BLOCKED') : 'CONFIGURATION_REQUIRED'),
                'message' => $source->type === 'payable' ? 'Supplier credit is a liability, not a receiving or paying channel.'
                    : (! $valid ? 'An active, postable cash/bank asset account is required.' : ($source->is_active ? 'Linked to a postable asset account; live history still needs validation.' : 'Inactive; unavailable for receiving or paying.')),
            ];
        })->values()->all();
        foreach (['MPESA' => 'Company M-Pesa', 'CARD' => 'Company Card'] as $code => $name) {
            if (! collect($channels)->contains('code', $code)) {
                $channels[] = ['code' => $code, 'name' => $name, 'type' => $code === 'MPESA' ? 'mobile_money' : 'card', 'active' => false, 'can_make_payment' => false, 'account' => null, 'state' => 'CONFIGURATION_REQUIRED', 'message' => 'No channel or correct ledger account has been configured.'];
            }
        }
        $channelReady = collect($channels)->where('type', '<>', 'payable')->contains('state', 'READY')
            && ! collect($channels)->contains('state', 'CONFIGURATION_REQUIRED');
        $settings = FinanceSetting::query()->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->orderByDesc('effective_from')->get()->unique('key')->map(fn ($row) => [
                'key' => $row->key, 'label' => $row->label, 'authority' => 'Recorded Finance setting approval',
                'approved_by_id' => $row->approved_by, 'approved_at' => $row->approved_at?->toIso8601String(),
                'reference' => 'finance_settings#'.$row->id, 'effective_from' => $row->effective_from?->toDateString(),
                'effective_to' => $row->effective_to?->toDateString(), 'value' => in_array($row->value, [null, '', 'null'], true) ? null : $row->value,
                'approved' => $row->approved_by !== null && $row->approved_at !== null,
                'state' => $row->approved_by !== null && $row->approved_at !== null && ! in_array($row->value, [null, '', 'null'], true) ? 'READY' : 'POLICY_REQUIRED',
            ])->values()->all();
        $policyTitles = [
            'W1-10' => 'Credit-note COGS / WIP treatment',
            'W7-24' => 'Project labour GL / WIP project dimensions',
            'W7-25' => 'Standard labour versus actual payroll variance',
            'W7-26' => 'Employer statutory costs in standard labour rates',
            'salary-advance' => 'Salary advance GL treatment',
            'stores-consumption' => 'Non-project Stores consumption GL treatment',
            'stock-adjustments' => 'Manual stock adjustment authority',
            'opening-balances' => 'Opening balance and history provenance',
            'retained-earnings' => 'Retained earnings authority',
            'period-end' => 'Period-end / as-of financial authority',
            'wip-production' => 'Production approval of WIP recognition / release mode',
        ];
        $evidence = app(FinancePolicyEvidenceService::class);
        $settings = $evidence->settings($settings);
        $policies = $evidence->decisions($policyTitles);
        $domains = [
            $domain('software', 'Controlled Finance workflows', 'SOFTWARE_READY', 'Existing posting, approval, period and reversal services are retained. This is not a verdict on live data.', '/finance/work-queue'),
            $domain('chart', 'Chart & account mappings', $passed(['required_accounts', 'chart_profile']) && $classificationGaps === 0 ? 'READY' : 'CONFIGURATION_REQUIRED', "{$classificationGaps} active postable account(s) lack classification. Posting functions resolve through ChartAccountMap.", '/finance/setup?section=mapping'),
            $domain('channels', 'Payment & receiving channels', $channelReady ? 'READY' : 'CONFIGURATION_REQUIRED', 'Review each channel and its actual asset account; no account is invented for M-Pesa or Card.', '/finance/setup?section=banks'),
            $domain('period', 'Accounting periods', $period?->isOpen() ? 'READY' : 'BLOCKED', $period ? 'Current period: '.$period->status.'. Posting still checks assertOpenPeriod.' : 'No accounting period covers today.', '/finance/periods'),
            $domain('expenses', 'Expense codes & posting dependencies', $passed(['expense_codes', 'expense_code_mapping', 'cost_centres', 'activities']) ? 'READY' : 'CONFIGURATION_REQUIRED', 'Catalogue account, cost centre and activity resolution use the existing checks. An empty posting-rule table alone does not prove a defect; coded posting remains authoritative.', '/finance/setup?section=numbering'),
            $domain('tax', 'Tax configuration', $passed(['vat_treatments', 'wht_categories']) ? 'READY' : 'CONFIGURATION_REQUIRED', 'Reference treatments only; effective tax rules and evidence require Finance review before filing.', '/finance/tax'),
            // Report 75: READY only when a policy is approved and active in Finance Setup.
            // A deployment setting, a draft or a submission is not that.
            (function () use ($domain) {
                $wip = \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy();
                [$state, $message] = match ($wip['state']) {
                    'governed' => ['READY', "Approved and active in Finance Setup: {$wip['policy']}."],
                    'conflict' => ['BLOCKED', (string) $wip['message']],
                    'deployment' => ['POLICY_REQUIRED', "Supplied by the deployment setting ({$wip['policy']}) so tooling can run. It has not been approved in Finance Setup."],
                    default => ['POLICY_REQUIRED', 'No project cost treatment is approved and active. Decide it in Finance Setup > Accounting Policies.'],
                };

                return $domain('wip', 'Project cost / WIP policy', $state, $message, '/finance/setup?section=policies');
            })(),
            $domain('payroll', 'Payroll Finance policy', 'POLICY_REQUIRED', 'W7-24, W7-25, W7-26 and salary-advance treatment remain separate from aggregate payroll readiness. Employee HR details are not exposed here.', '/finance/setup/system-checks#policy-register'),
            $domain('petty-cash', 'Petty cash configuration', collect($settings)->filter(fn ($row) => str_starts_with($row['key'], 'petty_cash_'))->every(fn ($row) => $row['state'] === 'READY') && collect($settings)->contains(fn ($row) => str_starts_with($row['key'], 'petty_cash_')) ? 'READY' : 'POLICY_REQUIRED', 'Threshold and surrender policies need explicit approval; safe existing defaults do not establish policy readiness.', '/finance/petty-cash/overview'),
            $domain('documents', 'Document controls', 'DATA_REQUIRED', DB::table('document_sequences')->count().' sequence row(s) exist. Presence alone cannot establish every workflow sequence or history provenance.', '/finance/setup?section=numbering'),
            $domain('history', 'Opening / financial history', 'DATA_REQUIRED', 'Opening balances, AP authority, cash/bank history and period-end provenance are not certified by reference checks.', '/finance/reports?tab=reconciliation'),
            $domain('cash-flow', 'Statutory Cash Flow statement', 'NOT_SUPPORTED', 'The canonical reporting service supports Cash Movement and Cash Flow readiness; it does not fabricate a statutory statement.', '/finance/reports?tab=cash-flow'),
        ];
        return ['domains' => $domains, 'channels' => $channels, 'settings' => $settings, 'policies' => $policies,
            'classification_gaps' => $classificationGaps, 'wip_mode' => \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy()['policy'],
            'period' => $period ? ['status' => $period->status, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()] : null,
            'scope' => 'Configuration and policy gates are separate from reference-data checks. No live readiness or accounting policy is inferred.'];
    }
}
