<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostCollectorService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProjectFinancialClosureTest extends TestCase
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
        Permission::findOrCreate(Permissions::FINANCE_COSTS_CLOSE, 'web');
        Permission::findOrCreate(Permissions::FINANCE_COSTS_REOPEN, 'web');

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_CLOSE,
        ]);
        $this->actingAs($this->user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Closure Client', 'email' => 'close@test.local', 'phone' => '0711000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Closure Project', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-CLS-001', 'job_number' => 'WNG-CLS-001',
            'created_by' => $this->user->id,
            'financial_closure_status' => 'open',
        ]);
    }

    public function test_closure_check_returns_structured_result(): void
    {
        $response = $this->getJson("/api/costs/projects/{$this->enquiry->id}/closure-check");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame($this->enquiry->id, $data['enquiry_id']);
        $this->assertSame('open', $data['closure_status']);
        $this->assertIsArray($data['checks']);
        $this->assertNotEmpty($data['checks']);

        foreach ($data['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('value', $check);
            $this->assertArrayHasKey('passes', $check);
            $this->assertSame('pending_configuration', $check['policy_classification']);
        }
    }

    public function test_close_project_sets_status_to_closed(): void
    {
        $response = $this->postJson("/api/costs/projects/{$this->enquiry->id}/close");

        $response->assertOk();
        $this->assertSame('closed', $response->json('data.financial_closure_status'));

        $this->enquiry->refresh();
        $this->assertSame('closed', $this->enquiry->financial_closure_status);
        $this->assertSame($this->user->id, (int) $this->enquiry->financially_closed_by);
        $this->assertNotNull($this->enquiry->financially_closed_at);
    }

    public function test_cannot_close_already_closed_project(): void
    {
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $response = $this->postJson("/api/costs/projects/{$this->enquiry->id}/close");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['financial_closure_status']);
    }

    public function test_reopen_requires_special_permission(): void
    {
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        // Current user has CLOSE but NOT REOPEN
        $response = $this->postJson("/api/costs/projects/{$this->enquiry->id}/reopen", [
            'reason' => 'Late supplier bill arrived',
        ]);

        $response->assertStatus(403);
    }

    public function test_reopen_sets_status_back_to_open_when_permission_granted(): void
    {
        $this->enquiry->update(['financial_closure_status' => 'closed']);
        $this->user->givePermissionTo(Permissions::FINANCE_COSTS_REOPEN);

        $response = $this->postJson("/api/costs/projects/{$this->enquiry->id}/reopen", [
            'reason' => 'Late supplier invoice approved by CFO',
        ]);

        $response->assertOk();
        $this->assertSame('open', $response->json('data.financial_closure_status'));

        $this->enquiry->refresh();
        $this->assertSame('open', $this->enquiry->financial_closure_status);
        $this->assertSame($this->user->id, (int) $this->enquiry->closure_reopened_by);
        $this->assertNotNull($this->enquiry->closure_reopened_at);
        $this->assertSame('Late supplier invoice approved by CFO', $this->enquiry->closure_reopen_reason);
    }

    public function test_collect_blocked_on_financially_closed_project(): void
    {
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $collector = app(CostCollectorService::class);

        $context = new CostContext(
            expenseCode: 'OFFICE-SUPPLIES',
            amount: '500.00',
            nature: CostLine::NATURE_ACTUAL,
            projectId: null,
            enquiryId: $this->enquiry->id,
            jobNumber: $this->enquiry->job_number,
            incurredAt: now()->toIso8601String(),
            currency: 'KES',
            fxRate: '1.000000',
            payeeType: null,
            payeeId: null,
            payeeName: null,
            consumesLineId: null,
            details: [],
            evidence: [],
            description: 'Attempting to collect on closed project',
        );

        $this->expectException(ValidationException::class);
        $collector->collect($context);
    }
}
