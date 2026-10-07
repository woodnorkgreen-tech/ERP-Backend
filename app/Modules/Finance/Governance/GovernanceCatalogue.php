<?php

namespace App\Modules\Finance\Governance;

use App\Modules\Finance\Support\RequisitionAdvanceControl;
use App\Constants\Permissions;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Everything at WNG Finance that a person decides, and the software then obeys
 * (Report 75).
 *
 * Nothing is stored here. Each item is derived from something that already
 * exists: the 37 posting functions, the accounts in the chart, the paying
 * accounts, the finance_settings rows, the tax tables. What people decide about
 * an item lives in finance_config_versions; this class says what the item is, in
 * plain words, what a valid answer looks like, and who has to agree.
 *
 * Every recommendation the system already carries (the chart profile's account
 * for a function, a seeded threshold) appears as a SUGGESTION. A suggestion is
 * input for review. It is never an approval, and nothing here turns one into one.
 */
final class GovernanceCatalogue
{
    public const DOMAINS = [
        'policies' => 'Accounting Policies',
        'mapping' => 'Account Mapping',
        'classification' => 'Account Classification',
        'banks' => 'Banks & Payment Methods',
        'petty_cash' => 'Petty Cash & Cash Controls',
        'tax' => 'Tax Configuration',
        'approval_rules' => 'Approval Rules',
    ];

    /** Who has to agree, and the permission that makes someone that person. */
    public const REQUIREMENTS = [
        'finance_review' => ['label' => 'Finance review', 'who' => 'A Finance approver', 'permission' => Permissions::FINANCE_CONFIG_APPROVE_OPERATIONAL],
        'operational_confirmation' => ['label' => 'Operational confirmation', 'who' => 'A Finance approver', 'permission' => Permissions::FINANCE_CONFIG_APPROVE_OPERATIONAL],
        'accountant_approval' => ['label' => 'Accountant approval', 'who' => 'The accountant', 'permission' => Permissions::FINANCE_CONFIG_APPROVE_ACCOUNTING],
        'management_approval' => ['label' => 'Management approval', 'who' => 'Management', 'permission' => Permissions::FINANCE_CONFIG_APPROVE_MANAGEMENT],
    ];

    /** The six mappings Report 74 could not call settled. Never bulk-approved. */
    public const JUDGEMENT_MAPPINGS = [
        'staff_advances' => 'STD-001 is "Short Term Debtors", not a named staff-advance account. It is right only if WNG uses it for nothing else.',
        'cos_direct_labour' => 'PE-007 sits in the Personnel Expenses family. Treating it as cost of sales moves direct labour into gross margin.',
        'cos_project_facilitation' => 'WNG also keeps separate Team Meals and Teams accommodation accounts; this pools all facilitation into one.',
        'cos_rework_warranty' => 'COS-018 is the catch-all "Other - COS"; WNG has no account named for rework or warranty.',
        'inventory_adjustments' => 'INV-001 "Inventory Shrinkage" would also receive stock-count gains.',
        'bank_charges' => 'All payment fees would post to Bank charges although WNG keeps M-Pesa charges separately.',
    ];

    /** The thirteen classifications the profile makes while recording a judgement. */
    public const JUDGEMENT_CLASSIFICATIONS = [
        'FK-001' => 'WNG records Faulu Kenya as an asset; whether it is a loan (a liability) is unconfirmed.',
        'DIV-001' => 'Contra-equity: a debit normal balance departs from the usual for equity.',
        'RI-001' => 'Contra-revenue kept at credit so the profit and loss deducts it; a deliberate sign convention.',
        'COS-001' => '"Change in inventory" is an adjustment, not a purchase cost.',
        'COS-017' => '"Discounts given" booked inside cost of sales; kept as WNG presents it.',
        'COS-019' => '"Overhead - COS" typed as overhead, which takes it out of gross margin.',
        'PE-007' => 'A Personnel Expenses account typed as direct cost so direct labour reaches gross margin.',
        'OTE-001' => 'No history; overtime could be direct project labour.',
        'WE-001' => 'No history; wages could be direct project labour.',
        'UE-001' => 'A QuickBooks catch-all whose nature is unknown.',
        'UCBPE-001' => 'A QuickBooks system account whose nature is unknown.',
        'EXD-001' => 'Excise duty treated as an operating expense, as WNG records it.',
        'INV-001' => 'Shrinkage typed as an operating expense; some presentations place it in cost of sales.',
    ];

    public const ACCOUNT_TYPES = [
        'balance_sheet' => 'Balance sheet', 'capex' => 'Capital expenditure', 'revenue' => 'Revenue',
        'direct_cost' => 'Direct cost (cost of sales)', 'overhead' => 'Production overhead', 'opex' => 'Operating expense',
    ];

    private const TYPES_BY_CATEGORY = [
        'asset' => ['balance_sheet', 'capex'], 'liability' => ['balance_sheet'], 'equity' => ['balance_sheet'],
        'revenue' => ['revenue'], 'expense' => ['direct_cost', 'overhead', 'opex'],
    ];

    /**
     * The Finance settings, in business terms. `kind` decides the unit and the
     * input; `requirement` is who signs. Keys are the finance_settings keys, so a
     * value activated here is exactly the row the existing code already reads.
     */
    private const SETTINGS = [
        'capitalisation_threshold' => ['policies', 'amount', 'accountant_approval', 'Capitalisation threshold', 'Above what purchase value is an item treated as an asset rather than an expense?', 'Flags purchases for capital-expenditure review.'],
        'reconciliation_date_tolerance_days' => ['policies', 'days', 'finance_review', 'Bank matching date tolerance', 'How many days apart may a bank line and a ledger entry be and still be matched automatically?', 'Automatic bank statement matching. Amount, account and reference must still agree exactly.', 0, 31],
        'margin_warning_percent' => ['policies', 'percent', 'management_approval', 'Projected margin warning', 'Below what projected margin should a project raise a warning?', 'Project cost account warnings.', 0, 100],
        'margin_escalation_percent' => ['policies', 'percent', 'management_approval', 'Final margin escalation', 'Below what final margin should a project be escalated to leadership?', 'Project cost account escalations.', 0, 100],
        'cost_overrun_alert_percent' => ['policies', 'percent', 'management_approval', 'Cost overrun alert', 'By how much may actual plus committed cost exceed the budget before an alert is raised?', 'Project cost overrun alerts.', 0, 100],
        'tax_return_due_day' => ['tax', 'day_of_month', 'accountant_approval', 'Tax return due day', 'On which day of the following month are the VAT return and withholding tax remittance due?', 'Due dates quoted on the tax schedules.', 1, 28],
        'input_vat_claim_window_months' => ['tax', 'months', 'accountant_approval', 'Input VAT claim window', 'For how many months after a purchase may its input VAT still be claimed?', 'The VAT claim schedule and the eTIMS gap report.', 1, 60],
        'petty_cash_max_per_transaction' => ['petty_cash', 'amount', 'management_approval', 'Petty cash limit per payment', 'What is the largest single payment that may be made from petty cash?', 'Payments above it are refused from the float and must go through accounts payable.'],
        'petty_cash_low_balance_threshold' => ['petty_cash', 'amount', 'finance_review', 'Low-balance alert', 'Below what float balance should Finance be warned to top up?', 'The petty cash low-balance warning.'],
        'petty_cash_critical_balance_threshold' => ['petty_cash', 'amount', 'finance_review', 'Critical-balance alert', 'Below what float balance is the position critical?', 'The petty cash critical-balance warning.'],
        'petty_cash_surrender_due_days' => ['petty_cash', 'days', 'finance_review', 'Surrender deadline', 'How many days after receiving an advance must it be accounted for?', 'The default due date on every advance. Without it, each advance needs its own date.', 1, 365],
        'petty_cash_surrender_due_soon_days' => ['petty_cash', 'days', 'finance_review', 'Surrender "due soon" window', 'How many days before its deadline should an advance show as due soon?', 'The due-soon marker on advances.', 0, 365],
        'purchase_order_auto_approval_limit' => ['approval_rules', 'amount', 'management_approval', 'Purchase order auto-approval limit', 'Up to what value may an order from an already-approved requisition skip its second approval?', 'Purchase orders at or below it, raised from an approved requisition and not grown beyond it, are approved once.'],
        'purchase_order_senior_approval_threshold' => ['approval_rules', 'amount', 'management_approval', 'Purchase order senior-approval threshold', 'Above what value does a purchase order need an additional senior approval?', 'Purchase orders above it need a second, senior approver.'],
        'spend_voucher_senior_approval_threshold' => ['approval_rules', 'amount', 'management_approval', 'Payment voucher senior-approval threshold', 'Above what value does a payment voucher need an additional senior approval?', 'Payment vouchers above it need a second, senior approver.'],
    ];

    private const UNITS = ['amount' => 'KES', 'days' => 'days', 'percent' => '%', 'months' => 'months', 'day_of_month' => 'day of the month'];

    /** @var array<string, array>|null */
    private ?array $items = null;

    private ?Collection $chart = null;

    /**
     * Forget what was read from the database. The catalogue is one instance per
     * request and reads the chart, the paying accounts and the settings once; the
     * controller calls this at the start of every action, so a long-lived process
     * never answers from the previous request's picture.
     */
    public function forget(): void
    {
        $this->items = null;
        $this->chart = null;
    }

    /**
     * The chart profile these decisions are about: the active one, or, before
     * cutover, the single profile this installation ships. Mappings can be reviewed
     * and approved before the profile is switched on; that is the point.
     */
    public function profile(): ?string
    {
        if (filled($active = config('finance_accounts.profile'))) {
            return (string) $active;
        }
        $available = collect(glob(database_path('finance/*-chart-profile.json')) ?: [])
            ->map(fn ($path) => preg_replace('/-chart-profile\.json$/', '', basename($path)))->values();

        return $available->count() === 1 ? $available->first() : null;
    }

    public function profileIsActive(): bool
    {
        return filled(config('finance_accounts.profile'));
    }

    /** @return array<string, array> */
    public function items(?string $domain = null): array
    {
        $this->items ??= $this->policies() + $this->mappings() + $this->classifications() + $this->banks() + $this->settings() + $this->tax();

        return $domain === null ? $this->items : array_filter($this->items, fn ($item) => $item['domain'] === $domain);
    }

    public function item(string $key): ?array
    {
        return $this->items()[$key] ?? null;
    }

    private function base(string $key, string $domain, string $type, string $requirement, string $title, string $question, string $affects, array $more = []): array
    {
        return $more + [
            'key' => $key, 'domain' => $domain, 'type' => $type, 'requirement' => $requirement,
            'requirement_label' => self::REQUIREMENTS[$requirement]['label'],
            'title' => $title, 'question' => $question, 'affects' => $affects, 'why' => null,
            'required' => true, 'applies' => 'record', 'suggestion' => null, 'suggestion_source' => null,
            'current' => null, 'flags' => [], 'options' => null, 'unit' => null, 'bulk' => false,
        ];
    }

    // ---- Accounting policies -------------------------------------------------

    private function policies(): array
    {
        $wip = $this->base('policy.wip', 'policies', 'choice', 'accountant_approval', 'Project cost treatment',
            'How should costs for ongoing projects be treated?',
            'Where every project cost is posted when it is captured, and what moves to cost of sales when a job is invoiced.', [
                'why' => 'Until this is decided, project costs cannot be posted and invoices cannot be issued. The system will not choose for you.',
                'applies' => 'runtime',
                'options' => [
                    FinanceChartProfile::WIP_CAPITALISE => ['label' => 'Hold as Work in Progress until release', 'help' => 'A project cost sits on the balance sheet as work in progress and moves to cost of sales in step with invoicing.'],
                    FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE => ['label' => 'Recognise as project cost immediately', 'help' => 'A project cost goes straight to cost of sales when it is captured. Nothing is held as work in progress.'],
                ],
                // Deliberately no suggestion: the profile's default is not put in front of the accountant as the answer.
            ]);

        return ['policy.wip' => $wip] + $this->requisitionAdvanceControl();
    }

    /**
     * Report 75R-B: how money advanced through a Financial Requisition is held
     * until it is accounted for.
     *
     * WNG's decision (Report 75R-C): every requisition payment — to an employee,
     * a supplier or another approved recipient — is held in the existing Staff
     * Advances account until it is accounted for. That decision is put forward
     * here as the proposed answer for each kind of receiver. It is still only a
     * proposal: it becomes the approved policy when someone with authority
     * approves it through this screen, and not before. No account is created.
     */
    private function requisitionAdvanceControl(): array
    {
        $existing = ChartAccountMap::local(FinanceAccountFunctions::STAFF_ADVANCES);
        $receivers = [
            'employee' => ['An employee', 'money advanced to an employee'],
            'supplier' => ['A supplier or service provider', 'money advanced to a supplier or service provider'],
            'other' => ['Another approved recipient', 'money advanced to a recipient who is neither an employee nor a supplier'],
        ];
        $items = [];
        foreach ($receivers as $type => [$label, $phrase]) {
            $key = RequisitionAdvanceControl::item($type);
            $items[$key] = $this->base($key, 'policies', 'account', 'accountant_approval',
                "Requisition advances — {$label}",
                'How should money advanced through a Financial Requisition be controlled before accountability?',
                "Which account holds {$phrase} from the day it is paid until it is accounted for or returned.", [
                    'why' => "WNG has decided to hold {$phrase} in Staff Advances ({$existing}) until it is accounted for. Paying it is a money movement, not an expense; the expense is recognised when the accountability is accepted. This is proposed here and takes effect as policy once approved.",
                    'suggestion' => ['account_code' => $existing],
                    'suggestion_source' => 'WNG decision, Report 75R-C: Staff Advances for every kind of receiver.',
                    'suggestion_account' => $this->accountView($existing),
                    'applies' => 'runtime',
                    'expected' => null,
                    'current' => ['account_code' => $existing, 'resolves' => (bool) $this->chart()->get($existing)?->is_postable,
                        'account' => $this->accountView($existing), 'basis' => 'existing_unapproved'],
                    'receiver_type' => $type,
                ]);
        }

        return $items;
    }

    // ---- Account mapping -----------------------------------------------------

    private function mappings(): array
    {
        $profile = FinanceChartProfile::load($this->profile());
        $functions = FinanceAccountFunctions::all();
        $new = collect((array) ($profile['new_accounts'] ?? []))->keyBy('code');
        $reuse = FinanceChartProfile::reuse($this->profile());
        $items = [];

        foreach ($functions as $key => $function) {
            $isWip = str_starts_with($key, 'wip_');
            $declared = $profile === null ? $function['code']
                : ($profile['functions'][$key]['account'] ?? ($profile['wip_policies'][FinanceChartProfile::WIP_CAPITALISE][$key] ?? null));
            $declared = $declared === null ? null : (string) ($reuse[$declared] ?? $declared);
            $expected = ChartOfAccountSeeder::referenceAccount($function['code']);
            $resolved = ChartAccountMap::local($function['code']);
            $resolvedAccount = $this->chart()->get($resolved);

            $items["mapping.{$key}"] = $this->base("mapping.{$key}", 'mapping', 'account', 'accountant_approval',
                ucfirst($function['meaning']), 'Which account should this post to?', $function['used_by'], [
                    'applies' => 'runtime',
                    'function' => $key, 'reference' => $function['code'], 'expected' => $expected,
                    'why' => $isWip ? 'Used while project costs are held as work in progress. If costs are recognised immediately, this function posts to its cost-of-sales account instead and this mapping is not used.' : null,
                    'suggestion' => $declared === null ? null : ['account_code' => $declared],
                    'suggestion_source' => $profile === null ? 'The reference chart this installation keeps'
                        : 'Proposed chart setup for WNG'.(filled($profile['functions'][$key]['note'] ?? null) ? ': '.$profile['functions'][$key]['note'] : ''),
                    'suggestion_account' => $declared === null ? null : $this->accountView($declared, $new),
                    'current' => ['account_code' => $resolved, 'resolves' => (bool) ($resolvedAccount?->is_postable && $resolvedAccount?->is_active),
                        'account' => $this->accountView($resolved, $new)],
                    'flags' => array_filter(['judgement' => self::JUDGEMENT_MAPPINGS[$key] ?? null]),
                    'bulk' => ! isset(self::JUDGEMENT_MAPPINGS[$key]),
                    'workflows' => $function['workflows'],
                ]);
        }

        return $items;
    }

    // ---- Account classification ---------------------------------------------

    private function classifications(): array
    {
        $profile = FinanceChartProfile::load($this->profile());
        $proposed = (array) ($profile['existing_classification']['accounts'] ?? []);
        $pending = (array) ($profile['existing_classification']['unclassified_pending_decision'] ?? []);
        $items = [];

        $codes = collect(array_keys($proposed))->merge(array_keys($pending))
            ->merge($this->chart()->filter(fn ($a) => $a->is_active && $a->is_postable && ($a->account_type === null || $a->normal_balance === null))->keys())
            ->map(fn ($code) => (string) $code)->unique()->sort()->values();

        foreach ($codes as $code) {
            $account = $this->chart()->get($code);
            if (! $account) {
                continue;   // not this chart's account
            }
            $spec = $proposed[$code] ?? null;
            $judgement = self::JUDGEMENT_CLASSIFICATIONS[$code] ?? null;
            $confidence = match (true) {
                $spec === null => 'unclassified',
                $judgement !== null => 'accountant_judgement',
                in_array($account->category, ['asset', 'liability', 'equity', 'revenue'], true) => 'deterministic',
                default => 'strong_evidence',
            };
            $basis = match ($confidence) {
                'deterministic' => "Follows from the category already recorded for this account ({$account->category}).",
                'strong_evidence' => 'Balance follows from the category (expense); the type follows the account family WNG already files it under.',
                'accountant_judgement' => $judgement,
                default => (string) ($pending[$code] ?? 'No classification has been proposed for this account.'),
            };

            $items["classification.{$code}"] = $this->base("classification.{$code}", 'classification', 'classification', 'accountant_approval',
                "{$code} · {$account->name}", 'How should this account be classified for reporting?',
                'Where the account appears in the profit and loss and the balance sheet, and whether it counts towards gross margin.', [
                    'account' => ['code' => $code, 'name' => $account->name, 'category' => $account->category],
                    'confidence' => $confidence, 'why' => $basis,
                    'required' => $spec !== null,
                    'suggestion' => $spec === null ? null : ['account_type' => $spec['account_type'] ?? null, 'normal_balance' => $spec['normal_balance'] ?? null],
                    'suggestion_source' => $spec === null ? null : 'Proposed chart setup for WNG',
                    'current' => ['account_type' => $account->account_type, 'normal_balance' => $account->normal_balance],
                    'options' => ['account_types' => array_intersect_key(self::ACCOUNT_TYPES, array_flip(self::TYPES_BY_CATEGORY[$account->category] ?? array_keys(self::ACCOUNT_TYPES))),
                        'normal_balances' => ['debit' => 'Debit', 'credit' => 'Credit']],
                    'flags' => array_filter(['judgement' => $confidence === 'accountant_judgement' ? $judgement : null,
                        'undecided' => $confidence === 'unclassified' ? $basis : null]),
                    'bulk' => in_array($confidence, ['deterministic', 'strong_evidence'], true),
                ]);
        }

        return $items;
    }

    // ---- Banks and payment methods ------------------------------------------

    private function banks(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_sources')) {
            return [];
        }
        $declared = FinanceChartProfile::paymentSources($this->profile());
        $sources = DB::table('payment_sources as ps')->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ps.gl_account_id')
            ->orderBy('ps.id')->get(['ps.id', 'ps.code', 'ps.name', 'ps.type', 'ps.is_active', 'ps.float_limit', 'coa.code as account_code', 'coa.name as account_name', 'coa.category as account_category']);
        $items = [];

        foreach ($sources->whereIn('type', ['bank', 'petty_cash']) as $source) {
            $suggested = $declared[$source->code] ?? $source->account_code;
            $items["bank.{$source->code}"] = $this->base("bank.{$source->code}", 'banks', 'bank', 'finance_review', $source->name,
                'Is this account in use, and which ledger account holds its balance?',
                $source->type === 'petty_cash' ? 'Cash payments, cash receipts and float top-ups.' : 'Payments made from, and receipts banked into, this account.', [
                    'applies' => 'payment_source', 'source' => ['code' => $source->code, 'name' => $source->name, 'type' => $source->type],
                    'capabilities' => ['payment' => true, 'receipt' => true],
                    'suggestion' => $suggested === null ? null : ['active' => (bool) $source->is_active, 'account_code' => (string) $suggested],
                    'suggestion_source' => isset($declared[$source->code]) ? 'The proposed chart setup names this account; whether it is in use is as currently set' : 'As currently set in the system',
                    'current' => ['active' => (bool) $source->is_active, 'account_code' => $source->account_code, 'account_name' => $source->account_name],
                    'why' => 'An account being in the chart does not make it a working bank account. It is used only once Finance confirms it.',
                ]);
        }

        $mpesa = $sources->firstWhere('code', 'MPESA');
        $items['channel.mpesa'] = $this->base('channel.mpesa', 'banks', 'mpesa', 'accountant_approval', 'M-Pesa',
            'Does WNG use M-Pesa for company transactions?', 'Whether M-Pesa receipts and payments can be recorded, and which account they land in.', [
                'applies' => 'payment_source',
                'why' => 'While this is undecided M-Pesa cannot be linked to an account. No account is created or assumed for it.',
                'current' => $mpesa ? ['active' => (bool) $mpesa->is_active, 'account_code' => $mpesa->account_code, 'account_name' => $mpesa->account_name] : null,
                'options' => ['modes' => [
                    'held_balance' => ['label' => 'WNG holds a balance in an M-Pesa wallet or till', 'help' => 'M-Pesa is its own pot of money. It needs its own asset account, which must already exist in the chart.'],
                    'settlement_channel' => ['label' => 'M-Pesa is only a channel that settles to a bank', 'help' => 'Money passes through M-Pesa into a bank account. It is recorded in that bank account.'],
                ], 'settlement_sources' => $this->settlementSources($sources)],
            ]);

        $card = $sources->firstWhere('code', 'CARD');
        $items['channel.card'] = $this->base('channel.card', 'banks', 'card', 'accountant_approval', 'Company card',
            'Does WNG operate a company card?', 'Whether card payments can be recorded, and which bank account they are drawn from.', [
                'applies' => 'payment_source',
                'why' => 'A card is recorded against the bank account it settles to. The card number is never stored.',
                'current' => $card ? ['active' => (bool) $card->is_active, 'account_code' => $card->account_code, 'account_name' => $card->account_name] : null,
                'options' => ['settlement_sources' => $this->settlementSources($sources),
                    'custodian_roles' => Role::query()->orderBy('name')->pluck('name')->all()],
            ]);

        $float = $sources->firstWhere('type', 'petty_cash');
        if ($float) {
            $items['cash.float_ceiling'] = $this->base('cash.float_ceiling', 'petty_cash', 'number', 'management_approval', 'Petty cash float ceiling',
                'What is the most cash the petty cash float may hold?', 'Recorded against the float. No workflow enforces it yet; it is shown to whoever tops the float up.', [
                    'applies' => 'float_limit', 'kind' => 'amount', 'unit' => 'KES', 'allow_none' => true,
                    'current' => ['amount' => $float->float_limit === null ? null : (float) $float->float_limit, 'approved' => false],
                    'source' => ['code' => $float->code],
                ]);
        }

        return $items;
    }

    /** Bank accounts a channel may settle to: they exist and carry a ledger account. */
    private function settlementSources(Collection $sources): array
    {
        return $sources->where('type', 'bank')->whereNotNull('account_code')
            ->mapWithKeys(fn ($s) => [$s->code => ['label' => $s->name, 'account' => "{$s->account_code} · {$s->account_name}", 'active' => (bool) $s->is_active]])->all();
    }

    // ---- Settings (petty cash, approval rules, thresholds) --------------------

    private function settings(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('finance_settings')) {
            return [];
        }
        $today = now()->toDateString();
        $rows = DB::table('finance_settings')->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderBy('effective_from')->get()->keyBy('key');
        $items = [];

        foreach (self::SETTINGS as $key => $definition) {
            [$domain, $kind, $requirement, $title, $question, $affects] = $definition;
            $row = $rows->get($key);
            $stored = $row ? json_decode((string) $row->value, true) : null;
            $stored = is_numeric($stored) ? $stored + 0 : null;
            $approved = $row && $row->approved_by !== null && $row->approved_at !== null;

            $items["setting.{$key}"] = $this->base("setting.{$key}", $domain, 'number', $requirement, $title, $question, $affects, [
                'applies' => 'finance_setting', 'setting' => $key, 'kind' => $kind, 'unit' => self::UNITS[$kind],
                'min' => $definition[6] ?? 0, 'max' => $definition[7] ?? null, 'allow_none' => true,
                // A stored figure nobody approved is a recommendation waiting for a decision.
                'suggestion' => $stored === null || $approved ? null : ['amount' => $stored],
                'suggestion_source' => $stored === null || $approved ? null : 'A recommended figure already in the system. Nobody has approved it, and nothing enforces it.',
                'current' => ['amount' => $stored, 'approved' => $approved],
            ]);
        }

        return $items;
    }

    // ---- Tax -----------------------------------------------------------------

    private function tax(): array
    {
        $items = [];
        foreach ([['vat_treatments', 'tax.vat', 'VAT treatment'], ['wht_categories', 'tax.wht', 'Withholding tax category']] as [$table, $prefix, $label]) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $rows = DB::table("{$table} as t")->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 't.gl_account_id')
                ->orderBy('t.id')->get(['t.*', 'coa.code as account_code', 'coa.name as account_name']);
            foreach ($rows as $row) {
                $snapshot = ['code' => $row->code, 'name' => $row->name, 'rate_percent' => (float) $row->rate_percent,
                    'account_code' => $row->account_code, 'account_name' => $row->account_name,
                    'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'is_active' => (bool) $row->is_active]
                    + ($table === 'vat_treatments'
                        ? ['is_recoverable' => (bool) $row->is_recoverable, 'requires_etims' => (bool) $row->requires_etims, 'claim_window_months' => $row->claim_window_months]
                        : ['residency' => $row->residency, 'threshold_amount' => $row->threshold_amount === null ? null : (float) $row->threshold_amount]);

                $items["{$prefix}.{$row->code}"] = $this->base("{$prefix}.{$row->code}", 'tax', 'verification', 'accountant_approval', "{$label}: {$row->name}",
                    'Is this rate, and the account it posts to, correct for WNG?',
                    $table === 'vat_treatments' ? 'VAT on every purchase and invoice line using this treatment, and the VAT schedules.' : 'Tax withheld from supplier payments in this category, and the withholding schedule.', [
                        'why' => 'These figures were loaded as reference data. They are used as they stand; this records that an authorised person has checked them.',
                        'tax' => $table === 'vat_treatments' ? 'vat' : 'wht', 'current' => $snapshot, 'bulk' => false,
                    ]);
            }
        }

        return $items;
    }

    // ---- Accounts ------------------------------------------------------------

    public function chart(): Collection
    {
        return $this->chart ??= DB::table('chart_of_accounts')->get()->keyBy('code');
    }

    /** An account as a person needs to see it, including one the cutover will create. */
    public function accountView(?string $code, ?Collection $new = null): ?array
    {
        if ($code === null) {
            return null;
        }
        $new ??= collect((array) (FinanceChartProfile::load($this->profile())['new_accounts'] ?? []))->keyBy('code');
        $account = $this->chart()->get($code);
        if ($account) {
            return ['code' => $code, 'name' => $account->name, 'category' => $account->category, 'account_type' => $account->account_type,
                'normal_balance' => $account->normal_balance, 'is_postable' => (bool) $account->is_postable, 'is_active' => (bool) $account->is_active, 'exists' => true];
        }
        if ($spec = $new->get($code)) {
            return ['code' => $code, 'name' => $spec['name'], 'category' => $spec['category'], 'account_type' => $spec['account_type'] ?? null,
                'normal_balance' => $spec['normal_balance'], 'is_postable' => (bool) ($spec['is_postable'] ?? true), 'is_active' => true, 'exists' => false];
        }

        return ['code' => $code, 'name' => null, 'category' => null, 'account_type' => null, 'normal_balance' => null, 'is_postable' => false, 'is_active' => false, 'exists' => false, 'unknown' => true];
    }

    /**
     * Why an account cannot serve an item, or null when it can.
     *
     * A posting function has a kind of account it belongs on (an asset, a
     * liability, a cost of sales account). The reference chart is the statement of
     * that kind. An account that is inactive, a header, or the wrong side of the
     * balance sheet is refused whoever asks.
     */
    public function accountProblem(array $item, ?array $account): ?string
    {
        if ($account === null || ($account['unknown'] ?? false)) {
            return 'That account is not in the chart of accounts, and is not one the chart profile will create.';
        }
        if (! $account['is_active']) {
            return "{$account['code']} is inactive.";
        }
        if (! $account['is_postable']) {
            return "{$account['code']} is a header account; entries cannot be posted to it.";
        }
        $expected = match ($item['type']) {
            'account' => $item['expected'] ?? null,
            'bank', 'mpesa' => ['category' => 'asset', 'normal_balance' => 'debit', 'account_type' => 'balance_sheet'],
            default => null,
        };
        if ($expected === null) {
            return null;
        }
        if ($account['category'] !== $expected['category']) {
            return "{$account['code']} is ".$this->a($account['category'])." account; this needs ".$this->a($expected['category']).' account.';
        }
        if ($account['normal_balance'] !== null && $account['normal_balance'] !== $expected['normal_balance']) {
            return "{$account['code']} normally carries a {$account['normal_balance']} balance; this needs a {$expected['normal_balance']} balance.";
        }
        if ($account['account_type'] !== null && ($expected['account_type'] ?? null) !== null && $account['account_type'] !== $expected['account_type']) {
            return "{$account['code']} is classified as ".strtolower(self::ACCOUNT_TYPES[$account['account_type']] ?? $account['account_type'])
                .'; this needs '.strtolower(self::ACCOUNT_TYPES[$expected['account_type']] ?? $expected['account_type']).'.';
        }

        return null;
    }

    private function a(?string $category): string
    {
        return in_array($category, ['asset', 'equity', 'expense'], true) ? "an {$category}" : 'a '.($category ?? 'unknown');
    }

    /**
     * Accounts that may be chosen for an item, searched by code or name. Only the
     * ones that pass accountProblem() are offered, so the picker cannot produce an
     * assignment the server would then refuse.
     *
     * @return list<array>
     */
    public function eligibleAccounts(array $item, ?string $search = null, int $limit = 40): array
    {
        $new = collect((array) (FinanceChartProfile::load($this->profile())['new_accounts'] ?? []))->keyBy('code');
        $needle = mb_strtolower(trim((string) $search));
        $candidates = $this->chart()->keys()->map(fn ($code) => (string) $code);
        if ($item['type'] === 'account') {
            $candidates = $candidates->merge($new->keys())->unique();
        }

        return $candidates->map(fn ($code) => $this->accountView($code, $new))
            ->filter(fn ($account) => $this->accountProblem($item, $account) === null)
            ->filter(fn ($account) => $needle === '' || str_contains(mb_strtolower($account['code'].' '.$account['name']), $needle))
            ->sortBy('code')->take($limit)->values()->all();
    }

    // ---- Validation ----------------------------------------------------------

    /**
     * A proposed value, checked and reduced to exactly the fields its type has.
     * Anything the type does not define is dropped, so a hidden form field can
     * never ride along into an approved version.
     *
     * @param  array<string, array>  $others  other items' values in force, for rules that span two settings
     */
    public function validate(array $item, array $value, array $others = []): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages(["value.{$field}" => $message]);

        switch ($item['type']) {
            case 'choice':
                $choice = $value['choice'] ?? null;
                if (! is_string($choice) || ! isset($item['options'][$choice])) {
                    $fail('choice', 'Choose one of the options.');
                }

                return ['choice' => $choice];

            case 'account':
                $code = $value['account_code'] ?? null;
                if (! is_string($code) || $code === '') {
                    $fail('account_code', 'Choose an account.');
                }
                if ($problem = $this->accountProblem($item, $this->accountView($code))) {
                    $fail('account_code', $problem);
                }

                return ['account_code' => $code];

            case 'classification':
                $type = $value['account_type'] ?? null;
                $balance = $value['normal_balance'] ?? null;
                if (! is_string($type) || ! isset($item['options']['account_types'][$type])) {
                    $fail('account_type', 'Choose a classification that suits '.$this->a($item['account']['category']).' account.');
                }
                if (! in_array($balance, ['debit', 'credit'], true)) {
                    $fail('normal_balance', 'Choose debit or credit.');
                }

                return ['account_type' => $type, 'normal_balance' => $balance];

            case 'number':
                if (($value['not_applicable'] ?? false) === true) {
                    if (! ($item['allow_none'] ?? false)) {
                        $fail('amount', 'A value is required.');
                    }

                    return ['not_applicable' => true, 'amount' => null];
                }
                $amount = $value['amount'] ?? null;
                if (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount))) {
                    $fail('amount', 'Enter a number.');
                }
                $amount += 0;
                $whole = in_array($item['kind'], ['days', 'months', 'day_of_month'], true);
                if ($whole && floor($amount) != $amount) {
                    $fail('amount', 'Enter a whole number of '.$item['unit'].'.');
                }
                if ($amount < ($item['min'] ?? 0)) {
                    $fail('amount', 'This cannot be less than '.($item['min'] ?? 0).'.');
                }
                if (($item['max'] ?? null) !== null && $amount > $item['max']) {
                    $fail('amount', "This cannot be more than {$item['max']}.");
                }
                if ($item['kind'] === 'amount' && round($amount, 2) != $amount) {
                    $fail('amount', 'Enter an amount with at most two decimal places.');
                }
                // A whole number is stored as one: 25000, not 25000.0.
                $amount = $whole || floor($amount) == $amount ? (int) $amount : round((float) $amount, 2);
                $this->crossCheck($item, $amount, $others, $fail);

                return ['not_applicable' => false, 'amount' => $amount];

            case 'bank':
                $active = $value['active'] ?? null;
                $code = $value['account_code'] ?? null;
                if (! is_bool($active)) {
                    $fail('active', 'Say whether this account is in use.');
                }
                if (! is_string($code) || $code === '') {
                    $fail('account_code', 'Choose the ledger account that holds this balance.');
                }
                $account = $this->accountView($code);
                if (! ($account['exists'] ?? false)) {
                    $fail('account_code', "{$code} is not in the chart of accounts.");
                }
                if ($problem = $this->accountProblem($item, $account)) {
                    $fail('account_code', $problem);
                }

                return ['active' => $active, 'account_code' => $code];

            case 'mpesa':
                $inUse = $value['in_use'] ?? null;
                if (! is_bool($inUse)) {
                    $fail('in_use', 'Answer yes or no.');
                }
                if (! $inUse) {
                    return ['in_use' => false];
                }
                $mode = $value['mode'] ?? null;
                if (! is_string($mode) || ! isset($item['options']['modes'][$mode])) {
                    $fail('mode', 'Say how M-Pesa is operated.');
                }
                if ($mode === 'held_balance') {
                    $code = $value['account_code'] ?? null;
                    if (! is_string($code) || $code === '') {
                        $fail('account_code', 'Choose the asset account that holds the M-Pesa balance.');
                    }
                    $account = $this->accountView($code);
                    if (! ($account['exists'] ?? false)) {
                        $fail('account_code', "{$code} is not in the chart of accounts. An M-Pesa account is not created automatically; it must be added to the chart first.");
                    }
                    if ($problem = $this->accountProblem($item, $account)) {
                        $fail('account_code', $problem);
                    }

                    return ['in_use' => true, 'mode' => $mode, 'account_code' => $code];
                }
                $source = $value['settlement_source'] ?? null;
                if (! is_string($source) || ! isset($item['options']['settlement_sources'][$source])) {
                    $fail('settlement_source', 'Choose the bank account M-Pesa settles to.');
                }

                return ['in_use' => true, 'mode' => $mode, 'settlement_source' => $source];

            case 'card':
                $inUse = $value['in_use'] ?? null;
                if (! is_bool($inUse)) {
                    $fail('in_use', 'Answer yes or no.');
                }
                if (! $inUse) {
                    return ['in_use' => false];
                }
                $label = trim((string) ($value['label'] ?? ''));
                if ($label === '' || mb_strlen($label) > 60) {
                    $fail('label', 'Give the card a short name, for example "Operations card".');
                }
                // A card number has no place here, in any field.
                if (preg_match('/\d[\d\s-]{10,}\d/', $label)) {
                    $fail('label', 'Do not enter the card number. Use a name, and optionally the last four digits below.');
                }
                $source = $value['settlement_source'] ?? null;
                if (! is_string($source) || ! isset($item['options']['settlement_sources'][$source])) {
                    $fail('settlement_source', 'Choose the bank account the card is drawn on.');
                }
                $lastFour = $value['last_four'] ?? null;
                if ($lastFour !== null && $lastFour !== '' && ! (is_string($lastFour) && preg_match('/^\d{4}$/', $lastFour))) {
                    $fail('last_four', 'Enter only the last four digits, or leave this empty.');
                }
                $custodian = $value['custodian_role'] ?? null;
                if ($custodian !== null && $custodian !== '' && ! in_array($custodian, $item['options']['custodian_roles'], true)) {
                    $fail('custodian_role', 'Choose a role from the list.');
                }

                return ['in_use' => true, 'label' => $label, 'settlement_source' => $source,
                    'last_four' => $lastFour ?: null, 'custodian_role' => $custodian ?: null];

            case 'verification':
                // The server records what it verified: the figures as they stand now.
                return ['verified' => true, 'snapshot' => $item['current']];
        }

        $fail('value', 'This item cannot be configured here.');
    }

    /** Rules that only make sense between two settings. Checked when the other one is known. */
    private function crossCheck(array $item, int|float $amount, array $others, \Closure $fail): void
    {
        $other = fn (string $key) => ($others["setting.{$key}"]['not_applicable'] ?? true) ? null : ($others["setting.{$key}"]['amount'] ?? null);
        $rules = [
            'purchase_order_auto_approval_limit' => ['purchase_order_senior_approval_threshold', '<', 'The auto-approval limit must be below the senior-approval threshold (%s), or one order would be both waved through and escalated.'],
            'purchase_order_senior_approval_threshold' => ['purchase_order_auto_approval_limit', '>', 'The senior-approval threshold must be above the auto-approval limit (%s).'],
            'petty_cash_critical_balance_threshold' => ['petty_cash_low_balance_threshold', '<', 'The critical balance must be below the low-balance alert (%s).'],
            'petty_cash_low_balance_threshold' => ['petty_cash_critical_balance_threshold', '>', 'The low-balance alert must be above the critical balance (%s).'],
            'petty_cash_surrender_due_soon_days' => ['petty_cash_surrender_due_days', '<', 'The due-soon window must be shorter than the surrender deadline (%s days).'],
            'petty_cash_surrender_due_days' => ['petty_cash_surrender_due_soon_days', '>', 'The surrender deadline must be longer than the due-soon window (%s days).'],
            'margin_escalation_percent' => ['margin_warning_percent', '<=', 'The escalation margin cannot be above the warning margin (%s%%).'],
            'margin_warning_percent' => ['margin_escalation_percent', '>=', 'The warning margin cannot be below the escalation margin (%s%%).'],
        ];
        [$otherKey, $operator, $message] = $rules[$item['setting'] ?? ''] ?? [null, null, null];
        $against = $otherKey ? $other($otherKey) : null;
        if ($against === null) {
            return;
        }
        $holds = match ($operator) {
            '<' => $amount < $against, '>' => $amount > $against, '<=' => $amount <= $against, '>=' => $amount >= $against,
        };
        if (! $holds) {
            $fail('amount', sprintf($message, rtrim(rtrim(number_format((float) $against, 2), '0'), '.')));
        }
    }

    // ---- Plain language ------------------------------------------------------

    /** A value as one sentence a Finance user would say. */
    public function describe(array $item, ?array $value): string
    {
        if ($value === null) {
            return 'Not decided';
        }
        switch ($item['type']) {
            case 'choice':
                return $item['options'][$value['choice'] ?? '']['label'] ?? 'Not decided';
            case 'account':
                $account = $this->accountView($value['account_code'] ?? null);

                return trim(($account['code'] ?? '').' · '.($account['name'] ?? 'unknown account')).(($account['exists'] ?? true) ? '' : ' (created at cutover)');
            case 'classification':
                return (self::ACCOUNT_TYPES[$value['account_type'] ?? ''] ?? 'Unclassified').', '.($value['normal_balance'] ?? '?').' balance';
            case 'number':
                if ($value['not_applicable'] ?? false) {
                    return 'No limit set (this control stays off)';
                }
                $amount = $value['amount'] ?? null;
                if ($amount === null) {
                    return 'Not set';
                }

                return match ($item['kind']) {
                    'amount' => 'KES '.number_format((float) $amount, 2),
                    'percent' => rtrim(rtrim(number_format((float) $amount, 2), '0'), '.').'%',
                    'day_of_month' => 'Day '.(int) $amount.' of the following month',
                    default => (int) $amount.' '.$item['unit'],
                };
            case 'bank':
                $account = $this->accountView($value['account_code'] ?? null);

                return (($value['active'] ?? false) ? 'In use' : 'Not in use').' · '.trim(($account['code'] ?? '').' '.($account['name'] ?? ''));
            case 'mpesa':
                if (! ($value['in_use'] ?? false)) {
                    return 'WNG does not use M-Pesa';
                }
                if (($value['mode'] ?? null) === 'held_balance') {
                    $account = $this->accountView($value['account_code'] ?? null);

                    return 'A balance WNG holds, in '.trim(($account['code'] ?? '').' '.($account['name'] ?? ''));
                }

                return 'A channel settling to '.($item['options']['settlement_sources'][$value['settlement_source'] ?? '']['label'] ?? 'a bank account');
            case 'card':
                if (! ($value['in_use'] ?? false)) {
                    return 'WNG does not operate a company card';
                }

                return ($value['label'] ?? 'Card').(($value['last_four'] ?? null) ? ' (•••• '.$value['last_four'].')' : '')
                    .', drawn on '.($item['options']['settlement_sources'][$value['settlement_source'] ?? '']['label'] ?? 'a bank account');
            case 'verification':
                $s = $value['snapshot'] ?? [];

                return 'Verified at '.rtrim(rtrim(number_format((float) ($s['rate_percent'] ?? 0), 3), '0'), '.').'%'
                    .(($s['account_code'] ?? null) ? ", posting to {$s['account_code']}" : ', no ledger account');
        }

        return 'Recorded';
    }

    /**
     * Whether what was approved is still what the system holds. A paying account
     * edited on its own screen, or a tax rate changed in the table, after the
     * approval means the approval no longer describes reality.
     */
    public function inSync(array $item, array $active): bool
    {
        $current = $item['current'] ?? null;

        return match ($item['type']) {
            'verification' => ($active['snapshot'] ?? null) == $current,
            'bank' => $current !== null && (bool) $current['active'] === (bool) ($active['active'] ?? false) && $current['account_code'] === ($active['account_code'] ?? null),
            'mpesa', 'card' => $current === null ? ! ($active['in_use'] ?? false) : (bool) $current['active'] === (bool) ($active['in_use'] ?? false),
            default => true,
        };
    }
}
