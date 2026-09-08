<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What live jobs have already spoken for.
 *
 * The demand forecast on the Stores desk knew this and the buying side did not,
 * so a picker could report 120 sheets available while three approved jobs were
 * already counting on 85 of them. These pin the one definition both now read,
 * and the arithmetic the desk had been doing inline.
 */
class ProjectMaterialDemandTest extends TestCase
{
    use RefreshDatabase;

    private int $enquiryId;
    private int $projectId;
    private int $elementId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Stores', 'web');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Stores');
        Sanctum::actingAs($this->user);

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Safaricom Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-DEM-001', 'job_number' => 'WNG-DEM-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-DEM-001',
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->elementId = $this->element(approved: true);
    }

    /** A materials task whose specification Projects has signed off. */
    private function element(bool $approved): int
    {
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'in_progress',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode([
                'projectId' => 'WNG-DEM-001',
                'approval_status' => ['all_approved' => $approved],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function material(string $name, float $onHand, float $reserved = 0): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => $name, 'material_code' => 'MAT-' . uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet',
            'unit_cost' => 1500, 'item_status' => 'Active',
        ]);

        Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
        ]);

        return $material;
    }

    private function specify(LibraryMaterial $material, float $quantity, ?int $elementId = null): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $elementId ?? $this->elementId,
            'library_material_id' => $material->id,
            'description' => $material->material_name,
            'unit_of_measurement' => 'sheet',
            'quantity' => $quantity,
            'is_included' => true,
        ]);
    }

    private function issue(LibraryMaterial $material, ElementMaterial $line, float $quantity): InventoryLog
    {
        return InventoryLog::create([
            'material_id' => $material->id,
            'project_id' => $this->projectId,
            'project_material_id' => $line->id,
            'type' => 'check_out',
            'quantity' => -$quantity,
            'balance_after' => 0,
            'reference_no' => 'WNG-DEM-001',
            'logged_at' => now(),
            'user_id' => $this->user->id,
        ]);
    }

    private function demand(): ProjectMaterialDemand
    {
        return app(ProjectMaterialDemand::class);
    }

    public function test_an_approved_unissued_line_is_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $this->specify($material, 85);

        $this->assertSame(85.0, $this->demand()->pendingByMaterial()[$material->id] ?? 0.0);
    }

    public function test_issuing_against_a_line_retires_that_much_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $line = $this->specify($material, 85);
        $this->issue($material, $line, 30);

        $this->assertSame(55.0, $this->demand()->pendingByMaterial()[$material->id] ?? 0.0);
    }

    public function test_a_fully_issued_line_leaves_no_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $line = $this->specify($material, 85);
        $this->issue($material, $line, 85);

        $this->assertArrayNotHasKey($material->id, $this->demand()->pendingByMaterial());
    }

    /** Over-issuing is a Stores matter; it must not read as negative demand. */
    public function test_issuing_more_than_specified_does_not_go_negative(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $line = $this->specify($material, 85);
        $this->issue($material, $line, 100);

        $this->assertArrayNotHasKey($material->id, $this->demand()->pendingByMaterial());
    }

    /**
     * A specification nobody has approved is a draft. Counting it would let an
     * unreviewed list quietly consume the availability a live job is relying on.
     */
    public function test_an_unapproved_specification_is_not_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $this->specify($material, 85, elementId: $this->element(approved: false));

        $this->assertArrayNotHasKey($material->id, $this->demand()->pendingByMaterial());
    }

    public function test_an_excluded_line_is_not_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $line = $this->specify($material, 85);
        $line->update(['is_included' => false]);

        $this->assertArrayNotHasKey($material->id, $this->demand()->pendingByMaterial());
    }

    public function test_demand_from_several_jobs_adds_up_on_one_material(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $this->specify($material, 50);
        $this->specify($material, 35, elementId: $this->element(approved: true));

        $this->assertSame(85.0, $this->demand()->pendingByMaterial()[$material->id] ?? 0.0);
    }

    public function test_it_can_be_narrowed_to_the_materials_asked_about(): void
    {
        $wanted = $this->material('MDF 18mm', onHand: 120);
        $other = $this->material('Ply 12mm', onHand: 40);
        $this->specify($wanted, 85);
        $this->specify($other, 10);

        $rows = $this->demand()->pendingByMaterial([$wanted->id]);

        $this->assertSame(85.0, $rows[$wanted->id] ?? 0.0);
        $this->assertArrayNotHasKey($other->id, $rows);
    }

    /**
     * The whole point of the phase: what the buyer is shown while choosing.
     * `available` subtracts only board reservations, so it answered "120" while
     * 85 were already spoken for.
     */
    public function test_the_material_picker_reports_what_is_uncommitted(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $this->specify($material, 85);

        $row = collect($this->getJson('/api/procurement-stores/material-options?search=MDF')
            ->assertOk()->json('data'))
            ->firstWhere('id', $material->id);

        $this->assertNotNull($row);
        $this->assertSame(120.0, (float) $row['quantity_on_hand']);
        $this->assertSame(85.0, (float) $row['pending_demand']);
        $this->assertSame(35.0, (float) $row['uncommitted']);
    }

    public function test_the_picker_never_reports_negative_uncommitted_stock(): void
    {
        $material = $this->material('MDF 18mm', onHand: 20);
        $this->specify($material, 85);

        $row = collect($this->getJson('/api/procurement-stores/material-options?search=MDF')
            ->assertOk()->json('data'))
            ->firstWhere('id', $material->id);

        $this->assertSame(0.0, (float) $row['uncommitted']);
        $this->assertSame(85.0, (float) $row['pending_demand']);
    }

    /**
     * Characterisation: the desk forecast must keep answering exactly as it did
     * once it reads its demand from the shared service instead of computing it
     * inline.
     */
    public function test_the_desk_forecast_still_answers_as_before(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120, reserved: 20);
        $line = $this->specify($material, 85);
        $this->issue($material, $line, 25);

        $row = collect($this->getJson('/api/procurement-stores/material-demand-forecast')
            ->assertOk()->json('data'))
            ->firstWhere('material_id', $material->id);

        $this->assertNotNull($row);
        $this->assertSame(60.0, (float) $row['pending_demand']);   // 85 specified − 25 issued
        $this->assertSame(100.0, (float) $row['available']);       // 120 on hand − 20 board-reserved
        $this->assertSame(0.0, (float) $row['immediate_shortage']);
        $this->assertSame('fully_covered', $row['status']);
        $this->assertSame(1, count($row['projects']));
        $this->assertSame(60.0, (float) $row['projects'][0]['pending']);
    }

    public function test_the_forecast_reports_a_shortage_it_cannot_cover(): void
    {
        $material = $this->material('MDF 18mm', onHand: 10);
        $this->specify($material, 85);

        $row = collect($this->getJson('/api/procurement-stores/material-demand-forecast')
            ->assertOk()->json('data'))
            ->firstWhere('material_id', $material->id);

        $this->assertSame(75.0, (float) $row['immediate_shortage']);
        $this->assertSame('partially_covered', $row['status']);
    }
}
