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

    /** Materials priced against the element that consumes them. */
    private function materialLine(string $nature, string $net, ?string $element, array $overrides = []): CostLine
    {
        return $this->line($nature, $net, array_merge([
            'details' => array_filter([
                'budget_category' => 'materials',
                'element' => $element,
            ], fn ($value) => $value !== null),
        ], $overrides));
    }

    public function test_materials_are_reported_per_element(): void
    {
        // A category total says materials cost 90,000. It cannot say that one
        // stand is on budget and another has doubled — which is the only version
        // of the question anyone actually asks.
        $booth = $this->materialLine(CostLine::NATURE_PLANNED, '60000.00', 'BOOTH1');
        $this->materialLine(CostLine::NATURE_ACTUAL, '20000.00', 'BOOTH1', ['consumes_line_id' => $booth->id]);

        $counter = $this->materialLine(CostLine::NATURE_PLANNED, '30000.00', 'COUNTER');
        $this->materialLine(CostLine::NATURE_ACTUAL, '45000.00', 'COUNTER', ['consumes_line_id' => $counter->id]);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        // Ordered by budget size, so the biggest commitment reads first.
        $response->assertJsonPath('data.elements.0.element', 'BOOTH1');
        $response->assertJsonPath('data.elements.0.planned', '60000.00');
        $response->assertJsonPath('data.elements.0.spent', '20000.00');
        $response->assertJsonPath('data.elements.0.remaining', '40000.00');

        $response->assertJsonPath('data.elements.1.element', 'COUNTER');
        $response->assertJsonPath('data.elements.1.planned', '30000.00');
        $response->assertJsonPath('data.elements.1.remaining', '-15000.00');
    }

    public function test_an_element_breaks_down_into_the_materials_that_built_it(): void
    {
        // "BOOTH1 is 40% over" is where the question starts, not where it ends.
        // The budget line and the issue that fulfils it must land on one row, or
        // the same board reads as two unrelated costs.
        $board = $this->materialLine(CostLine::NATURE_PLANNED, '40000.00', 'BOOTH1', [
            'details' => ['budget_category' => 'materials', 'element' => 'BOOTH1',
                'material' => 'MDF 9mm Sheet', 'library_material_id' => 7],
        ]);
        $this->line(CostLine::NATURE_ACTUAL, '52000.00', [
            'consumes_line_id' => $board->id,
            'details' => ['budget_category' => 'materials', 'element' => 'BOOTH1',
                'material' => 'MDF 9mm Sheet', 'library_material_id' => 7],
        ]);
        $this->line(CostLine::NATURE_PLANNED, '6000.00', [
            'details' => ['budget_category' => 'materials', 'element' => 'BOOTH1',
                'material' => 'Flat Washers M6', 'library_material_id' => 9],
        ]);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $materials = collect($response->json('data.elements.0.materials'));

        // Ordered by budget, so the material carrying the element leads.
        $this->assertSame(['MDF 9mm Sheet', 'Flat Washers M6'], $materials->pluck('material')->all());

        $board = $materials->firstWhere('material', 'MDF 9mm Sheet');
        $this->assertSame('40000.00', $board['planned']);
        $this->assertSame('52000.00', $board['spent']);
        $this->assertSame('-12000.00', $board['remaining']);

        // And the materials still reconcile to the element above them.
        $this->assertSame(
            $response->json('data.elements.0.planned'),
            number_format((float) $materials->sum(fn ($row) => (float) $row['planned']), 2, '.', ''),
        );
    }

    public function test_the_same_catalogue_item_is_one_row_even_when_worded_differently(): void
    {
        // Stores describes a movement, the budget describes a plan. Grouping on
        // the catalogue id keeps them together; grouping on wording would not.
        $this->line(CostLine::NATURE_PLANNED, '40000.00', [
            'details' => ['budget_category' => 'materials', 'element' => 'BOOTH1',
                'material' => 'MDF 9mm Sheet', 'library_material_id' => 7],
        ]);
        $this->line(CostLine::NATURE_ACTUAL, '15000.00', [
            'details' => ['budget_category' => 'materials', 'element' => 'BOOTH1',
                'material' => 'MDF 9mm sheet (board)', 'library_material_id' => 7],
        ]);

        $materials = collect(
            $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk()
                ->json('data.elements.0.materials')
        );

        $this->assertCount(1, $materials);
        $this->assertSame('40000.00', $materials[0]['planned']);
        $this->assertSame('15000.00', $materials[0]['spent']);
    }

    public function test_material_spend_without_an_element_is_labelled_not_hidden(): void
    {
        // Direct purchases and lines predating the element carry none. Dropping
        // them would make the element figures quietly disagree with the category
        // total they are meant to explain.
        $this->materialLine(CostLine::NATURE_PLANNED, '10000.00', 'BOOTH1');
        $this->materialLine(CostLine::NATURE_ACTUAL, '2500.00', null);

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $elements = collect($response->json('data.elements'));

        $this->assertEqualsCanonicalizing(
            ['BOOTH1', 'Unassigned'],
            $elements->pluck('element')->all(),
        );
        $this->assertSame('2500.00', $elements->firstWhere('element', 'Unassigned')['spent']);

        // And the parts still add up to the category they came from.
        $this->assertSame(
            $response->json('data.categories.0.spent'),
            number_format((float) $elements->sum(fn ($row) => (float) $row['spent']), 2, '.', ''),
        );
    }

    public function test_only_materials_are_grouped_by_element(): void
    {
        // Labour, expenses and logistics are flat by nature; there is no element
        // to group them on, so they must not appear in the element breakdown.
        $this->line(CostLine::NATURE_PLANNED, '15000.00');           // logistics
        $this->materialLine(CostLine::NATURE_PLANNED, '5000.00', 'BOOTH1');

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}")->assertOk();

        $this->assertSame(['BOOTH1'], collect($response->json('data.elements'))->pluck('element')->all());
    }

    public function test_the_materials_drilldown_groups_its_lines_by_element(): void
    {
        $booth = $this->materialLine(CostLine::NATURE_PLANNED, '60000.00', 'BOOTH1');
        $this->materialLine(CostLine::NATURE_ACTUAL, '20000.00', 'BOOTH1', ['consumes_line_id' => $booth->id]);
        $this->materialLine(CostLine::NATURE_PLANNED, '30000.00', 'COUNTER');

        $response = $this->getJson("/api/costs/account/{$this->enquiryId}/category-lines?category=materials")
            ->assertOk();

        $this->assertSame(
            ['BOOTH1', 'COUNTER'],
            collect($response->json('elements'))->pluck('element')->all(),
        );
        $response->assertJsonPath('elements.0.planned_total', '60000.00');
        $response->assertJsonPath('elements.0.spend_total', '20000.00');
        $response->assertJsonPath('elements.1.spend_total', '0.00');
    }

    public function test_a_flat_category_drilldown_is_not_grouped(): void
    {
        $this->line(CostLine::NATURE_PLANNED, '15000.00');  // logistics

        $this->getJson("/api/costs/account/{$this->enquiryId}/category-lines?category=logistics")
            ->assertOk()
            ->assertJsonPath('elements', []);
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

    /* ── Drill-down ──────────────────────────────────────────────────────── */

    public function test_a_category_can_be_opened_to_the_lines_behind_it(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00', ['description' => 'Trucking budget']);
        $this->line(CostLine::NATURE_ACTUAL, '18000.00', [
            'consumes_line_id' => $planned->id, 'description' => 'Lorry hire, day one',
        ]);
        $this->line(CostLine::NATURE_ACTUAL, '4500.00', ['description' => 'Emergency boda']);

        // A different category must not leak into the drill-down.
        $this->line(CostLine::NATURE_ACTUAL, '2000.00', [
            'description' => 'Vinyl', 'details' => ['budget_category' => 'materials'],
        ]);

        $response = $this->getJson(
            "/api/costs/account/{$this->enquiryId}/category-lines?category=logistics",
        )->assertOk();

        $response->assertJsonPath('category', 'logistics');
        $response->assertJsonCount(1, 'planned');
        $response->assertJsonPath('planned.0.description', 'Trucking budget');

        // The variance is read as budget against charge, so both come back.
        $response->assertJsonCount(2, 'spend');
        $this->assertEqualsCanonicalizing(
            ['Lorry hire, day one', 'Emergency boda'],
            collect($response->json('spend'))->pluck('description')->all(),
        );
    }

    public function test_the_drill_down_finds_costs_with_no_category_at_all(): void
    {
        // `forEnquiry` labels these 'uncategorised', so the drill-down has to
        // match that absence rather than search for the literal string.
        $this->line(CostLine::NATURE_ACTUAL, '3000.00', [
            'description' => 'Unlabelled spend', 'details' => [],
        ]);

        $this->getJson("/api/costs/account/{$this->enquiryId}/category-lines?category=uncategorised")
            ->assertOk()
            ->assertJsonCount(1, 'spend')
            ->assertJsonPath('spend.0.description', 'Unlabelled spend');
    }

    /* ── Grid filters, which the service accepted and ignored ────────────── */

    public function test_the_grid_can_be_narrowed_to_projects_that_are_over_budget(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '10000.00');
        $this->line(CostLine::NATURE_ACTUAL, '25000.00', ['consumes_line_id' => $planned->id]);

        $withinBudget = $this->secondProject();
        $otherPlanned = $this->line(CostLine::NATURE_PLANNED, '80000.00', [
            'project_enquiry_id' => $withinBudget,
        ]);
        $this->line(CostLine::NATURE_ACTUAL, '1000.00', [
            'project_enquiry_id' => $withinBudget, 'consumes_line_id' => $otherPlanned->id,
        ]);

        $this->getJson('/api/costs/accounts')
            ->assertOk()
            ->assertJsonCount(2, 'rows');

        $this->getJson('/api/costs/accounts?overrun_only=true')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.enquiry_id', $this->enquiryId);
    }

    public function test_the_grid_can_be_narrowed_to_projects_with_unbudgeted_spend(): void
    {
        $planned = $this->line(CostLine::NATURE_PLANNED, '60000.00');
        $this->line(CostLine::NATURE_ACTUAL, '5000.00', ['consumes_line_id' => $planned->id]);

        $messy = $this->secondProject();
        $this->line(CostLine::NATURE_ACTUAL, '900.00', ['project_enquiry_id' => $messy]);

        $this->getJson('/api/costs/accounts?unbudgeted_only=true')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.enquiry_id', $messy);
    }

    public function test_the_grid_searches_the_project_not_the_cost_description(): void
    {
        $this->line(CostLine::NATURE_ACTUAL, '5000.00');

        $other = $this->secondProject();
        $this->line(CostLine::NATURE_ACTUAL, '900.00', ['project_enquiry_id' => $other]);

        // On this screen a row is a project, so a search term means the job.
        $this->getJson('/api/costs/accounts?q=WNG-ACC-002')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.enquiry_id', $other);
    }

    public function test_the_grid_reports_when_a_project_last_recorded_a_cost(): void
    {
        $this->line(CostLine::NATURE_ACTUAL, '5000.00', ['incurred_at' => '2026-05-04 10:00:00']);
        $this->line(CostLine::NATURE_ACTUAL, '2000.00', ['incurred_at' => '2026-07-19 10:00:00']);

        $this->getJson('/api/costs/accounts')
            ->assertOk()
            ->assertJsonPath('rows.0.last_cost_at', '2026-07-19');
    }

    /** A second project, so the filters have something to exclude. */
    private function secondProject(): int
    {
        $clientId = DB::table('clients')->value('id');

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Second Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-ACC-002', 'job_number' => 'WNG-ACC-002',
            'created_by' => DB::table('users')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
