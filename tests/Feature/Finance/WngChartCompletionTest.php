<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Report 54: WNG keeps its chart, and the accounts it lacks are created by
 * `finance:complete-chart` from database/finance/wng-chart-profile.json.
 *
 * The fixture is WNG's real chart (120 QuickBooks accounts plus the 3 the
 * target migrations insert), so the duplicate and conflict checks run against
 * real names.
 */
class WngChartCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_ACCOUNTS = 29;

    protected function setUp(): void
    {
        parent::setUp();
        FinanceChartProfile::flush();
        $this->wngChart();
    }

    private function wngChart(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('expense_codes')->update(['default_debit_account_id' => null]);
        DB::table('chart_of_accounts')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/wng_chart_of_accounts.json')), true);
        foreach ($fixture['accounts'] as $a) {
            DB::table('chart_of_accounts')->insert($a + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function activateProfile(string $policy = FinanceChartProfile::WIP_CAPITALISE): void
    {
        config([
            'finance_accounts.profile' => 'wng',
            'finance_accounts.wip_policy' => $policy,
            'finance_accounts.map' => FinanceChartProfile::map('wng', $policy),
            'finance_accounts.payment_sources' => FinanceChartProfile::paymentSources('wng'),
        ]);
    }

    private function complete(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('finance:complete-chart', ['--profile' => 'wng'] + $options);
    }

    private function database(): string
    {
        return (string) DB::selectOne('SELECT DATABASE() AS db')->db;
    }

    private function execute(): \Illuminate\Testing\PendingCommand
    {
        return $this->complete(['--execute' => true, '--confirm' => $this->database()]);
    }

    /** Every column of every existing account, to prove none is modified. */
    private function snapshot(): array
    {
        return DB::table('chart_of_accounts')->orderBy('id')->get()
            ->map(fn ($a) => array_diff_key((array) $a, ['created_at' => 0, 'updated_at' => 0]))->all();
    }

    public function test_on_wngs_chart_nothing_resolves_until_the_chart_is_completed(): void
    {
        $this->activateProfile();
        $unresolved = array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved']));

        // WNG's chart already carries 24 of the 37; the missing ones are exactly the accounts the profile creates.
        $this->assertEqualsCanonicalizing([
            'input_vat', 'inventory', 'output_vat', 'wht_payable', 'paye_payable', 'statutory_payable', 'accrued_expenses',
            'client_deposits', 'project_revenue', 'cos_equipment_site', 'cos_project_utilities', 'cos_venue_statutory',
            'wip_direct_materials', 'wip_direct_labour', 'wip_subcontractors', 'wip_transport_logistics', 'wip_equipment_site',
            'wip_project_utilities', 'wip_project_facilitation', 'wip_venue_statutory', 'wip_rework_warranty',
        ], $unresolved);
    }

    public function test_a_dry_run_writes_nothing_and_reports_every_function_resolving(): void
    {
        $before = $this->snapshot();

        $this->complete()->expectsOutputToContain('DRY RUN')->expectsOutputToContain('Functions resolved: 37 / 37')->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_execute_creates_only_the_missing_accounts_and_never_touches_an_existing_one(): void
    {
        $before = $this->snapshot();

        $this->execute()->expectsOutputToContain('Functions resolved: 37 / 37')->assertSuccessful();

        $this->assertSame(123 + self::NEW_ACCOUNTS, DB::table('chart_of_accounts')->count());
        $existing = DB::table('chart_of_accounts')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()
            ->map(fn ($a) => array_diff_key((array) $a, ['created_at' => 0, 'updated_at' => 0]))->all();
        $this->assertSame($before, $existing, 'Existing WNG accounts keep their ids and every column.');

        $this->activateProfile();
        $unresolved = array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved']));
        $this->assertSame([], $unresolved, '37/37 posting functions resolve on the completed chart.');
    }

    public function test_a_second_run_creates_nothing(): void
    {
        $this->execute()->assertSuccessful();
        $after = $this->snapshot();

        $this->execute()->assertSuccessful();

        $this->assertSame($after, $this->snapshot(), 'Idempotent: a rerun changes nothing.');
    }

    public function test_new_accounts_carry_their_type_balance_and_hierarchy(): void
    {
        $this->execute()->assertSuccessful();
        $a = fn (string $code) => DB::table('chart_of_accounts')->where('code', $code)->first();
        $parent = fn (string $code) => DB::table('chart_of_accounts')->where('id', $a($code)->parent_id)->value('code');

        // Headers take no postings; their children do.
        foreach (['SAL-001', 'PL-001', 'WIP-001', 'PRE-001'] as $header) {
            $this->assertFalse((bool) $a($header)->is_postable, "{$header} is a non-postable header");
        }
        $this->assertSame('SAL-001', $parent('SAL-002'));
        $this->assertSame('PL-001', $parent('PL-002'));
        $this->assertSame('WIP-001', $parent('WIP-003'));
        $this->assertSame('COS-002', $parent('COS-021'), 'new cost-of-sales accounts sit under WNG\'s own Cost of Sales');

        $this->assertSame(['revenue', 'revenue', 'credit'], [$a('SAL-002')->category, $a('SAL-002')->account_type, $a('SAL-002')->normal_balance]);
        $this->assertSame(['liability', 'balance_sheet', 'credit'], [$a('VAT-001')->category, $a('VAT-001')->account_type, $a('VAT-001')->normal_balance]);
        $this->assertSame(['asset', 'balance_sheet', 'debit'], [$a('VAT-002')->category, $a('VAT-002')->account_type, $a('VAT-002')->normal_balance]);
        $this->assertSame(['asset', 'balance_sheet', 'debit'], [$a('WIP-002')->category, $a('WIP-002')->account_type, $a('WIP-002')->normal_balance]);
        $this->assertSame(['expense', 'direct_cost', 'debit'], [$a('COS-022')->category, $a('COS-022')->account_type, $a('COS-022')->normal_balance]);
    }

    public function test_every_new_account_is_classified_consistently_with_its_category(): void
    {
        $profile = FinanceChartProfile::load('wng');
        $this->assertCount(self::NEW_ACCOUNTS, $profile['new_accounts']);
        foreach ($profile['new_accounts'] as $spec) {
            [$types, $balance] = match ($spec['category']) {
                'asset' => [['balance_sheet'], 'debit'],
                'liability', 'equity' => [['balance_sheet'], 'credit'],
                'revenue' => [['revenue'], 'credit'],
                'expense' => [['direct_cost', 'opex', 'overhead'], 'debit'],
            };
            $this->assertContains($spec['account_type'], $types, "{$spec['code']} type");
            $this->assertSame($balance, $spec['normal_balance'], "{$spec['code']} normal balance");
        }
    }

    public function test_an_existing_code_with_a_different_definition_is_refused_and_nothing_is_written(): void
    {
        DB::table('chart_of_accounts')->insert(['code' => 'VAT-001', 'name' => 'VAT Control', 'category' => 'liability',
            'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->snapshot();

        $this->execute()->expectsOutputToContain('REFUSED')->expectsOutputToContain('Existing accounts are never overwritten')->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'no account created, none modified');
    }

    public function test_an_account_already_kept_under_another_code_is_refused_as_a_duplicate(): void
    {
        // The accountant adds QuickBooks' "Inventory Asset" under their own code first.
        DB::table('chart_of_accounts')->insert(['code' => 'INVA-01', 'name' => 'Inventory Asset', 'category' => 'asset',
            'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->execute()->expectsOutputToContain('duplicates existing account INVA-01')->assertFailed();

        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'IA-001')->exists());
        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'SAL-002')->exists(), 'the whole run refuses; nothing is written');
    }

    public function test_execution_needs_the_database_typed_and_never_runs_against_the_live_source(): void
    {
        $this->complete(['--execute' => true])->expectsOutputToContain('--confirm=')->assertFailed();
        $this->complete(['--execute' => true, '--confirm' => 'some_other_db'])->assertFailed();

        config(['source_migration.live_source_databases' => [$this->database()]]);
        $this->complete()->expectsOutputToContain('LIVE SOURCE')->assertFailed();

        config(['source_migration.live_source_databases' => [], 'source_migration.live_target_databases' => [$this->database()]]);
        $this->execute()->expectsOutputToContain('requires --cutover')->assertFailed();

        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'SAL-002')->exists());
    }

    public function test_the_wip_timing_policy_is_a_switch_not_a_chart_fact(): void
    {
        $capitalise = FinanceChartProfile::map('wng', FinanceChartProfile::WIP_CAPITALISE);
        $this->assertSame('WIP-002', $capitalise['1211']);
        $this->assertSame('WIP-003', $capitalise['1212']);

        $expensed = FinanceChartProfile::map('wng', FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE);
        $this->assertSame('COS-008', $expensed['1211'], 'expense on capture: WIP maps onto its cost-of-sales twin');
        $this->assertSame('PE-007', $expensed['1212']);
        foreach (['1211' => '5100', '1213' => '5300', '1215' => '5500', '1219' => '5900'] as $wip => $cos) {
            $this->assertSame($expensed[$cos], $expensed[$wip], "{$wip} and {$cos} are one account, so the release has nothing to move");
        }

        // An unknown policy is never guessed: no WIP function is mapped, and readiness says why.
        $unknown = FinanceChartProfile::map('wng', 'on_a_whim');
        $this->assertArrayNotHasKey('1211', $unknown);
        $this->assertSame('AR-001', $unknown['1100'], 'the rest of the map is unaffected');
        $this->assertNotEmpty(FinanceChartProfile::problems('wng', 'on_a_whim'));
        $this->assertSame([], FinanceChartProfile::problems('wng', null));
    }

    public function test_an_unreadable_profile_maps_nothing_and_readiness_names_it(): void
    {
        $this->assertSame([], FinanceChartProfile::map('no-such-company'));
        $this->assertSame([], FinanceChartProfile::map('../etc/passwd'));
        $this->assertStringContainsString('cannot be read', FinanceChartProfile::problems('no-such-company', null)[0]);
        $this->assertSame([], FinanceChartProfile::map(null), 'no profile: the reference chart, untouched');
    }

    public function test_every_bank_links_to_its_own_account_not_the_generic_bank(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        DB::table('payment_sources')->update(['gl_account_id' => null]);

        $this->seed(PaymentSourceSeeder::class);

        $linked = DB::table('payment_sources as ps')->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ps.gl_account_id')
            ->select('ps.code as source', 'coa.code as account')->pluck('account', 'source')->all();
        $this->assertSame('EQB-001', $linked['BANK-MAIN']);
        $this->assertSame('NCBA-001', $linked['BANK-ALT']);
        $this->assertSame('STB-001', $linked['BANK-STANBIC'], 'names the generic 1010, which the map sends to Equity');
        $this->assertSame('KCB-001', $linked['BANK-KCB']);
        $this->assertSame('FMB-001', $linked['BANK-FAMILY']);
        $this->assertSame('PETTY-001', $linked['PC-MAIN']);
        $this->assertSame('AP-001', $linked['AP']);
        $this->assertNull($linked['CARD'], 'left unlinked for Finance, never defaulted to a bank');
        $this->assertNull($linked['MPESA']);

        // A link Finance sets is kept on the next seed.
        DB::table('payment_sources')->where('code', 'CARD')->update(['gl_account_id' => DB::table('chart_of_accounts')->where('code', 'KCB-001')->value('id')]);
        $this->seed(PaymentSourceSeeder::class);
        $this->assertSame('KCB-001', DB::table('chart_of_accounts')->where('id', DB::table('payment_sources')->where('code', 'CARD')->value('gl_account_id'))->value('code'));
    }

    public function test_after_completion_tax_links_and_expense_codes_resolve_to_wng_accounts(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        DB::table('vat_treatments')->update(['gl_account_id' => null]);
        DB::table('wht_categories')->update(['gl_account_id' => null]);

        $this->seed(FinanceTaxSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $code = fn ($id) => DB::table('chart_of_accounts')->where('id', $id)->value('code');
        $this->assertContains('VAT-002', DB::table('vat_treatments')->whereNotNull('gl_account_id')->pluck('gl_account_id')->map($code)->all());
        $this->assertContains('WHT-001', DB::table('wht_categories')->whereNotNull('gl_account_id')->pluck('gl_account_id')->map($code)->all());

        $byReference = DB::table('expense_codes')->whereNotNull('default_debit_account_id')->get()
            ->mapWithKeys(fn ($ec) => [substr((string) $ec->default_debit_gl, 0, 4) => $code($ec->default_debit_account_id)]);
        $this->assertSame('WIP-002', $byReference['1211'], 'job materials capitalise to WIP under the default policy');
        $this->assertSame('OPE-030', $byReference['7150'], 'WNG\'s Stationery & Printing, not the ERP-added duplicate 7150');
        $this->assertSame('PRE-003', $byReference['1320']);

        // The only anchored catalogue code left unresolved is the loan (no evidence WNG borrows).
        $this->assertSame(['2300'], DB::table('expense_codes')->whereNull('default_debit_account_id')
            ->where('default_debit_gl', 'REGEXP', '^[0-9]{4}')->pluck('default_debit_gl')->map(fn ($g) => substr($g, 0, 4))->unique()->values()->all());
        $this->assertSame(0, DB::table('expense_codes as ec')->join('chart_of_accounts as coa', 'coa.id', '=', 'ec.default_debit_account_id')
            ->where('ec.is_active', true)->where(fn ($q) => $q->where('coa.is_postable', false)->orWhere('coa.is_active', false))->count(),
            'no active code posts to a header');
    }

    public function test_the_uncoded_cost_fallback_lands_on_wngs_materials_wip(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();

        $resolve = new ReflectionMethod(JournalPostingService::class, 'accountByCode');
        $id = $resolve->invoke(app(JournalPostingService::class), FinanceAccountFunctions::UNCODED_COST_FALLBACK);

        $this->assertSame('WIP-002', DB::table('chart_of_accounts')->where('id', $id)->value('code'));
    }

    public function test_readiness_reports_the_profile_and_all_functions_resolving(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Accounts');

        $data = $this->actingAs($user, 'sanctum')->getJson('/api/finance/readiness')->assertOk()->json('data');
        $checks = collect($data['checks'])->keyBy('key');

        $this->assertTrue($checks['required_accounts']['ready'], $checks['required_accounts']['message']);
        $this->assertTrue($checks['chart_profile']['ready']);
        $this->assertStringContainsString("'wng' is active", $checks['chart_profile']['message']);
        $this->assertCount(37, array_filter($data['account_functions'], fn ($f) => $f['resolved']));
    }
}
