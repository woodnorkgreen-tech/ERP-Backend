<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\ReceivablesPostingService;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Services\ProjectFinancialAccess;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Report 53 — D3 Option A: WNG keeps its QuickBooks chart; the redesigned posting code
 * reaches it through the account map, and refuses cleanly where a function is unmapped.
 */
class WngChartMappingTest extends TestCase
{
    use RefreshDatabase;

    /** A slice of WNG's real chart (mnemonic QuickBooks codes), no reference codes. */
    private function wngChart(): void
    {
        DB::table('chart_of_accounts')->delete();
        foreach ([['AR-001', 'Accounts Receivable (A/R)', 'asset'], ['AP-001', 'Accounts Payable (A/P)', 'liability'],
            ['PETTY-001', 'Petty cash', 'asset'], ['EQB-001', 'Equity Bank', 'asset'], ['COS-008', 'Cost of Sales:Materials', 'expense'],
            ['ADM-001', 'Administration expenses', 'expense'], ['OBE-001', 'Opening Balance Equity', 'equity']] as [$code, $name, $category]) {
            DB::table('chart_of_accounts')->insert(['code' => $code, 'name' => $name, 'category' => $category,
                'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_every_posting_function_is_a_reference_chart_account(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $resolution = FinanceAccountFunctions::resolution();

        $this->assertCount(37, $resolution);
        $this->assertSame([], array_keys(array_filter($resolution, fn ($f) => ! $f['resolved'])),
            'On the reference chart every function must resolve (nothing changes for a reference-chart install).');
    }

    public function test_on_the_wng_chart_readiness_names_every_unmapped_function_and_mapping_resolves_it(): void
    {
        $this->wngChart();
        config(['finance_accounts.map' => []]);

        $unresolved = array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved']));
        $this->assertContains('accounts_receivable', $unresolved);
        $this->assertContains('accounts_payable', $unresolved);

        config(['finance_accounts.map' => ['1100' => 'AR-001', '2100' => 'AP-001', '1030' => 'PETTY-001']]);
        $resolution = FinanceAccountFunctions::resolution();
        $this->assertTrue($resolution['accounts_receivable']['resolved']);
        $this->assertSame('AR-001', $resolution['accounts_receivable']['local_code']);
        $this->assertSame('Accounts Payable (A/P)', $resolution['accounts_payable']['account']);
        $this->assertFalse($resolution['project_revenue']['resolved'], 'WNG chart has no revenue account: stays unresolved');
    }

    public function test_readiness_endpoint_reports_functions_and_accounts_can_open_it(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->wngChart();
        $accounts = User::factory()->create(['is_active' => true]);
        $accounts->assignRole('Accounts');

        $response = $this->actingAs($accounts, 'sanctum')->getJson('/api/finance/readiness')->assertOk();

        $check = collect($response->json('data.checks'))->firstWhere('key', 'required_accounts');
        $this->assertFalse($check['ready']);
        $this->assertStringContainsString('accounts_receivable (1100)', $check['message']);
        $this->assertCount(37, $response->json('data.account_functions'));
    }

    public function test_an_unmapped_function_refuses_posting_with_a_clear_message(): void
    {
        $this->wngChart();
        config(['finance_accounts.map' => []]);
        $account = new ReflectionMethod(ReceivablesPostingService::class, 'account');

        try {
            $account->invoke(app(ReceivablesPostingService::class), FinanceAccountFunctions::ACCOUNTS_RECEIVABLE, 'Accounts Receivable');
            $this->fail('Posting to an unmapped function must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('map reference code 1100', $e->getMessage());
        }

        config(['finance_accounts.map' => ['1100' => 'AR-001']]);
        $this->assertSame((int) DB::table('chart_of_accounts')->where('code', 'AR-001')->value('id'),
            $account->invoke(app(ReceivablesPostingService::class), FinanceAccountFunctions::ACCOUNTS_RECEIVABLE, 'Accounts Receivable'));
    }

    public function test_an_uncoded_cost_never_falls_back_to_an_arbitrary_account(): void
    {
        $this->wngChart();
        config(['finance_accounts.map' => []]);
        $resolve = new ReflectionMethod(JournalPostingService::class, 'resolveAccountsForCostLine');
        $line = new CostLine(['nature' => CostLine::NATURE_ACTUAL, 'source_ref' => 'historical']);

        // Before Report 53 this returned ADM-001 — the first "expense" account by code.
        [$debit] = $resolve->invoke(app(JournalPostingService::class), $line, null);
        $this->assertNull($debit, 'unmapped fallback must yield no debit (the entry is then refused), never a guess');

        config(['finance_accounts.map' => ['1211' => 'COS-008']]);
        [$debit] = $resolve->invoke(app(JournalPostingService::class), $line, null);
        $this->assertSame((int) DB::table('chart_of_accounts')->where('code', 'COS-008')->value('id'), $debit);
    }

    public function test_the_reference_chart_fallback_is_unchanged(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $resolve = new ReflectionMethod(JournalPostingService::class, 'resolveAccountsForCostLine');
        [$debit] = $resolve->invoke(app(JournalPostingService::class), new CostLine(['nature' => CostLine::NATURE_ACTUAL, 'source_ref' => 'historical']), null);

        $this->assertSame((int) DB::table('chart_of_accounts')->where('code', '1211')->value('id'), $debit);
    }

    public function test_seeders_link_through_the_map_and_never_wipe_a_finance_set_link(): void
    {
        $this->wngChart();
        config(['finance_accounts.map' => ['1030' => 'PETTY-001']]);
        $this->seed(PaymentSourceSeeder::class);
        $petty = (int) DB::table('chart_of_accounts')->where('code', 'PETTY-001')->value('id');
        $this->assertSame($petty, (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('gl_account_id'));

        // Finance links NCBA on the paying-accounts screen; re-seeding must keep it.
        $equity = (int) DB::table('chart_of_accounts')->where('code', 'EQB-001')->value('id');
        DB::table('payment_sources')->where('code', 'BANK-ALT')->update(['gl_account_id' => $equity]);
        $this->seed(PaymentSourceSeeder::class);
        $this->assertSame($equity, (int) DB::table('payment_sources')->where('code', 'BANK-ALT')->value('gl_account_id'));

        // Same rule for the tax seeder's VAT/WHT links.
        $this->seed(FinanceTaxSeeder::class);
        DB::table('vat_treatments')->where('code', 'STD16-REC')->update(['gl_account_id' => $equity]);
        $this->seed(FinanceTaxSeeder::class);
        $this->assertSame($equity, (int) DB::table('vat_treatments')->where('code', 'STD16-REC')->value('gl_account_id'));
    }

    public function test_r1_project_officers_record_and_po_verify_labour_only_on_their_projects(): void
    {
        $matrix = RolePermissions::matrix()['Project Officer'];
        $this->assertContains(Permissions::FINANCE_LABOUR_VIEW, $matrix);
        $this->assertContains(Permissions::FINANCE_LABOUR_RECORD, $matrix);
        $this->assertContains(Permissions::FINANCE_LABOUR_PO_VERIFY, $matrix);
        $this->assertNotContains(Permissions::FINANCE_LABOUR_FINANCE_VERIFY, $matrix, 'Finance verification stays with Accounts');

        $this->seed(RoleAndPermissionSeeder::class);
        [$officer, $other] = [User::factory()->create(['is_active' => true]), User::factory()->create(['is_active' => true])];
        $officer->assignRole('Project Officer');
        $other->assignRole('Project Officer');
        $mine = $this->enquiryLedBy($officer);
        $theirs = $this->enquiryLedBy($other);
        $access = app(ProjectFinancialAccess::class);

        $this->assertTrue($access->canRecordLabour($officer->fresh(), $mine));
        $this->assertTrue($access->canPoVerifyLabour($officer->fresh(), $mine));
        $this->assertFalse($access->canRecordLabour($officer->fresh(), $theirs), 'scoped to assigned projects');
        $this->assertFalse($access->canPoVerifyLabour($officer->fresh(), $theirs));
        $this->assertFalse($access->canFinanceVerifyLabour($officer->fresh()));
    }

    public function test_r3_accounts_holds_finance_reports_view_and_nothing_else_changed_for_it(): void
    {
        $this->assertContains(Permissions::FINANCE_REPORTS_VIEW, RolePermissions::matrix()['Accounts']);
        $this->assertNotContains(Permissions::FINANCE_PETTY_CASH_CUSTODY, RolePermissions::matrix()['Accounts'], 'R-2 stays open');
        foreach (RolePermissions::matrix() as $role => $permissions) {
            if ($role !== 'Super Admin') {
                $this->assertNotContains(Permissions::FINANCE_PETTY_CASH_CUSTODY, $permissions, "R-2 is WNG's decision; {$role} must not receive custody");
            }
        }
    }

    public function test_the_mapping_command_validates_the_proposal_and_emits_only_approved_lines(): void
    {
        $this->wngChart();
        $proposal = sys_get_temp_dir().'/wng-proposal-'.getmypid().'.json';
        $functions = collect(FinanceAccountFunctions::all())->map(fn () => ['wng_code' => null, 'classification' => 'ACCOUNTANT_DECISION'])->all();
        $functions['accounts_receivable'] = ['wng_code' => 'AR-001', 'classification' => 'EXACT_MATCH', 'status' => 'approved'];
        $functions['accounts_payable'] = ['wng_code' => 'AP-001', 'classification' => 'EXACT_MATCH'];
        file_put_contents($proposal, json_encode(['functions' => $functions]));

        $this->artisan('finance:account-mapping', ['--proposal' => $proposal, '--emit-map' => true])
            ->expectsOutputToContain("'1100' => 'AR-001',   // accounts_receivable")
            ->doesntExpectOutputToContain("'2100' => 'AP-001'")
            ->assertSuccessful();

        $functions['accounts_payable']['wng_code'] = 'NOT-IN-CHART';
        file_put_contents($proposal, json_encode(['functions' => $functions]));
        $this->artisan('finance:account-mapping', ['--proposal' => $proposal])
            ->expectsOutputToContain("proposes 'NOT-IN-CHART', which is not in this chart")
            ->assertFailed();
    }

    public function test_the_committed_wng_proposal_covers_every_function_and_approves_nothing(): void
    {
        $proposal = json_decode((string) file_get_contents(database_path('finance/wng-coa-mapping-proposal.json')), true);

        $this->assertEqualsCanonicalizing(array_keys(FinanceAccountFunctions::all()), array_keys($proposal['functions']));
        $approved = collect([$proposal['functions'], $proposal['catalogue_references'], $proposal['payment_sources']])->flatten(1)
            ->filter(fn ($e) => ($e['status'] ?? 'proposed') === 'approved');
        $this->assertCount(0, $approved, 'No mapping may be marked approved until WNG supplies the accountant approval');
    }

    private function enquiryLedBy(User $officer): ProjectEnquiry
    {
        $client = DB::table('clients')->insertGetId(['full_name' => 'C', 'email' => uniqid('c').'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
            'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('project_enquiries')->insertGetId(['date_received' => now()->toDateString(), 'client_id' => $client,
            'title' => 'E', 'contact_person' => 'C', 'enquiry_number' => 'ENQ-R1-'.uniqid(), 'job_number' => 'WNG-R1-'.uniqid(),
            'status' => 'in_progress', 'financial_closure_status' => 'open', 'project_officer_id' => $officer->id,
            'created_by' => $officer->id, 'created_at' => now(), 'updated_at' => now()]);

        return ProjectEnquiry::findOrFail($id);
    }
}
