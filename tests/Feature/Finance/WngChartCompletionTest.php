<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use App\Modules\Finance\Support\FinanceReadiness;
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

        // An unknown policy is never guessed: every WIP function maps to a marker that is no account, and readiness says why.
        $unknown = FinanceChartProfile::map('wng', 'on_a_whim');
        $this->assertSame(FinanceChartProfile::WIP_POLICY_REQUIRED, $unknown['1211']);
        $this->assertSame('AR-001', $unknown['1100'], 'the rest of the map is unaffected');
        $this->assertStringStartsWith('POLICY REQUIRED', FinanceChartProfile::problems('wng', 'on_a_whim')[0]);
        $this->assertSame([], FinanceChartProfile::problems('wng', FinanceChartProfile::WIP_CAPITALISE));
        $this->assertSame([], FinanceChartProfile::problems('wng', FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE));
    }

    // ---- Report 74A: the WIP policy is never assumed ----

    /** The profile is active but nobody has set FINANCE_WIP_POLICY. */
    private function activateProfileWithoutAPolicy(): void
    {
        config([
            'finance_accounts.profile' => 'wng',
            'finance_accounts.wip_policy' => null,
            'finance_accounts.map' => FinanceChartProfile::map('wng', null),
            'finance_accounts.payment_sources' => FinanceChartProfile::paymentSources('wng'),
        ]);
    }

    public function test_with_no_wip_policy_set_the_profile_default_is_never_applied(): void
    {
        $this->assertSame('capitalise', FinanceChartProfile::suggestedWipPolicy('wng'), 'the profile does suggest one');
        $map = FinanceChartProfile::map('wng', null);

        foreach (FinanceChartProfile::wipFunctions() as $function) {
            $reference = FinanceAccountFunctions::all()[$function]['code'];
            $this->assertSame(FinanceChartProfile::WIP_POLICY_REQUIRED, $map[$reference], "{$function} is not given the suggested account");
        }
        $this->assertCount(9, FinanceChartProfile::wipFunctions());
        $this->assertNotContains('WIP-002', $map, 'capitalise was not chosen');
        $this->assertSame('COS-008', $map['5100'], 'and expense-on-capture was not chosen either: cost of sales keeps its own mapping only');
        $this->assertSame('AR-001', $map['1100'], 'nothing else depends on the policy');
        $this->assertStringStartsWith('POLICY REQUIRED', FinanceChartProfile::problems('wng', null)[0]);
    }

    public function test_with_no_wip_policy_project_cost_cannot_post_and_readiness_says_policy_required(): void
    {
        $this->execute()->assertSuccessful();   // the WIP accounts exist: only the decision is missing
        $this->activateProfile();
        $this->seed(ExpenseCodeSeeder::class);  // codes linked while a policy was in force
        $this->seed(RoleAndPermissionSeeder::class);
        $this->activateProfileWithoutAPolicy();

        // The nine WIP functions resolve to nothing; the other 28 are untouched.
        $unresolved = array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved']));
        $this->assertEqualsCanonicalizing(FinanceChartProfile::wipFunctions(), $unresolved);

        $posting = app(JournalPostingService::class);
        $account = new ReflectionMethod(JournalPostingService::class, 'accountByCode');
        $this->assertNull($account->invoke($posting, FinanceAccountFunctions::UNCODED_COST_FALLBACK), 'no account, so no posting');
        $this->assertNotNull($account->invoke($posting, FinanceAccountFunctions::ACCOUNTS_RECEIVABLE));

        // A job-cost code still carries the account an earlier policy gave it. Posting through it is refused all the same.
        $guard = new ReflectionMethod(JournalPostingService::class, 'assertWipPolicy');
        $jobCode = DB::table('expense_codes')->where('default_debit_gl', 'like', '1211%')->whereNotNull('default_debit_account_id')->first();
        $this->assertNotNull($jobCode);
        try {
            $guard->invoke($posting, $jobCode->default_debit_gl, 'cost line CL-TEST');
            $this->fail('A project cost posted with no WIP policy.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('POLICY REQUIRED', $e->getMessage());
            $this->assertStringContainsString('cost line CL-TEST', $e->getMessage());
        }
        // An office overhead is not the WIP policy's to decide.
        $guard->invoke($posting, '7100 Office Rent & Electricity', 'cost line CL-OFFICE');

        // The release that moves WIP to cost of sales is refused too, rather than quietly releasing nothing.
        try {
            app(\App\Modules\Finance\Services\WorkInProgressReleaseService::class)
                ->releaseForInvoice(new \App\Modules\Finance\Models\ProjectInvoice(['project_enquiry_id' => 1, 'invoice_number' => 'INV-TEST']));
            $this->fail('Work in progress was released with no WIP policy.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('POLICY REQUIRED', $e->getMessage());
        }

        // Readiness, on the command line and on the setup screen, names the decision rather than a missing account.
        $cli = collect(app(FinanceReadiness::class)->checks())->keyBy('check');
        $this->assertFalse($cli['Chart profile']['ok']);
        $this->assertStringStartsWith('POLICY REQUIRED', $cli['Chart profile']['detail']);
        $this->assertStringNotContainsString('complete-chart', (string) $cli['Chart profile']['fix']);
        $this->assertFalse(app(FinanceReadiness::class)->passes());

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Accounts');
        $checks = collect($this->actingAs($user, 'sanctum')->getJson('/api/finance/readiness')->assertOk()->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['chart_profile']['ready']);
        $this->assertStringContainsString('POLICY REQUIRED', $checks['chart_profile']['message']);
        $this->assertFalse($checks['required_accounts']['ready']);

        // With the decision made (either one), all of it clears.
        foreach ([FinanceChartProfile::WIP_CAPITALISE, FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE] as $policy) {
            $this->activateProfile($policy);
            $this->assertTrue(collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Chart profile')['ok'], $policy);
            $guard->invoke($posting, $jobCode->default_debit_gl, 'cost line CL-TEST');
        }
    }

    public function test_seeding_the_catalogue_with_no_wip_policy_links_no_job_cost_code(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfileWithoutAPolicy();

        $this->seed(ExpenseCodeSeeder::class);

        $jobCodes = DB::table('expense_codes')->where('default_debit_gl', 'REGEXP', '^121[1-9] ')->get();
        $this->assertGreaterThan(50, $jobCodes->count());
        $this->assertSame(0, $jobCodes->whereNotNull('default_debit_account_id')->count(), 'no WIP account, and no cost-of-sales account, was chosen for them');
        $this->assertSame(0, $jobCodes->where('is_active', true)->count());
        $this->assertTrue((bool) DB::table('expense_codes')->where('code', 'OE-FIN-001')->value('is_active'), 'codes the policy does not govern are linked as usual');
    }

    public function test_the_planning_tool_labels_the_suggested_policy_and_never_cuts_over_on_it(): void
    {
        $before = $this->snapshot();
        config(['source_migration.live_target_databases' => [$this->database()], 'finance_accounts.wip_policy' => null]);

        $this->complete()->expectsOutputToContain('WIP policy: capitalise, DEFAULT / NOT APPROVED FOR PRODUCTION')->assertSuccessful();
        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--cutover' => true])
            ->expectsOutputToContain('POLICY REQUIRED')->assertFailed();
        $this->assertSame($before, $this->snapshot(), 'a cutover is not run on a default');

        config(['finance_accounts.wip_policy' => FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE]);
        $this->complete()->expectsOutputToContain('WIP policy: expense_on_capture, configured (FINANCE_WIP_POLICY)')->assertSuccessful();
    }

    // ---- Report 74A: what chart is this, and the two-chart stop ----

    private function identity(array $options = []): array
    {
        $dir = sys_get_temp_dir().'/r74a-'.uniqid();
        $this->complete(['--output' => $dir] + $options)->run();
        $report = json_decode(file_get_contents("{$dir}/chart_completion.json"), true);
        @unlink("{$dir}/chart_completion.json");
        @rmdir($dir);

        return $report;
    }

    public function test_wngs_own_chart_is_identified_as_such(): void
    {
        $this->complete()->expectsOutputToContain('Target chart identity: WNG_CHART_ONLY')->assertSuccessful();

        $identity = $this->identity()['target_identity'];
        $this->assertSame('WNG_CHART_ONLY', $identity['identity']);
        $this->assertSame(['total' => 123, 'active' => 123, 'postable' => 123, 'company_coded' => 120, 'numeric' => 3, 'reference' => 3,
            'reference_acknowledged' => 3, 'reference_unnamed' => 0, 'unexplained_numeric' => 0, 'other_coded' => 0,
            'profile_existing_accounts' => 29, 'profile_existing_missing' => 0], $identity['counts']);
        $this->assertSame([], $identity['duplicate_names']);
        $this->assertSame([], $identity['reference_accounts_with_postings']);
    }

    public function test_two_charts_in_one_database_stop_the_run_and_nothing_is_merged_moved_renamed_or_adopted(): void
    {
        // The reference chart seeded beside WNG's: under names that match, and names that do not.
        $this->account('1010', 'Bank – Main Account', 'asset');
        $this->account('1100', 'Accounts Receivable', 'asset');
        $this->account('3900', 'Opening Balance Equity', 'equity');
        $this->outputVat();
        $period = DB::table('accounting_periods')->value('id');
        $entry = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-74A', 'posting_date' => now()->toDateString(), 'accounting_period_id' => $period,
            'description' => 'test', 'total_debit' => 10, 'total_credit' => 10, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['1010' => 'debit', '2110' => 'credit'] as $code => $side) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => DB::table('chart_of_accounts')->where('code', $code)->value('id'),
                'entry_type' => $side, 'amount' => 10, 'base_amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->snapshot();
        $lines = DB::table('journal_lines')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();

        $this->complete()->expectsOutputToContain('Target chart identity: TWO_CHART_STATE')
            ->expectsOutputToContain('ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED')->assertFailed();
        $this->execute()->expectsOutputToContain('REFUSED')->expectsOutputToContain('ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED')->assertFailed();
        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--classify-existing' => true, '--disable-unlinked-sources' => true])->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'no account deleted, merged, renamed, created or classified');
        $this->assertSame($lines, DB::table('journal_lines')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(), 'no journal line moved or remapped');
        $this->assertSame('VAT-001', FinanceChartProfile::map('wng', 'capitalise')['2110'], 'no reference account adopted');

        $identity = $this->identity()['target_identity'];
        $this->assertSame('TWO_CHART_STATE', $identity['identity']);
        $this->assertSame(['1010', '1100', '2110', '3900'], collect($identity['reference_accounts_not_named_by_profile'])->sort()->values()->all());
        $this->assertSame(['1010' => 1, '2110' => 1], $identity['reference_accounts_with_postings']);
        $this->assertSame(['OBE-001', '3900'], $identity['duplicate_names']['opening balance equity']);
        $this->assertArrayNotHasKey('accounts receivable', $identity['duplicate_names'], '1100 is found by its code: its name differs from AR-001\'s');

        // Declaring one reuse does not make the other three reference accounts WNG's.
        $profile = $this->variant(self::REUSE_OUTPUT_VAT);
        $this->artisan('finance:complete-chart', ['--profile' => $profile, '--execute' => true, '--confirm' => $this->database()])
            ->expectsOutputToContain('TWO CHARTS DETECTED')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_chart_that_is_neither_is_sent_for_manual_review(): void
    {
        $this->account('9999', 'Suspense', 'asset');
        $before = $this->snapshot();

        $this->complete()->expectsOutputToContain('Target chart identity: OTHER / MANUAL_REVIEW_REQUIRED')->assertFailed();
        $this->execute()->expectsOutputToContain('MANUAL REVIEW REQUIRED')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['9999'], $this->identity()['target_identity']['unexplained_numeric_accounts']);

        // And so is a database that does not hold the company's accounts at all.
        DB::table('chart_of_accounts')->where('code', '9999')->delete();
        DB::table('chart_of_accounts')->whereIn('code', ['EQB-001', 'AR-001'])->delete();
        $identity = $this->identity()['target_identity'];
        $this->assertSame('OTHER / MANUAL_REVIEW_REQUIRED', $identity['identity']);
        $this->assertEqualsCanonicalizing(['EQB-001', 'AR-001'], $identity['profile_accounts_missing']);
    }

    // ---- Report 74A: an expense code resolves only from the account its catalogue row designates ----

    public function test_under_the_full_profile_the_codes_that_only_mention_an_account_stay_unresolved(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        $this->seed(ExpenseCodeSeeder::class);
        $code = fn (string $code) => DB::table('expense_codes')->where('code', $code)->first();

        // NE-018 mentions 2200 as its CREDIT; NE-023 names the range 5100–5800. Both codes are mapped by WNG's profile.
        $this->assertSame('CD-001', FinanceChartProfile::map('wng', 'capitalise')['2200']);
        $this->assertSame('COS-008', FinanceChartProfile::map('wng', 'capitalise')['5100']);
        foreach (['NE-018' => 'Bank / Cash (credit is 2200 Client Deposits)', 'NE-023' => 'Relevant 5100–5800 Cost of Sales account'] as $indirect => $text) {
            $this->assertSame($text, $code($indirect)->default_debit_gl, 'the catalogue text is unchanged');
            $this->assertNull($code($indirect)->default_debit_account_id, "{$indirect} takes no account from a code its text only mentions");
            $this->assertFalse((bool) $code($indirect)->is_active, "{$indirect} stays out of capture until its own workflow supplies the account");
        }
        // No code whose text does not lead with an account is linked or active.
        $this->assertSame(0, DB::table('expense_codes')->where('default_debit_gl', 'NOT REGEXP', '^[0-9]{4} ')
            ->where(fn ($q) => $q->whereNotNull('default_debit_account_id')->orWhere('is_active', true))->count());

        // Every code that DOES designate an account still resolves: 101 of them, less the loan WNG's profile leaves off.
        $designated = DB::table('expense_codes')->where('default_debit_gl', 'REGEXP', '^[0-9]{4} ')->get();
        $this->assertCount(101, $designated);
        $this->assertSame(['NE-016'], $designated->whereNull('default_debit_account_id')->pluck('code')->values()->all());
        $this->assertSame(100, $designated->where('is_active', true)->count());
        $this->assertSame('WIP-002', DB::table('chart_of_accounts')->where('id', $code('DM-EL-001')->default_debit_account_id)->value('code'));
        $this->assertSame('FIN-003', DB::table('chart_of_accounts')->where('id', $code('OE-FIN-001')->default_debit_account_id)->value('code'));

        $this->assertTrue(collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Expense-code accounts')['ok']);
    }

    public function test_a_designated_account_missing_from_the_chart_fails_readiness_by_name(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        DB::table('chart_of_accounts')->where('code', 'OPE-022')->delete();   // WNG's rent account, which reference 7100 maps to

        $this->seed(ExpenseCodeSeeder::class);

        $rent = DB::table('expense_codes')->where('default_debit_gl', 'like', '7100%')->first();
        $this->assertNull($rent->default_debit_account_id, 'not redirected to some other account');
        $this->assertFalse((bool) $rent->is_active);
        $check = collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Expense-code accounts');
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('OPE-022', $check['detail']);
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

    public function test_classification_fills_only_null_columns_and_touches_nothing_else(): void
    {
        $identity = fn () => DB::table('chart_of_accounts')->orderBy('id')->get(['id', 'code', 'name', 'category', 'parent_id', 'is_postable', 'is_active'])
            ->map(fn ($a) => (array) $a)->all();
        $before = $identity();

        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--classify-existing' => true])->assertSuccessful();

        $type = fn (string $code) => DB::table('chart_of_accounts')->where('code', $code)->first(['account_type', 'normal_balance']);
        $this->assertSame(['direct_cost', 'debit'], array_values((array) $type('COS-008')));
        $this->assertSame(['direct_cost', 'debit'], array_values((array) $type('PE-007')), 'the existing direct-labour account');
        $this->assertSame(['overhead', 'debit'], array_values((array) $type('COS-019')));
        $this->assertSame(['opex', 'debit'], array_values((array) $type('PE-006')));
        $this->assertSame(['balance_sheet', 'credit'], array_values((array) $type('AP-001')));
        $this->assertSame(['revenue', 'credit'], array_values((array) $type('RI-001')), 'contra-revenue keeps a credit normal balance so the P&L deducts it');
        foreach (['OPE-026', 'ITX-001', 'LDO-001', 'EQE-001'] as $pending) {
            $this->assertNull($type($pending)->account_type, "{$pending} is left for the accountant");
        }

        // Only account_type / normal_balance changed; new accounts were added after the existing ones.
        $after = array_slice($identity(), 0, count($before));
        $this->assertSame($before, $after, 'code, name, id, parent, postability and active flag are untouched');

        // Idempotent.
        $snapshot = $this->snapshot();
        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--classify-existing' => true])->assertSuccessful();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_an_existing_classification_is_never_overwritten(): void
    {
        DB::table('chart_of_accounts')->where('code', 'OPE-006')->update(['account_type' => 'direct_cost']);
        $before = $this->snapshot();

        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--classify-existing' => true])
            ->expectsOutputToContain('never overwritten')->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'the whole run refuses: nothing created, nothing classified');
    }

    public function test_unlinked_unused_paying_accounts_are_disabled_not_given_an_invented_account(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        DB::table('payment_sources')->update(['gl_account_id' => null, 'is_active' => true]);
        $this->seed(PaymentSourceSeeder::class);

        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--disable-unlinked-sources' => true])->assertSuccessful();

        $state = DB::table('payment_sources')->pluck('is_active', 'code')->map(fn ($v) => (bool) $v)->all();
        $this->assertFalse($state['MPESA']);
        $this->assertFalse($state['CARD']);
        $this->assertTrue($state['BANK-MAIN']);
        $this->assertNull(DB::table('payment_sources')->where('code', 'MPESA')->value('gl_account_id'), 'no account invented');

        // Once Finance links it, re-enabling sticks: the step never disables a linked source.
        DB::table('payment_sources')->where('code', 'CARD')->update(['is_active' => true, 'gl_account_id' => DB::table('chart_of_accounts')->where('code', 'KCB-001')->value('id')]);
        $this->complete(['--execute' => true, '--confirm' => $this->database(), '--disable-unlinked-sources' => true])->assertSuccessful();
        $this->assertTrue((bool) DB::table('payment_sources')->where('code', 'CARD')->value('is_active'));
    }

    public function test_the_unconfigured_loan_code_is_reported_but_does_not_block_readiness(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        $this->seed(ExpenseCodeSeeder::class);
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Accounts');

        $check = collect($this->actingAs($user, 'sanctum')->getJson('/api/finance/readiness')->assertOk()->json('data.checks'))
            ->firstWhere('key', 'expense_code_mapping');

        $this->assertTrue($check['ready'], $check['message']);
        $this->assertStringContainsString('off by design', $check['message']);
        $this->assertStringContainsString('2300', $check['message']);

        $cliCheck = collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Expense-code accounts');
        $this->assertTrue($cliCheck['ok'], $cliCheck['detail']);
        $this->assertStringContainsString('off by design', $cliCheck['detail']);
        $this->assertStringContainsString('2300', $cliCheck['detail']);
        $this->assertFalse((bool) DB::table('expense_codes')->where('default_debit_gl', 'like', '2300%')->value('is_active'), 'the loan code stays inactive');
    }

    // ---- Report 74: explicit reuse of an existing account, and a dry run that reports instead of stopping ----

    /** The WNG profile with some `new_accounts` entries changed, standing in under its own name. */
    private function variant(array $changes): string
    {
        $profile = FinanceChartProfile::load('wng');
        foreach ($profile['new_accounts'] as $i => $spec) {
            $profile['new_accounts'][$i] = ($changes[$spec['code']] ?? []) + $spec;
        }
        FinanceChartProfile::fake('wng-reuse', $profile);

        return 'wng-reuse';
    }

    private function account(string $code, string $name, string $category, array $columns = []): void
    {
        DB::table('chart_of_accounts')->insert($columns + ['code' => $code, 'name' => $name, 'category' => $category,
            'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function outputVat(array $columns = []): void
    {
        $this->account('2110', 'Output VAT Payable', 'liability', $columns + ['account_type' => 'balance_sheet', 'normal_balance' => 'credit']);
    }

    private const REUSE_OUTPUT_VAT = ['VAT-001' => ['reuse_existing' => ['code' => '2110', 'name' => 'Output VAT Payable']]];

    public function test_a_same_named_account_is_never_reused_unless_the_profile_declares_it(): void
    {
        $this->outputVat();
        $before = $this->snapshot();

        $this->complete()->expectsOutputToContain('duplicates existing account 2110')->assertFailed();
        $this->execute()->expectsOutputToContain('REFUSED')->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'nothing created, nothing adopted');
        $this->assertSame('VAT-001', FinanceChartProfile::map('wng')['2110'], 'the mapping still names the proposed account: the name match redirected nothing');
        $this->assertSame([], FinanceChartProfile::reuse('wng'), 'WNG\'s own profile reuses nothing');
    }

    public function test_an_explicitly_declared_existing_account_meets_the_requirement_and_nothing_is_created_for_it(): void
    {
        $this->outputVat();
        $before = $this->snapshot();
        $profile = $this->variant(self::REUSE_OUTPUT_VAT);

        $this->artisan('finance:complete-chart', ['--profile' => $profile])
            ->expectsOutputToContain('reuse 2110')->expectsOutputToContain('Functions resolved: 37 / 37')->assertSuccessful();
        $this->assertSame($before, $this->snapshot(), 'a dry run writes nothing');

        $this->artisan('finance:complete-chart', ['--profile' => $profile, '--execute' => true, '--confirm' => $this->database()])
            ->expectsOutputToContain('Functions resolved: 37 / 37')->assertSuccessful();

        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'VAT-001')->exists(), 'no duplicate account');
        $this->assertSame(count($before) + self::NEW_ACCOUNTS - 1, DB::table('chart_of_accounts')->count());
        $existing = DB::table('chart_of_accounts')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()
            ->map(fn ($a) => array_diff_key((array) $a, ['created_at' => 0, 'updated_at' => 0]))->all();
        $this->assertSame($before, $existing, 'the reused account, like every existing account, keeps its id, code, name and every column');

        // The function now posts to the existing account, under either reading of the profile.
        $this->assertSame('2110', FinanceChartProfile::map($profile)['2110']);
        config(['finance_accounts.map' => FinanceChartProfile::map($profile, FinanceChartProfile::WIP_CAPITALISE)]);
        $resolution = FinanceAccountFunctions::resolution();
        $this->assertSame('2110', $resolution['output_vat']['local_code']);
        $this->assertSame([], array_keys(array_filter($resolution, fn ($f) => ! $f['resolved'])));

        // Idempotent.
        $after = $this->snapshot();
        $this->artisan('finance:complete-chart', ['--profile' => $profile, '--execute' => true, '--confirm' => $this->database()])->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    /** @return array<string, array{0: \Closure, 1: array, 2: string}> */
    public static function unsafeReuse(): array
    {
        $vat = fn (array $columns = []) => fn (self $t) => $t->outputVat($columns);
        $declare = fn (string $code, string $name = 'Output VAT Payable') => ['VAT-001' => ['reuse_existing' => ['code' => $code, 'name' => $name]]];

        return [
            'a code that is not in the chart' => [$vat(), $declare('2119'), 'not in this chart'],
            'a code that is another account' => [$vat(), $declare('AP-001'), 'that is not the account the profile describes'],
            'a declaration with no name to check the code against' => [$vat(), ['VAT-001' => ['reuse_existing' => ['code' => '2110']]], "both 'code' and 'name'"],
            'a non-postable header' => [$vat(['is_postable' => false]), $declare('2110'), 'non-postable header'],
            'an inactive account' => [$vat(['is_active' => false]), $declare('2110'), 'is inactive'],
            'an account of another category' => [fn (self $t) => $t->account('2110', 'Output VAT Payable', 'asset'), $declare('2110'), "category is 'asset'"],
            'an account with the opposite normal balance' => [$vat(['normal_balance' => 'debit']), $declare('2110'), "normal_balance is 'debit'"],
            'an account another requirement already reuses' => [$vat(), $declare('2110') + ['WHT-001' => ['reuse_existing' => ['code' => '2110', 'name' => 'Output VAT Payable']]], 'cannot stand in for two'],
            'an account the profile itself proposes' => [$vat(), $declare('WHT-001', 'Withholding Tax Payable'), 'itself an account this profile proposes'],
            'a requirement that is already in the chart as well' => [fn (self $t) => [$t->outputVat(), $t->account('VAT-001', 'Output VAT Payable (WNG)', 'liability')], $declare('2110'), 'cannot have two accounts'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeReuse')]
    public function test_a_reuse_declaration_that_is_not_exactly_the_declared_account_is_refused(\Closure $chart, array $changes, string $reason): void
    {
        $chart($this);
        $before = $this->snapshot();
        $profile = $this->variant($changes);

        $this->artisan('finance:complete-chart', ['--profile' => $profile])->expectsOutputToContain($reason)->assertFailed();
        $this->artisan('finance:complete-chart', ['--profile' => $profile, '--execute' => true, '--confirm' => $this->database()])
            ->expectsOutputToContain('REFUSED')->expectsOutputToContain($reason)->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'the whole run refuses: nothing created, nothing modified');
    }

    public function test_a_new_account_is_refused_beside_the_reference_account_for_the_same_function_whatever_its_name(): void
    {
        // The reference chart's inventory account, under a name the duplicate-name check cannot see.
        $this->account('1200', 'Raw-material Inventory', 'asset', ['account_type' => 'balance_sheet', 'normal_balance' => 'debit']);
        $before = $this->snapshot();

        $this->execute()->expectsOutputToContain("IA-001 would be a second account for 'inventory'")->assertFailed();
        $this->assertSame($before, $this->snapshot());

        // Declared, it is the inventory account, and the catalogue and posting map follow it.
        $profile = $this->variant(['IA-001' => ['reuse_existing' => ['code' => '1200', 'name' => 'Raw-material Inventory']]]);
        $this->artisan('finance:complete-chart', ['--profile' => $profile, '--execute' => true, '--confirm' => $this->database()])->assertSuccessful();
        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'IA-001')->exists());
        $this->assertSame('1200', FinanceChartProfile::map($profile)[FinanceAccountFunctions::INVENTORY]);
    }

    public function test_a_blocked_dry_run_still_reports_the_whole_plan_and_writes_nothing(): void
    {
        $this->account('INVA-01', 'Inventory Asset', 'asset');
        $before = $this->snapshot();
        $dir = sys_get_temp_dir().'/r74-'.uniqid();

        $this->complete(['--output' => $dir])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Accounts: 28 to create, 0 already present, 0 met by an existing account (declared reuse), 1 in conflict')
            ->expectsOutputToContain('Functions resolved: 36 / 37')
            ->expectsOutputToContain('Classification preview')
            ->expectsOutputToContain('BLOCKED')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot(), 'a dry run never writes, blocked or not');
        $report = json_decode(file_get_contents("{$dir}/chart_completion.json"), true);
        @unlink("{$dir}/chart_completion.json");
        @rmdir($dir);
        $this->assertCount(1, $report['blocking']);
        $this->assertSame('conflict', collect($report['accounts'])->firstWhere('code', 'IA-001')['action']);
        $this->assertSame('CONFLICT', $report['functions']['inventory']['status']);
        $this->assertSame('RESOLVED_EXISTING', $report['functions']['accounts_receivable']['status']);
        $this->assertSame('RESOLVED_PROPOSED_NEW', $report['functions']['output_vat']['status']);
        $this->assertSame(['inventory'], $report['unresolved_functions']);
    }

    public function test_the_classification_preview_shows_before_and_proposed_and_changes_nothing(): void
    {
        $before = $this->snapshot();
        $dir = sys_get_temp_dir().'/r74-'.uniqid();

        // Previewed by default, and again when the flag is given without --execute.
        $this->complete(['--output' => $dir])
            ->expectsOutputToContain('116 account(s) would have a NULL account_type / normal_balance filled; left for the accountant: EQE-001, ITX-001, LDO-001, OPE-026')
            ->assertSuccessful();
        $this->complete(['--classify-existing' => true])->expectsOutputToContain('Existing accounts classified (NULL columns filled): 116 account(s) (dry run)')->assertSuccessful();

        $this->assertSame($before, $this->snapshot(), 'previewing a classification classifies nothing');
        $preview = json_decode(file_get_contents("{$dir}/chart_completion.json"), true)['classification_preview'];
        @unlink("{$dir}/chart_completion.json");
        @rmdir($dir);
        $this->assertCount(116, $preview['would_fill']);
        $materials = collect($preview['would_fill'])->firstWhere('code', 'COS-008');
        $this->assertSame([null, null, 'direct_cost', 'debit'], [$materials['current_account_type'], $materials['current_normal_balance'], $materials['proposed_account_type'], $materials['proposed_normal_balance']]);
        $this->assertSame(['EQE-001', 'ITX-001', 'LDO-001', 'OPE-026'], array_keys($preview['pending_decision']));
        $this->assertSame([], $preview['not_in_profile'], 'every unclassified WNG account is either proposed or named as the accountant\'s');
    }

    public function test_a_declared_reuse_loosens_none_of_the_execution_guards(): void
    {
        $this->outputVat();
        $before = $this->snapshot();
        $profile = $this->variant(self::REUSE_OUTPUT_VAT);
        $run = fn (array $options) => $this->artisan('finance:complete-chart', ['--profile' => $profile] + $options);

        $run(['--execute' => true])->expectsOutputToContain('--confirm=')->assertFailed();
        $run(['--execute' => true, '--confirm' => 'some_other_db'])->expectsOutputToContain('--confirm=')->assertFailed();

        config(['source_migration.live_source_databases' => [$this->database()]]);
        $run(['--execute' => true, '--confirm' => $this->database(), '--cutover' => true])->expectsOutputToContain('LIVE SOURCE')->assertFailed();

        config(['source_migration.live_source_databases' => [], 'source_migration.live_target_databases' => [$this->database()]]);
        $run(['--execute' => true, '--confirm' => $this->database()])->expectsOutputToContain('requires --cutover')->assertFailed();
        $run(['--execute' => true, '--cutover' => true])->expectsOutputToContain('--confirm=')->assertFailed();
        $run(['--execute' => true, '--confirm' => $this->database(), '--cutover' => true])->expectsOutputToContain('POLICY REQUIRED')->assertFailed();
        $this->assertSame($before, $this->snapshot(), 'refused every time: nothing written');

        // On the live target it runs only with the typed database, --cutover and an approved WIP policy.
        config(['finance_accounts.wip_policy' => FinanceChartProfile::WIP_CAPITALISE]);
        $run(['--execute' => true, '--confirm' => $this->database(), '--cutover' => true])->assertSuccessful();
        $this->assertTrue(DB::table('chart_of_accounts')->where('code', 'SAL-002')->exists());
    }

    public function test_the_dry_run_reports_where_each_paying_account_is_linked_against_the_profile(): void
    {
        $this->execute()->assertSuccessful();
        $this->activateProfile();
        DB::table('payment_sources')->update(['gl_account_id' => null, 'is_active' => true]);
        $this->seed(PaymentSourceSeeder::class);
        // Somebody points M-Pesa at a liability, and Stanbic at Equity's account.
        DB::table('payment_sources')->where('code', 'MPESA')->update(['gl_account_id' => DB::table('chart_of_accounts')->where('code', 'AP-001')->value('id')]);
        DB::table('payment_sources')->where('code', 'BANK-STANBIC')->update(['gl_account_id' => DB::table('chart_of_accounts')->where('code', 'EQB-001')->value('id')]);
        $sources = DB::table('payment_sources')->orderBy('id')->get()->map(fn ($s) => (array) $s)->all();
        $dir = sys_get_temp_dir().'/r74-'.uniqid();

        $this->complete(['--output' => $dir])->assertSuccessful();

        $state = json_decode(file_get_contents("{$dir}/chart_completion.json"), true)['payment_sources'];
        @unlink("{$dir}/chart_completion.json");
        @rmdir($dir);
        $this->assertSame('linked as the profile declares', $state['BANK-MAIN']['state']);
        $this->assertStringContainsString('a liability account', $state['MPESA']['state']);
        $this->assertStringContainsString('linked to EQB-001; the profile declares STB-001', $state['BANK-STANBIC']['state']);
        $this->assertStringContainsString('unlinked and ACTIVE', $state['CARD']['state']);
        $this->assertSame('linked as the profile declares', $state['AP']['state'], 'Supplier Credit belongs on the liability');
        $this->assertSame($sources, DB::table('payment_sources')->orderBy('id')->get()->map(fn ($s) => (array) $s)->all(), 'reporting changes no paying account');
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
