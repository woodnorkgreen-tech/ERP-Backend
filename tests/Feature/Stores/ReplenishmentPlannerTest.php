<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buying worked out rather than remembered.
 *
 * Every requisition used to begin with somebody noticing. Stores could see on
 * the demand forecast which materials approved jobs would exhaust, and
 * `min_stock_level` had been a badge on a listing since the table was created —
 * two facts that should drive buying, both visible, neither actionable.
 *
 * These pin the arithmetic and, just as importantly, where it stops: a
 * suggestion becomes a DRAFT the buyer owns, never a submitted requisition.
 */
class ReplenishmentPlannerTest extends TestCase
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
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-REP-001', 'job_number' => 'WNG-REP-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-REP-001',
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'in_progress',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode([
                'projectId' => 'WNG-REP-001',
                'approval_status' => ['all_approved' => true],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->elementId = DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function material(string $name, float $onHand, float $minimum = 0, float $unitCost = 1500): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Workshop', 'code' => 'WS-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => $name, 'material_code' => 'MAT-' . uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet',
            'unit_cost' => $unitCost, 'item_status' => 'Active',
        ]);

        Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
            'min_stock_level' => $minimum,
        ]);

        return $material;
    }

    private function specify(LibraryMaterial $material, float $quantity): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $this->elementId,
            'library_material_id' => $material->id,
            'description' => $material->material_name,
            'unit_of_measurement' => 'sheet',
            'quantity' => $quantity,
            'is_included' => true,
        ]);
    }

    /** An order that can still deliver, optionally part received. */
    private function orderOnTheWay(LibraryMaterial $material, float $quantity, float $received = 0): void
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Supplier ' . uniqid(), 'contact_person' => 'C',
            'phone' => '0700000000', 'email' => uniqid() . '@t.local', 'address' => 'Nairobi',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->user->id,
        ]);

        $order = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Store', 'description' => 'Restock',
            'total_amount' => $quantity * 1500, 'status' => 'approved', 'user_id' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => $material->id,
            'quantity' => $quantity, 'unit_price' => 1500, 'total' => $quantity * 1500,
        ]);

        if ($received > 0) {
            $note = DB::table('goods_receipt_notes')->insertGetId([
                'grn_number' => 'GRN-' . uniqid(), 'date' => now()->toDateString(),
                'purchase_order_id' => $order->id, 'batch_number' => 'B-' . uniqid(),
                'store_location' => 'Store', 'quality_check' => 'pass',
                'store_status' => 'confirmed', 'received_by' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('goods_receipt_note_items')->insert([
                'goods_receipt_note_id' => $note,
                'purchase_order_item_id' => $item->id,
                'ordered_quantity' => $quantity, 'received_quantity' => $received,
                'condition' => 'good', 'accepted' => true,
                'store_status' => 'confirmed', 'stock_status' => 'posted',
                'unit_price' => 1500, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function suggestions()
    {
        return app(ReplenishmentPlanner::class)->suggestions()->keyBy('material_id');
    }

    public function test_a_material_that_covers_its_jobs_is_not_suggested(): void
    {
        $material = $this->material('MDF 18mm', onHand: 120);
        $this->specify($material, 85);

        $this->assertArrayNotHasKey($material->id, $this->suggestions());
    }

    public function test_demand_beyond_the_shelf_is_a_job_shortfall(): void
    {
        $material = $this->material('MDF 18mm', onHand: 20);
        $this->specify($material, 85);

        $row = $this->suggestions()[$material->id];

        $this->assertSame('job_shortfall', $row['reason']);
        $this->assertSame('urgent', $row['urgency']);
        $this->assertSame(-65.0, $row['projected_position']);
        $this->assertSame(65.0, $row['suggested_quantity']);
        $this->assertCount(1, $row['demand_sources']);
        $this->assertSame('WNG-REP-001', $row['demand_sources'][0]['project_code']);
        $this->assertSame(85.0, $row['demand_sources'][0]['pending']);
    }

    /** Ordering the same shortfall twice is the failure this must not have. */
    public function test_stock_already_on_order_is_not_ordered_again(): void
    {
        $material = $this->material('MDF 18mm', onHand: 20);
        $this->specify($material, 85);
        $this->orderOnTheWay($material, 65);

        $this->assertArrayNotHasKey($material->id, $this->suggestions());
    }

    /** Only the undelivered balance counts, or a part delivery is counted twice. */
    public function test_only_the_unreceived_balance_of_an_order_counts_as_incoming(): void
    {
        $material = $this->material('MDF 18mm', onHand: 20);
        $this->specify($material, 85);
        $this->orderOnTheWay($material, 65, received: 40);

        $row = $this->suggestions()[$material->id];

        // 20 free + 25 still to come − 85 needed
        $this->assertSame(-40.0, $row['projected_position']);
        $this->assertSame(40.0, $row['suggested_quantity']);
    }

    public function test_stock_under_its_minimum_is_suggested_even_with_no_jobs(): void
    {
        $material = $this->material('Screws 40mm', onHand: 12, minimum: 50);

        $row = $this->suggestions()[$material->id];

        $this->assertSame('below_minimum', $row['reason']);
        $this->assertSame('normal', $row['urgency']);
        $this->assertSame(38.0, $row['suggested_quantity']);
    }

    /**
     * A zero minimum is the default nobody set, not a decision to hold none.
     * Treating it as a target would suggest buying the whole catalogue.
     */
    public function test_a_material_with_no_minimum_and_no_demand_is_left_alone(): void
    {
        $this->material('Odds and ends', onHand: 0, minimum: 0);

        $this->assertCount(0, $this->suggestions());
    }

    /** The minimum is a buffer left standing after the jobs are served. */
    public function test_the_minimum_is_measured_after_job_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 100, minimum: 30);
        $this->specify($material, 85);

        $row = $this->suggestions()[$material->id];

        // 100 free − 85 demanded = 15 projected, 30 wanted
        $this->assertSame(15.0, $row['projected_position']);
        $this->assertSame(15.0, $row['suggested_quantity']);
        $this->assertSame('below_minimum', $row['reason']);
    }

    public function test_issued_material_no_longer_counts_as_demand(): void
    {
        $material = $this->material('MDF 18mm', onHand: 20);
        $line = $this->specify($material, 85);

        InventoryLog::create([
            'material_id' => $material->id, 'project_id' => $this->projectId,
            'project_material_id' => $line->id, 'type' => 'check_out',
            'quantity' => -85, 'balance_after' => 20, 'logged_at' => now(),
            'user_id' => $this->user->id,
        ]);

        $this->assertArrayNotHasKey($material->id, $this->suggestions());
    }

    public function test_the_worst_shortfall_is_listed_first(): void
    {
        $mild = $this->material('Ply 12mm', onHand: 10, minimum: 20);
        $severe = $this->material('MDF 18mm', onHand: 0);
        $this->specify($severe, 200);

        $rows = app(ReplenishmentPlanner::class)->suggestions();

        $this->assertSame($severe->id, $rows->first()['material_id']);
        $this->assertSame($mild->id, $rows->last()['material_id']);
    }

    public function test_the_endpoint_summarises_what_needs_buying(): void
    {
        $short = $this->material('MDF 18mm', onHand: 0, unitCost: 1000);
        $this->specify($short, 10);
        $this->material('Screws 40mm', onHand: 5, minimum: 15, unitCost: 100);

        $response = $this->getJson('/api/procurement-stores/replenishment-suggestions')->assertOk();

        $this->assertSame(2, $response->json('summary.materials'));
        $this->assertSame(1, $response->json('summary.job_shortfall'));
        $this->assertSame(1, $response->json('summary.below_minimum'));
        // 10 × 1000 + 10 × 100
        $this->assertSame(11000.0, (float) $response->json('summary.indicative_value'));
    }

    public function test_it_is_closed_to_anyone_without_a_stores_or_buying_role(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($outsider);

        $this->getJson('/api/procurement-stores/replenishment-suggestions')->assertStatus(403);
        $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [])
            ->assertStatus(403);
    }

    /** The whole point: it stops at a draft the buyer still owns. */
    public function test_raising_a_requisition_leaves_it_in_draft(): void
    {
        $material = $this->material('MDF 18mm', onHand: 0, unitCost: 1000);
        $this->specify($material, 10);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);

        $response = $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id,
            'items' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertStatus(201);

        $requisition = Requisition::findOrFail($response->json('data.id'));

        $this->assertSame('draft', $requisition->status);
        $this->assertNull($requisition->submitted_at);
        $this->assertNull($requisition->approved_at);
        $this->assertSame('office', $requisition->requested_by_type);
        $this->assertNull($requisition->project_id);
        $this->assertSame('10000.00', (string) $requisition->total_amount);
        $this->assertSame(1, $requisition->items()->count());
        $this->assertSame('urgent', $requisition->urgency);
    }

    /** The buyer may disagree with the arithmetic; the draft records what they asked for. */
    public function test_the_buyer_may_order_a_different_quantity(): void
    {
        $material = $this->material('MDF 18mm', onHand: 0, unitCost: 1000);
        $this->specify($material, 10);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);

        $response = $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id,
            'items' => [['material_id' => $material->id, 'quantity' => 25]],
        ])->assertStatus(201);

        $item = Requisition::findOrFail($response->json('data.id'))->items()->first();

        $this->assertSame('25.000000', (string) $item->quantity);
        $this->assertSame('1000.00', (string) $item->unit_price);
        $this->assertStringContainsString('Approved jobs need 10', $item->purpose);
    }

    public function test_a_draft_needs_a_department_and_at_least_one_line(): void
    {
        $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_automatic_priority_only_considers_selected_materials(): void
    {
        $urgent = $this->material('Urgent board', onHand: 0);
        $this->specify($urgent, 10);
        $buffer = $this->material('Buffer screws', onHand: 5, minimum: 10);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);

        $response = $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id,
            'items' => [['material_id' => $buffer->id, 'quantity' => 5]],
        ])->assertCreated();

        $this->assertSame('normal', Requisition::findOrFail($response->json('data.id'))->urgency);
    }

    public function test_existing_requests_are_visible_without_reducing_the_shortage(): void
    {
        $material = $this->material('Board', onHand: 0);
        $this->specify($material, 10);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);
        $draft = $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id,
            'items' => [['material_id' => $material->id, 'quantity' => 8]],
        ])->assertCreated();

        $row = $this->getJson('/api/procurement-stores/replenishment-suggestions')->assertOk()->json('data.0');
        $this->assertSame(10.0, (float) $row['suggested_quantity']);
        $this->assertCount(1, $row['active_requests']);
        $this->assertSame($draft->json('data.id'), $row['active_requests'][0]['id']);
        $this->assertSame(8.0, (float) $row['active_requests'][0]['quantity']);

        Requisition::findOrFail($draft->json('data.id'))->update(['status' => 'rejected']);
        $this->getJson('/api/procurement-stores/replenishment-suggestions')->assertOk()
            ->assertJsonCount(0, 'data.0.active_requests');
    }

    public function test_duplicate_material_lines_are_rejected_instead_of_silently_overwritten(): void
    {
        $material = $this->material('Board', onHand: 0);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);
        $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id,
            'items' => [
                ['material_id' => $material->id, 'quantity' => 8],
                ['material_id' => $material->id, 'quantity' => 4],
            ],
        ])->assertStatus(422);
        $this->assertSame(0, Requisition::count());
    }
}
