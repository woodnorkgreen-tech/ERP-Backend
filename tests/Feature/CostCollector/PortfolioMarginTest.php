<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PortfolioMarginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $projectAId;
    private int $projectBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_COSTS_READ, 'web');
        Permission::findOrCreate(Permissions::FINANCE_COSTS_PORTFOLIO, 'web');

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_PORTFOLIO,
        ]);
        $this->actingAs($this->user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Portfolio Client', 'email' => 'p@test.local', 'phone' => '0711000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectAId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Project A', 'contact_person' => 'Contact A',
            'enquiry_number' => 'ENQ-PTA-001', 'job_number' => 'WNG-PTA-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectBId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Project B', 'contact_person' => 'Contact B',
            'enquiry_number' => 'ENQ-PTB-001', 'job_number' => 'WNG-PTB-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Add some actual cost lines
        CostLine::create([
            'ref' => 'CL-0077771',
            'project_enquiry_id' => $this->projectAId,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '15000.00',
            'tax_amount' => '0.00',
            'net_amount' => '15000.00',
            'base_net_amount' => '15000.00',
        ]);
    }

    public function test_portfolio_margin_returns_margin_per_enquiry(): void
    {
        $response = $this->postJson('/api/costs/portfolio-margin', [
            'enquiry_ids' => [$this->projectAId, $this->projectBId],
        ]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey((string) $this->projectAId, $data);
        $this->assertArrayHasKey((string) $this->projectBId, $data);

        $marginA = $data[(string) $this->projectAId];
        $this->assertArrayHasKey('billed_revenue', $marginA);
        $this->assertArrayHasKey('cost_of_sales', $marginA);
        $this->assertArrayHasKey('margin', $marginA);
        $this->assertArrayHasKey('margin_percent', $marginA);
        $this->assertArrayHasKey('cost_completeness', $marginA);
        $this->assertSame('direct', $marginA['margin_type']);
        $this->assertSame('provisional', $marginA['margin_status']);
        $this->assertFalse($marginA['fully_loaded_available']);
    }

    public function test_portfolio_margin_requires_portfolio_permission(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);
        $unprivileged->givePermissionTo(Permissions::FINANCE_COSTS_READ);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson('/api/costs/portfolio-margin', [
                'enquiry_ids' => [$this->projectAId],
            ]);

        $response->assertStatus(403);
    }

    public function test_portfolio_margin_includes_cost_completeness(): void
    {
        $response = $this->postJson('/api/costs/portfolio-margin', [
            'enquiry_ids' => [$this->projectAId],
        ]);

        $response->assertOk();
        $completeness = $response->json("data.{$this->projectAId}.cost_completeness");

        $this->assertSame('included', $completeness['materials']);
        $this->assertSame('included', $completeness['procurement']);
        $this->assertSame('included', $completeness['expenses']);
        $this->assertSame('not_included', $completeness['labour']);
        $this->assertSame('not_included', $completeness['logistics']);
        $this->assertSame('not_included', $completeness['overhead']);
    }
}
