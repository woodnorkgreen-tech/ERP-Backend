<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineAllocation;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $projectAId;
    private int $projectBId;
    private CostLine $verifiedLine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_COSTS_READ, 'web');
        Permission::findOrCreate(Permissions::FINANCE_COSTS_ALLOCATE, 'web');

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_ALLOCATE,
        ]);
        $this->actingAs($this->user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Test Client', 'email' => 'client@test.local', 'phone' => '0711000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectAId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Project Alpha', 'contact_person' => 'Alpha Contact',
            'enquiry_number' => 'ENQ-ALF-001', 'job_number' => 'WNG-ALF-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectBId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Project Beta', 'contact_person' => 'Beta Contact',
            'enquiry_number' => 'ENQ-BET-001', 'job_number' => 'WNG-BET-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->verifiedLine = CostLine::create([
            'ref' => 'CL-0099991',
            'project_enquiry_id' => $this->projectAId,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '45000.00',
            'tax_amount' => '0.00',
            'net_amount' => '45000.00',
            'base_net_amount' => '45000.00',
            'description' => 'Shared Venue Hire',
        ]);
    }

    public function test_allocations_must_sum_to_parent_amount(): void
    {
        // Parent is 45,000.00. Slices sum to 40,000.00. Must fail 422.
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '30000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '10000.00'],
            ],
            'reason' => 'Shared activation split',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slices']);
    }

    public function test_allocations_sum_invariant_passes_when_equal(): void
    {
        // 25,000.00 + 20,000.00 = 45,000.00 exact match.
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
            ],
            'reason' => 'Shared venue 55/45 split',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('cost_line_allocations', 2);
        $this->assertDatabaseHas('cost_line_allocations', [
            'cost_line_id' => $this->verifiedLine->id,
            'project_enquiry_id' => $this->projectAId,
            'allocated_amount' => '25000.00',
        ]);
        $this->assertDatabaseHas('cost_line_allocations', [
            'cost_line_id' => $this->verifiedLine->id,
            'project_enquiry_id' => $this->projectBId,
            'allocated_amount' => '20000.00',
        ]);
    }

    public function test_requires_finance_costs_allocate_permission(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);
        $unprivileged->givePermissionTo(Permissions::FINANCE_COSTS_READ);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
                'slices' => [
                    ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                    ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
                ],
                'reason' => 'Unauthorized attempt',
            ]);

        $response->assertStatus(403);
    }

    public function test_deallocate_removes_allocations(): void
    {
        // First allocate
        $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
            ],
            'reason' => 'Split',
        ])->assertStatus(201);

        $this->assertDatabaseCount('cost_line_allocations', 2);

        // Now deallocate
        $deleteResponse = $this->deleteJson("/api/costs/lines/{$this->verifiedLine->id}/allocations");
        $deleteResponse->assertOk();

        $this->assertDatabaseCount('cost_line_allocations', 0);
    }

    public function test_cannot_allocate_unverified_line(): void
    {
        $submittedLine = CostLine::create([
            'ref' => 'CL-0099992',
            'project_enquiry_id' => $this->projectAId,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '45000.00',
            'tax_amount' => '0.00',
            'net_amount' => '45000.00',
            'base_net_amount' => '45000.00',
        ]);

        $response = $this->postJson("/api/costs/lines/{$submittedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
            ],
            'reason' => 'Unverified line attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cost_line_id']);
    }

    public function test_allocation_excludes_parent_and_includes_slices_in_margin(): void
    {
        // Allocate $this->verifiedLine (45,000) into 25,000 (A) and 20,000 (B)
        $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
            ],
            'reason' => 'Shared split test',
        ])->assertStatus(201);

        $service = app(\App\Modules\Finance\CostCollector\Services\CostAccountService::class);
        $enquiryA = ProjectEnquiry::find($this->projectAId);
        $enquiryB = ProjectEnquiry::find($this->projectBId);

        $accountA = $service->forEnquiry($enquiryA);
        $accountB = $service->forEnquiry($enquiryB);

        // Project A should show cost_of_sales = 25000.00 (NOT 45,000.00 parent + 25,000.00 slice = 70,000.00)
        $this->assertSame('25000.00', $accountA['margin']['cost_of_sales']);

        // Project B should show cost_of_sales = 20000.00
        $this->assertSame('20000.00', $accountB['margin']['cost_of_sales']);

        // Portfolio margin should also reflect 25,000 for A and 20,000 for B
        $portfolio = $service->portfolioMargin([$this->projectAId, $this->projectBId]);
        $this->assertSame('25000.00', $portfolio[$this->projectAId]['cost_of_sales']);
        $this->assertSame('20000.00', $portfolio[$this->projectBId]['cost_of_sales']);

        // Total company cost across A and B must be exactly 45,000.00, NOT 90,000.00!
        $totalCompanyCost = bcadd($portfolio[$this->projectAId]['cost_of_sales'], $portfolio[$this->projectBId]['cost_of_sales'], 2);
        $this->assertSame('45000.00', $totalCompanyCost);
    }

    public function test_cannot_allocate_to_financially_closed_project(): void
    {
        ProjectEnquiry::where('id', $this->projectBId)->update(['financial_closure_status' => 'closed']);

        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/allocations", [
            'slices' => [
                ['enquiry_id' => $this->projectAId, 'amount' => '25000.00'],
                ['enquiry_id' => $this->projectBId, 'amount' => '20000.00'],
            ],
            'reason' => 'Allocating to closed project',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slices']);
    }
}
