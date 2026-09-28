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

class CostAccountMarginalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ProjectEnquiry $enquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_COSTS_READ, 'web');

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo(Permissions::FINANCE_COSTS_READ);
        $this->actingAs($this->user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Marginal Client', 'email' => 'm@test.local', 'phone' => '0711000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Marginal Project', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-MRG-001', 'job_number' => 'WNG-MRG-001',
            'created_by' => $this->user->id,
            'financial_closure_status' => 'open',
        ]);

        CostLine::create([
            'ref' => 'CL-0066661',
            'project_enquiry_id' => $this->enquiry->id,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '12000.00',
            'tax_amount' => '0.00',
            'net_amount' => '12000.00',
            'base_net_amount' => '12000.00',
        ]);
    }

    public function test_forEnquiry_margin_includes_cost_completeness(): void
    {
        $service = app(CostAccountService::class);
        $account = $service->forEnquiry($this->enquiry);

        $this->assertArrayHasKey('margin', $account);
        $margin = $account['margin'];

        $this->assertArrayHasKey('cost_completeness', $margin);
        $this->assertSame('included', $margin['cost_completeness']['materials']);
        $this->assertSame('included', $margin['cost_completeness']['procurement']);
        $this->assertSame('included', $margin['cost_completeness']['expenses']);
        $this->assertSame('not_included', $margin['cost_completeness']['labour']);
        $this->assertSame('not_included', $margin['cost_completeness']['logistics']);
        $this->assertSame('not_included', $margin['cost_completeness']['overhead']);
    }

    public function test_forEnquiry_margin_status_is_provisional(): void
    {
        $service = app(CostAccountService::class);
        $account = $service->forEnquiry($this->enquiry);

        $this->assertSame('direct', $account['margin']['margin_type']);
        $this->assertSame('provisional', $account['margin']['margin_status']);
        $this->assertFalse($account['margin']['fully_loaded_available']);
    }

    public function test_forEnquiry_includes_financial_closure_status(): void
    {
        $service = app(CostAccountService::class);
        $account = $service->forEnquiry($this->enquiry);

        $this->assertArrayHasKey('project', $account);
        $this->assertSame('open', $account['project']['financial_closure_status']);
    }
}
