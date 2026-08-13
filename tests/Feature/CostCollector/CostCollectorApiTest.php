<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostCollectorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        foreach ([Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ]);
        $this->actingAs($this->user, 'sanctum');
    }

    private function makeEnquiry(): int
    {
        $clientId = \DB::table('clients')->insertGetId([
            'full_name' => 'Test Client', 'email' => 'client@test.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Test Activation', 'contact_person' => 'Test Contact',
            'enquiry_number' => 'ENQ-API-001', 'job_number' => 'WNG-TEST-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function code(array $overrides = []): ExpenseCode
    {
        return ExpenseCode::create(array_merge([
            'code' => 'DM-WD-001',
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct materials',
            'expense_type' => 'MDF boards',
            'simple_meaning' => 'Medium-density fibreboard used to build counters.',
            'job_id_rule' => ExpenseCode::JOB_REQUIRED,
            'cash_flow_class' => 'operating',
            'is_active' => true,
        ], $overrides));
    }

    public function test_the_picker_searches_and_filters_by_family(): void
    {
        $this->code();
        $this->code(['code' => 'TL-001', 'expense_family' => 'Transport', 'expense_type' => 'Truck hire']);

        $this->getJson('/api/costs/expense-codes?q=truck')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.expense_type', 'Truck hire');

        $this->getJson('/api/costs/expense-codes?family=Direct+materials')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'DM-WD-001');

        $this->getJson('/api/costs/expense-codes/families')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_returns_the_form_definition_for_a_code(): void
    {
        $this->code(['extra_operational_data' => [
            ['key' => 'item_code', 'label' => 'Item code', 'type' => 'text', 'required' => true],
        ], 'minimum_evidence' => [
            ['key' => 'etims_invoice', 'label' => 'eTIMS invoice', 'required' => true],
        ]]);

        $response = $this->getJson('/api/costs/expense-codes/DM-WD-001')->assertOk();

        $response->assertJsonPath('data.fields.0.key', 'item_code');
        $response->assertJsonPath('data.evidence.0.key', 'etims_invoice');
        $response->assertJsonPath('data.job_id_rule', 'required');

        // The GL account is Finance's business and must never reach the client.
        $this->assertArrayNotHasKey('default_debit_gl', $response->json('data'));
        $this->assertArrayNotHasKey('default_debit_account_id', $response->json('data'));
    }

    public function test_it_records_a_cost(): void
    {
        $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);

        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001',
            'amount' => 25000,
            'job_number' => 'WNG-TEST-001',
            'description' => 'MDF for reception counter',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', CostLine::STATUS_SUBMITTED)
            ->assertJsonPath('data.net_amount', '25000.00')
            ->assertJsonPath('data.is_unbudgeted', true)
            ->assertJsonPath('data.expense_code.expense_type', 'MDF boards');

        $this->assertSame($this->user->id, CostLine::firstOrFail()->submitted_by_user_id);
    }

    public function test_reporter_can_correct_a_queried_cost_with_revision_history(): void
    {
        $code = $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);
        $line = CostLine::create([
            'ref' => 'CL-0099001', 'expense_code_id' => $code->id,
            'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_QUERIED,
            'job_number' => 'WNG-TEST-001', 'amount' => '5000.00',
            'tax_amount' => '0.00', 'net_amount' => '5000.00', 'base_net_amount' => '5000.00',
            'currency' => 'KES', 'fx_rate' => '1', 'incurred_at' => now(),
            'description' => 'Incorrect description', 'submitted_by_user_id' => $this->user->id,
        ]);

        $this->putJson("/api/costs/{$line->id}/correction", [
            'amount' => 4500,
            'description' => 'Corrected receipt amount',
            'response' => 'The first amount included an unrelated item.',
        ])->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_SUBMITTED)
            ->assertJsonPath('data.latest_revision.before.amount', '5000.00')
            ->assertJsonPath('data.latest_revision.after.amount', '4500.00');

        $line->refresh();
        $this->assertSame('4500.00', $line->amount);
        $this->assertSame('5000.00', $line->capture_meta['revisions'][0]['before']['amount']);
    }

    public function test_reporting_a_cost_requires_the_create_permission(): void
    {
        $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);

        // finance.costs.create was seeded and granted but checked nowhere, so
        // any authenticated user could write to the cost ledger.
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/costs', [
                'expense_code' => 'DM-WD-001', 'amount' => 5000, 'job_number' => 'WNG-TEST-001',
            ])
            ->assertForbidden();

        $this->assertSame(0, CostLine::count());
    }

    public function test_a_catalogue_rejection_returns_field_level_errors(): void
    {
        $this->code();   // job_id_rule = required

        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001',
            'amount' => 25000,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['jobNumber']);
    }

    public function test_a_submitter_cannot_approve_their_own_cost(): void
    {
        $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);

        // Even if the client sends producer-only fields, they must be ignored.
        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001',
            'amount' => 5000,
            'job_number' => 'WNG-TEST-001',
            'sourceApproved' => true,
            'source_type' => 'GoodsReceiptNote',
            'source_id' => 1,
            'nature' => CostLine::NATURE_PLANNED,
        ])->assertCreated();

        $line = CostLine::firstOrFail();

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->status);
        $this->assertSame(CostLine::NATURE_ACTUAL, $line->nature);
        $this->assertNull($line->source_type);
        $this->assertNull($line->verified_at);
    }

    public function test_it_rejects_a_future_dated_cost_and_tax_above_the_amount(): void
    {
        $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);

        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001', 'amount' => 100,
            'job_number' => 'WNG-TEST-001',
            'incurred_at' => now()->addWeek()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['incurred_at']);

        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001', 'amount' => 100, 'tax_amount' => 500,
            'job_number' => 'WNG-TEST-001',
        ])->assertStatus(422)->assertJsonValidationErrors(['tax_amount']);
    }

    public function test_budget_lines_expose_what_is_left(): void
    {
        $enquiryId = $this->makeEnquiry();

        $planned = CostLine::create([
            'ref' => 'CL-0000001', 'project_enquiry_id' => $enquiryId,
            'nature' => CostLine::NATURE_PLANNED, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => '60000.00', 'tax_amount' => '0.00',
            'net_amount' => '60000.00', 'base_net_amount' => '60000.00',
            'description' => 'Truck hire', 'details' => ['budget_category' => 'logistics'],
        ]);

        CostLine::create([
            'ref' => 'CL-0000002', 'consumes_line_id' => $planned->id,
            'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => '18000.00', 'tax_amount' => '0.00',
            'net_amount' => '18000.00', 'base_net_amount' => '18000.00',
        ]);

        $this->getJson("/api/costs/budget-lines/{$enquiryId}")
            ->assertOk()
            ->assertJsonPath('data.0.budgeted', '60000.00')
            ->assertJsonPath('data.0.spent', '18000.00')
            ->assertJsonPath('data.0.remaining', '42000.00');
    }

    /**
     * A project's budget is commercially sensitive, and this endpoint returns
     * all of it — every line, with what is left on each. It was reachable by any
     * authenticated account from a guessable enquiry id, because it was the one
     * cost endpoint with no authorisation call on it at all.
     */
    public function test_budget_lines_are_not_readable_without_a_cost_permission(): void
    {
        $enquiryId = $this->makeEnquiry();
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/costs/budget-lines/{$enquiryId}")
            ->assertForbidden();
    }

    public function test_evidence_uploads_separately_from_the_cost(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/costs/evidence', [
            'key' => 'etims_invoice',
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertCreated();

        Storage::disk('public')->assertExists($response->json('data.path'));
        $this->assertSame('etims_invoice', $response->json('data.key'));
    }

    public function test_it_lists_only_the_callers_own_submissions(): void
    {
        $this->code(['job_id_rule' => ExpenseCode::JOB_OPTIONAL]);

        $this->postJson('/api/costs', [
            'expense_code' => 'DM-WD-001', 'amount' => 100, 'job_number' => 'WNG-TEST-001',
        ])->assertCreated();

        CostLine::create([
            'ref' => 'CL-9999999', 'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED, 'submitted_by_user_id' => User::factory()->create()->id,
            'amount' => '900.00', 'tax_amount' => '0.00',
            'net_amount' => '900.00', 'base_net_amount' => '900.00',
        ]);

        $this->getJson('/api/costs')->assertOk()->assertJsonCount(1, 'data');
    }
}
