<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineTransfer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostTransferTest extends TestCase
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
        Permission::findOrCreate(Permissions::FINANCE_COSTS_TRANSFER, 'web');

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_TRANSFER,
        ]);
        $this->actingAs($this->user, 'sanctum');

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => 'cl@test.local', 'phone' => '0711000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectAId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Source Project', 'contact_person' => 'Source Contact',
            'enquiry_number' => 'ENQ-SRC-001', 'job_number' => 'WNG-SRC-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectBId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Dest Project', 'contact_person' => 'Dest Contact',
            'enquiry_number' => 'ENQ-DST-001', 'job_number' => 'WNG-DST-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->verifiedLine = CostLine::create([
            'ref' => 'CL-0088881',
            'project_enquiry_id' => $this->projectAId,
            'job_number' => 'WNG-SRC-001',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '30000.00',
            'tax_amount' => '0.00',
            'net_amount' => '30000.00',
            'base_net_amount' => '30000.00',
            'description' => 'Fabrication materials miscoded',
        ]);
    }

    public function test_transfer_creates_reversing_pair(): void
    {
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'Wrong project code on purchase',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertStringStartsWith('CL-TRF-OUT-', $data['out_ref']);
        $this->assertStringStartsWith('CL-TRF-IN-', $data['in_ref']);

        // Assert original source cost line was NOT mutated
        $original = CostLine::find($this->verifiedLine->id);
        $this->assertSame($this->projectAId, $original->project_enquiry_id);
        $this->assertSame('30000.00', (string) $original->net_amount);

        // Assert OUT line is on source project with negative net amount
        $outLine = CostLine::where('ref', $data['out_ref'])->first();
        $this->assertNotNull($outLine);
        $this->assertSame($this->projectAId, $outLine->project_enquiry_id);
        $this->assertSame('-30000.00', (string) $outLine->net_amount);
        $this->assertSame(CostLine::STATUS_VERIFIED, $outLine->status);

        // Assert IN line is on destination project with positive net amount
        $inLine = CostLine::where('ref', $data['in_ref'])->first();
        $this->assertNotNull($inLine);
        $this->assertSame($this->projectBId, $inLine->project_enquiry_id);
        $this->assertSame('30000.00', (string) $inLine->net_amount);
        $this->assertSame(CostLine::STATUS_VERIFIED, $inLine->status);

        // Assert transfer record exists linking all three
        $this->assertDatabaseHas('cost_line_transfers', [
            'source_cost_line_id' => $this->verifiedLine->id,
            'out_cost_line_id' => $outLine->id,
            'in_cost_line_id' => $inLine->id,
            'transferred_by' => $this->user->id,
        ]);
    }

    public function test_transfer_amounts_balance_to_zero(): void
    {
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'Balance verification',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $outLine = CostLine::where('ref', $data['out_ref'])->first();
        $inLine = CostLine::where('ref', $data['in_ref'])->first();

        // OUT + IN sum must be exactly 0.00
        $sum = bcadd((string) $outLine->net_amount, (string) $inLine->net_amount, 2);
        $this->assertSame('0.00', $sum);
    }

    public function test_transfer_requires_finance_costs_transfer_permission(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);
        $unprivileged->givePermissionTo(Permissions::FINANCE_COSTS_READ);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
                'destination_enquiry_id' => $this->projectBId,
                'reason' => 'Unauthorized transfer',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_transfer_to_same_project(): void
    {
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectAId,
            'reason' => 'Attempting self-transfer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_enquiry_id']);
    }

    public function test_cannot_transfer_unverified_line(): void
    {
        $submittedLine = CostLine::create([
            'ref' => 'CL-0088882',
            'project_enquiry_id' => $this->projectAId,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '30000.00',
            'tax_amount' => '0.00',
            'net_amount' => '30000.00',
            'base_net_amount' => '30000.00',
        ]);

        $response = $this->postJson("/api/costs/lines/{$submittedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'Unverified line attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cost_line_id']);
    }

    public function test_cannot_transfer_already_transferred_line(): void
    {
        // First transfer succeeds
        $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'First transfer',
        ])->assertStatus(201);

        // Attempting to transfer the same source line again must fail 422
        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'Double transfer attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cost_line_id']);
    }

    public function test_cannot_transfer_to_financially_closed_project(): void
    {
        DB::table('project_enquiries')
            ->where('id', $this->projectBId)
            ->update(['financial_closure_status' => 'closed']);

        $response = $this->postJson("/api/costs/lines/{$this->verifiedLine->id}/transfer", [
            'destination_enquiry_id' => $this->projectBId,
            'reason' => 'Transfer to closed project',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_enquiry_id']);
    }
}
