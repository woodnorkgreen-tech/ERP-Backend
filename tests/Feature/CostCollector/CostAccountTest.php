<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostAccountTest extends TestCase
{
    use RefreshDatabase;

    private int $enquiryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_COSTS_READ, 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_COSTS_READ);
        $this->actingAs($user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => 'c@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Safaricom Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-ACC-001', 'job_number' => 'WNG-ACC-001',
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(string $nature, string $net, array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'project_enquiry_id' => $this->enquiryId,
            'nature' => $nature,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => $net, 'tax_amount' => '0.00',
            'net_amount' => $net, 'base_net_amount' => $net,
            'details' => ['budget_category' => 'logistics'],
        ], $overrides));
    }

    public function test_it_reports_planned_against_spend_by_category(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', ['consumes_line_id' => $planned->id]);
        $this->line(CostLine::NATURE_COMMITTED, '12000.00', ['consumes_line_id' => $planned->id]);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $response->assertJsonPath('data.totals.planned', '60000.00');
        $response->assertJsonPath('data.totals.actual', '18000.00');
        $response->assertJsonPath('data.totals.committed', '12000.00');
        $response->assertJsonPath('data.totals.remaining', '30000.00');
        $response->assertJsonPath('data.categories.0.category', 'logistics');
        $response->assertJsonPath('data.project.job_number', 'WNG-ACC-001');
    }

    public function test_unbudgeted_spend_is_surfaced_separately(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', ['consumes_line_id' => $planned->id]);
        $this->line(CostLine::NATURE_ACTUAL, '4500.00', ['description' => 'Emergency boda']);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $response->assertJsonPath('data.unbudgeted.total', '4500.00');
        $response->assertJsonPath('data.unbudgeted.count', 1);
        $response->assertJsonPath('data.unbudgeted.lines.0.description', 'Emergency boda');
    }

    public function test_exception_spend_groups_on_the_cause_flag(): void
    {
        $rework = DB::table('cost_causes')->where('code', 'REWORK')->value('id');
        $planned = DB::table('cost_causes')->where('code', 'PLANNED')->value('id');

        $this->line(CostLine::NATURE_ACTUAL, '9000.00', ['cost_cause_id' => $rework]);
        $this->line(CostLine::NATURE_ACTUAL, '5000.00', ['cost_cause_id' => $planned]);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        // Planned is not an exception, so only the rework spend appears.
        $response->assertJsonCount(1, 'data.exceptions');
        $response->assertJsonPath('data.exceptions.0.code', 'REWORK');
        $response->assertJsonPath('data.exceptions.0.total', '9000.00');
    }

    public function test_coverage_distinguishes_a_saving_from_an_unreported_cost(): void
    {
        $answered = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_PLANNED, '20000.00');   // nothing spent against it
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', ['consumes_line_id' => $answered->id]);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $response->assertJsonPath('data.coverage.planned_lines', 2);
        $response->assertJsonPath('data.coverage.lines_with_spend', 1);
        $response->assertJsonPath('data.coverage.lines_awaiting', 1);
        $this->assertEqualsWithDelta(50.0, $response->json('data.coverage.percent'), 0.01);
    }

    public function test_the_accounts_grid_lists_one_row_per_project_with_grand_totals(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', ['consumes_line_id' => $planned->id]);
        $this->line(CostLine::NATURE_ACTUAL, '4500.00');            // unbudgeted
        $this->line(CostLine::NATURE_ACTUAL, '900.00', ['status' => CostLine::STATUS_SUBMITTED]);

        $response = $this->getJson('/api/costs/accounts')->assertOk();

        $response->assertJsonCount(1, 'rows');
        $response->assertJsonPath('rows.0.enquiry_id', $this->enquiryId);
        $response->assertJsonPath('rows.0.job_number', 'WNG-ACC-001');
        $response->assertJsonPath('rows.0.planned', '60000.00');
        // 18,000 budgeted + 4,500 unbudgeted; the unverified 900 does not count.
        $response->assertJsonPath('rows.0.actual', '22500.00');
        $response->assertJsonPath('rows.0.unbudgeted', '4500.00');
        $response->assertJsonPath('rows.0.remaining', '37500.00');

        // Totals span every project, not just the page — a page total in a
        // financial table invites being read as the whole.
        $response->assertJsonPath('totals.planned', '60000.00');
        $response->assertJsonPath('totals.actual', '22500.00');
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_reversed_and_unverified_lines_do_not_count(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', [
            'consumes_line_id' => $planned->id, 'status' => CostLine::STATUS_REVERSED,
        ]);
        $this->line(CostLine::NATURE_ACTUAL, '7000.00', [
            'consumes_line_id' => $planned->id, 'status' => CostLine::STATUS_SUBMITTED,
        ]);

        $this->getJson("/api/costs/account/{$this->enquiryId}")
            ->assertOk()
            ->assertJsonPath('data.totals.actual', '0.00')
            ->assertJsonPath('data.totals.remaining', '60000.00');
    }
}
