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
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_cost_cause_must_be_a_recognised_code(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 1000,
                'funding_mode' => 'out_of_pocket',
                'payee_name' => 'Shell Petrol Station',
                'cost_cause' => 'MADE-UP-CODE',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cost_cause');
    }

    /** CLIENT-CHANGE, EMERGENCY, REWORK, BREAKDOWN, WASTAGE and WARRANTY all seed requires_note true. */
    public function test_an_exception_cause_requires_a_note(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 1000,
                'funding_mode' => 'out_of_pocket',
                'payee_name' => 'Shell Petrol Station',
                'cost_cause' => 'EMERGENCY',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $response = $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 1000,
            'funding_mode' => 'out_of_pocket',
            'payee_name' => 'Shell Petrol Station',
            'cost_cause' => 'EMERGENCY',
            'description' => 'Generator failed on site; fuel bought to keep the crew going.',
        ])->assertCreated();

        $line = CostLine::findOrFail($response->json('data.id'));
        $this->assertSame('EMERGENCY', DB::table('cost_causes')->where('id', $line->cost_cause_id)->value('code'));
    }

    /** PLANNED carries requires_note = false, so an ordinary cost still needs no note. */
    public function test_the_default_cause_needs_no_note(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 1000,
                'funding_mode' => 'out_of_pocket',
                'payee_name' => 'Shell Petrol Station',
            ])
            ->assertCreated();
    }

    /**
     * The PIN lives on the Supplier Master record itself — SpendVoucher already
     * carries payee_kra_pin as free text, but a cost line has no such field and
     * needs none: the requirement is that the supplier this payee resolves to
     * has one on file, not that the capturer retypes it every time.
     */
    public function test_a_supplier_payee_type_requires_a_kra_pin_on_the_supplier_record(): void
    {
        $noPin = Supplier::create([
            'supplier_name' => 'No Pin On File Ltd',
            'contact_person' => 'Accounts', 'phone' => '0700000030',
            'email' => 'no-pin-supplier@example.test', 'address' => 'Industrial Area',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 4500,
                'funding_mode' => 'company_paid',
                'payment_source_id' => $this->bankSource->id,
                'payee_type' => 'SUPPLIER',
                'payee_id' => $noPin->id,
                'payee_name' => $noPin->supplier_name,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payee_id');

        $withPin = Supplier::create([
            'supplier_name' => 'Has Pin On File Ltd',
            'contact_person' => 'Accounts', 'phone' => '0700000031',
            'email' => 'has-pin-supplier@example.test', 'address' => 'Industrial Area',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->user->id,
            'kra_pin' => 'P051234567A',
        ]);

        $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 4500,
            'funding_mode' => 'company_paid',
            'payment_source_id' => $this->bankSource->id,
            'payee_type' => 'SUPPLIER',
            'payee_id' => $withPin->id,
            'payee_name' => $withPin->supplier_name,
        ])->assertCreated();
    }

    /**
     * The unpaid-invoice funding mode already enforces this with its own
     * message (test_supplier_credit_requires_a_supplier_master_payee); this is
     * the same requirement reached from every other funding mode, driven by
     * payee_types.requires_supplier_record rather than hardcoded to one path.
     */
    public function test_a_company_paid_cost_tagged_as_a_supplier_must_name_a_real_supplier(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => $this->expenseCode->code,
                'amount' => 4500,
                'funding_mode' => 'company_paid',
                'payment_source_id' => $this->bankSource->id,
                'payee_type' => 'SUPPLIER',
                'payee_id' => 999999,
                'payee_name' => 'Not A Real Supplier',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payee_id');
    }

    public function test_cost_causes_are_listed_for_the_capture_form(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/costs/cost-causes')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertEqualsCanonicalizing(
            ['PLANNED', 'CLIENT-CHANGE', 'EMERGENCY', 'REWORK', 'BREAKDOWN', 'WASTAGE', 'WARRANTY'],
            $codes->all(),
        );
        $this->assertTrue($response->json('data.0.code') === 'PLANNED', 'Ordered by sort_order, PLANNED first.');
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

    public function test_supplier_credit_can_only_be_paid_to_its_supplier_with_a_payment_voucher(): void
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Correct Supplier Ltd',
            'contact_person' => 'Accounts',
            'phone' => '0700000010',
            'email' => 'correct-supplier@example.test',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->user->id,
            'kra_pin' => 'P051111111A',
        ]);

        $this->actingAs($this->user, 'sanctum');
        $costId = $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 2400,
            'description' => 'Supplier invoice on credit',
            'funding_mode' => 'unpaid_invoice',
            'payee_type' => 'SUPPLIER',
            'payee_id' => $supplier->id,
            'payee_name' => 'Untrusted typed name',
        ])->assertCreated()->json('data.id');

        app(CostVerificationService::class)->verify(CostLine::findOrFail($costId), $this->verifier);
        $this->actingAs($this->verifier, 'sanctum');

        $payload = [
            'payee_name' => 'Wrong Payee',
            'total_amount' => 2400,
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $this->bankSource->id,
            'allocations' => [['cost_line_id' => $costId, 'amount' => 2400]],
        ];

        $this->postJson('/api/finance/spend-vouchers', ['type' => 'reimbursement'] + $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', "Cost line ".CostLine::findOrFail($costId)->ref." is not an out-of-pocket staff claim. Use a payment voucher for supplier liabilities.");

        $response = $this->postJson('/api/finance/spend-vouchers', ['type' => 'payment'] + $payload)
            ->assertCreated()
            ->assertJsonPath('data.payee_name', 'Correct Supplier Ltd')
            ->assertJsonPath('data.supplier_id', $supplier->id);

        $this->assertDatabaseHas('spend_vouchers', [
            'id' => $response->json('data.id'),
            'payee_name' => 'Correct Supplier Ltd',
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_a_payment_voucher_cannot_be_funded_from_supplier_credit(): void
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Credit Test Supplier Ltd',
            'contact_person' => 'Accounts',
            'phone' => '0700000020',
            'email' => 'credit-test-supplier@example.test',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->user->id,
            'kra_pin' => 'P051222222A',
        ]);

        $this->actingAs($this->user, 'sanctum');
        $costId = $this->postJson('/api/costs', [
            'expense_code' => $this->expenseCode->code,
            'amount' => 1800,
            'description' => 'Supplier invoice on credit',
            'funding_mode' => 'unpaid_invoice',
            'payee_type' => 'SUPPLIER',
            'payee_id' => $supplier->id,
            'payee_name' => $supplier->supplier_name,
        ])->assertCreated()->json('data.id');

        app(CostVerificationService::class)->verify(CostLine::findOrFail($costId), $this->verifier);

        // Supplier Credit is the liability the cost line was already booked
        // against; offering it back as the voucher's own paying account would
        // let a payment voucher "settle" it by crediting the same control
        // account it owes, with no cash ever leaving a real bank or float.
        $apSource = PaymentSource::where('code', 'AP')->firstOrFail();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson('/api/finance/spend-vouchers', [
                'type' => 'payment',
                'payee_name' => $supplier->supplier_name,
                'total_amount' => 1800,
                'payment_method' => 'bank_transfer',
                'payment_source_id' => $apSource->id,
                'allocations' => [['cost_line_id' => $costId, 'amount' => 1800]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_source_id']);

        $this->assertDatabaseMissing('spend_vouchers', ['payment_source_id' => $apSource->id]);
    }

    public function test_incomplete_voucher_types_are_not_accepted_by_the_public_endpoint(): void
    {
        $this->actingAs($this->verifier, 'sanctum');

        foreach (['retirement', 'refund', 'top_up', 'reversal'] as $type) {
            $this->postJson('/api/finance/spend-vouchers', [
                'type' => $type,
                'payee_name' => 'Unsafe free-form payee',
                'total_amount' => 100,
                'payment_source_id' => $this->bankSource->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('type');
        }
    }
}
