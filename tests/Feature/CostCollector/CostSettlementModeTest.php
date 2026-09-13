<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostSettlementModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $verifier;
    private PaymentSource $bankSource;
    private ExpenseCode $expenseCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentSourceSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        foreach ([
            Permissions::FINANCE_COSTS_CREATE,
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_READ,
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $department = Department::create(['name' => 'Finance']);
        $employee = Employee::create([
            'employee_id' => 'EMP-CLAIMANT',
            'first_name' => 'Jane', 'last_name' => 'Claimant',
            'email' => 'jane.claimant@example.test', 'phone' => '254712345678',
            'department_id' => $department->id, 'position' => 'Officer',
            'hire_date' => now()->subYear()->toDateString(), 'status' => 'active',
        ]);
        $this->user = User::factory()->create([
            'is_active' => true, 'name' => 'Jane Claimant', 'employee_id' => $employee->id,
        ]);
        $this->user->givePermissionTo([Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ]);

        $this->verifier = User::factory()->create(['is_active' => true, 'name' => 'Finance Verifier']);
        $this->verifier->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_READ,
        ]);

        $this->bankSource = PaymentSource::where('type', 'bank')->where('is_active', true)->firstOrFail();
        
        $debitAccountId = \App\Modules\Finance\Models\ChartOfAccount::where('category', 'expense')->firstOrFail()->id;

        $this->expenseCode = ExpenseCode::create([
            'code' => 'TEST-MODE-001',
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Site Operations',
            'expense_type' => 'Site Consumables',
            'simple_meaning' => 'General consumables',
            'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED,
            'cash_flow_class' => 'operating',
            'is_active' => true,
            'default_debit_account_id' => $debitAccountId,
            'minimum_evidence' => [],
            'extra_operational_data' => [],
            'requires_supplier' => false,
            'requires_asset_record' => false,
            'is_capex_review' => false,
        ]);
    }

    public function test_out_of_pocket_claim_credits_ap_and_identifies_claimant_in_vouchers(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 1500.00,
            'payee_name' => 'Shell Petrol Station',
            'description' => 'Site fuel paid out of pocket',
            'funding_mode' => 'out_of_pocket',
        ]);

        $response->assertStatus(201);
        $costId = $response->json('data.id');

        $line = CostLine::findOrFail($costId);
        $this->assertEquals('out_of_pocket', $line->details['funding_mode']);
        $this->assertEquals($this->user->id, $line->details['claimant_user_id']);
        $this->assertEquals('Jane Claimant', $line->details['claimant_name']);

        // Verify cost line as finance
        $verifierService = app(CostVerificationService::class);
        $verifiedLine = $verifierService->verify($line, $this->verifier);

        $this->assertEquals('verified', $verifiedLine->status);
        $this->assertNotNull($verifiedLine->journal_entry_id);

        $journal = $verifiedLine->journalEntry;
        $this->assertEquals('posted', $journal->status);

        // Assert credit leg goes to 2100 Accounts Payable (reimbursement liability)
        $creditLine = $journal->lines->where('entry_type', 'credit')->first();
        $this->assertNotNull($creditLine);
        $this->assertStringContainsString('Staff reimbursement payable to Jane Claimant', $creditLine->description);
        $this->assertStringContainsString('Receipt from Shell Petrol Station', $creditLine->description);

        // Verify it appears in eligible liabilities for spend vouchers with claimant metadata
        $this->actingAs($this->verifier, 'sanctum');
        $liabilitiesResponse = $this->getJson('/api/finance/spend-vouchers/eligible-liabilities');
        $liabilitiesResponse->assertOk();

        $item = collect($liabilitiesResponse->json('data'))->firstWhere('id', $costId);
        $this->assertNotNull($item);
        $this->assertEquals('out_of_pocket', $item['funding_mode']);
        $this->assertEquals('Jane Claimant', $item['claimant_name']);
        $this->assertEquals($this->user->id, $item['claimant_user_id']);
        $this->assertEquals('254712345678', $item['claimant_phone']);
    }

    public function test_company_paid_cost_credits_bank_directly_without_creating_ap_liability(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 4500.00,
            'payee_name' => 'Kenya Power KPLC',
            'description' => 'Electricity bill paid from bank account',
            'funding_mode' => 'company_paid',
            'payment_source_id' => $this->bankSource->id,
        ]);

        $response->assertStatus(201);
        $costId = $response->json('data.id');

        $line = CostLine::findOrFail($costId);
        $this->assertEquals('company_paid', $line->details['funding_mode']);
        $this->assertEquals($this->bankSource->id, $line->details['payment_source_id']);

        // Verify cost line as finance
        $verifierService = app(CostVerificationService::class);
        $verifiedLine = $verifierService->verify($line, $this->verifier);

        $this->assertEquals('verified', $verifiedLine->status);
        $this->assertNotNull($verifiedLine->journal_entry_id);

        $journal = $verifiedLine->journalEntry;
        $this->assertEquals('posted', $journal->status);

        // Assert credit leg went directly to Bank GL account (NOT 2100 AP)
        $creditLine = $journal->lines->where('entry_type', 'credit')->first();
        $this->assertNotNull($creditLine);
        $this->assertEquals($this->bankSource->gl_account_id, $creditLine->account_id);
        $this->assertStringContainsString("Direct settlement via {$this->bankSource->name}", $creditLine->description);

        // Assert it does NOT appear in eligible liabilities (no fake AP liability created!)
        $this->actingAs($this->verifier, 'sanctum');
        $liabilitiesResponse = $this->getJson('/api/finance/spend-vouchers/eligible-liabilities');
        $liabilitiesResponse->assertOk();

        $item = collect($liabilitiesResponse->json('data'))->firstWhere('id', $costId);
        $this->assertNull($item, 'Direct company-paid cost lines must not create open AP liabilities.');
    }

    public function test_capture_requires_an_explicit_funding_mode(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 1000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('funding_mode');
    }

    public function test_supplier_credit_requires_a_supplier_master_payee(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 1000,
                'funding_mode' => 'unpaid_invoice',
                'payee_name' => 'Typed supplier name',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payee_type', 'payee_id']);
    }
}
