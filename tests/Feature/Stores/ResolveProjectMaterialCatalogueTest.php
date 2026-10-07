<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Giving a typed, catalogue-less approved material line a real identity from
 * the Project Material Desk — by matching an existing item or registering one
 * on the spot — the way "Quick Create and Receive" already does for a plain
 * stock receipt.
 */
class ResolveProjectMaterialCatalogueTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;

    private int $enquiryId;
    private User $user;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Stores', 'web');
        $this->grantMatrixPermissions('Stores');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Stores');
        Sanctum::actingAs($this->user->fresh());

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Stand build', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-RES-001', 'job_number' => 'WNG-RES-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // STOCK and 'l' (litre) are seeded by the item-type/UOM registry
        // migration itself — a category needs a real item type before
        // MaterialDefaultsService can resolve issue disposition, tracking
        // mode and a base unit, and a hand-rolled test row rarely has one.
        $stockTypeId = DB::table('material_item_types')->where('code', 'STOCK')->value('id');
        $this->categoryId = DB::table('material_categories')->insertGetId([
            'name' => 'Adhesives', 'code' => 'ADH', 'item_type_id' => $stockTypeId,
            'allowed_uoms' => json_encode(['l']), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function unlinkedLine(bool $approved = true, float $quantity = 10): ElementMaterial
    {
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'in_progress',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode([
                'projectId' => 'WNG-RES-001',
                'approval_status' => ['all_approved' => $approved],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $elementId = \App\Models\ProjectElement::create([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ])->id;

        return ElementMaterial::create([
            'project_element_id' => $elementId,
            'library_material_id' => null,
            'description' => 'Contact adhesive (typed, no catalogue match)',
            'unit_of_measurement' => 'litre',
            'quantity' => $quantity,
            'is_included' => true,
        ]);
    }

    private function existingMaterial(float $onHand): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => 'Contact Adhesive 1L', 'material_code' => 'MAT-'.uniqid(),
            'material_category_id' => $this->categoryId, 'category' => 'Adhesives',
            'unit_of_measure' => 'litre', 'unit_cost' => 800, 'item_status' => 'Active', 'is_active' => true,
        ]);

        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0]);

        return $material;
    }

    private function resolve(int $elementMaterialId, array $payload)
    {
        return $this->postJson("/api/procurement-stores/project-materials/{$elementMaterialId}/resolve-catalogue", $payload);
    }

    public function test_office_draft_keeps_project_link_and_shortfall_snapshot(): void
    {
        $project = \App\Models\Project::create([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-RES-PROJECT',
        ]);
        $department = \App\Modules\HR\Models\Department::create(['name' => 'Workshop']);
        $snapshot = ['elementName' => 'BOOTH1', 'description' => 'Contact adhesive', 'shortfall' => 10];
        $response = $this->postJson('/api/procurement-stores/requisitions', [
            'date' => now()->toDateString(), 'requested_by_type' => 'office',
            'department_id' => $department->id, 'project_id' => $project->id,
            'urgency' => 'normal', 'save_as_draft' => true,
            'trigger_reason' => 'Project material shortfall',
            'items' => [['custom_description' => 'Contact adhesive', 'quantity' => 10,
                'unit_price' => 100, 'procurement_item_snapshot' => $snapshot]],
        ])->assertSuccessful();
        $id = $response->json('data.id');
        $requisition = \App\Modules\ProcurementStores\Models\Requisition::findOrFail($id);
        $this->assertSame($project->id, (int) $requisition->project_id);
        $this->assertSame('Project material shortfall', $requisition->trigger_reason);
        $this->assertSame($snapshot, $requisition->items->first()->procurement_item_snapshot);
        $this->getJson('/api/procurement-stores/requisitions?project_id='.$project->id)
            ->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_matches_an_existing_material_with_enough_stock_already(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(50);

        $this->resolve($line->id, [
            'material_id' => $material->id,
            'reason' => 'Matched to the existing catalogue item.',
        ])->assertOk();

        $this->assertSame($material->id, $line->fresh()->library_material_id);
        $this->assertSame(50.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
    }

    public function test_matches_an_existing_material_and_tops_up_stock_in_one_call(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(2);

        $this->resolve($line->id, [
            'material_id' => $material->id,
            'receive' => ['quantity' => 20, 'receipt_unit_cost' => 850],
            'reason' => 'Only 2 on hand — topped up to cover the requirement.',
        ])->assertOk();

        $this->assertSame($material->id, $line->fresh()->library_material_id);
        $this->assertSame(22.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
    }

    public function test_registers_a_new_material_receives_stock_and_links_it_atomically(): void
    {
        $line = $this->unlinkedLine();

        $this->resolve($line->id, [
            'new_material' => ['material_name' => 'Contact Adhesive 1L', 'material_category_id' => $this->categoryId],
            'receive' => ['quantity' => 15, 'receipt_unit_cost' => 900],
            'reason' => 'Not in the catalogue at all — registered and received on the spot.',
        ])->assertOk();

        $line->refresh();
        $this->assertNotNull($line->library_material_id);

        $material = LibraryMaterial::find($line->library_material_id);
        $this->assertSame('Contact Adhesive 1L', $material->material_name);
        $this->assertSame('Active', $material->item_status);
        $this->assertSame(15.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
    }

    /**
     * The quick-create fields shown to the person resolving the line are not
     * decoration — they are the same governance data MaterialDefaultsService
     * would otherwise derive silently from the category, offered as an
     * editable suggestion. This pins that an explicit override actually
     * reaches the registered material rather than being swallowed by the
     * category default.
     */
    public function test_explicit_governance_overrides_reach_the_registered_material(): void
    {
        $line = $this->unlinkedLine();
        $boxId = DB::table('units_of_measure')->where('code', 'box')->value('id');
        $litreId = DB::table('units_of_measure')->where('code', 'l')->value('id');

        $this->resolve($line->id, [
            'new_material' => [
                'material_name' => 'Contact Adhesive 1L',
                'material_category_id' => $this->categoryId,
                'material_code' => 'ADH-TEST-01',
                // The category's STOCK item type defaults to "consumed" — this
                // material is a jig-mounted dispenser expected back, so the
                // storekeeper overrides it to "returnable" on the spot.
                'issue_disposition' => 'returnable',
                'tracking_mode' => 'bulk_quantity',
                'base_uom_id' => $litreId,
                'purchase_uom_id' => $boxId,
                'uom_conversions' => [['from_uom_id' => $boxId, 'factor' => 4]],
                'default_unit_cost' => 750,
            ],
            'receive' => ['quantity' => 8, 'receipt_unit_cost' => 900],
            'reason' => 'Registered with an explicit disposition and buying unit.',
        ])->assertOk();

        $material = LibraryMaterial::find($line->fresh()->library_material_id);
        $this->assertSame('ADH-TEST-01', $material->material_code);
        $this->assertSame('returnable', $material->issue_disposition);
        $this->assertSame($litreId, $material->base_uom_id);
        $this->assertSame($boxId, $material->purchase_uom_id);
        $this->assertSame(750.0, (float) $material->default_unit_cost);

        $conversion = DB::table('material_uom_conversions')->where('material_id', $material->id)->sole();
        $this->assertSame($boxId, (int) $conversion->from_uom_id);
        $this->assertSame($litreId, (int) $conversion->to_uom_id);
        $this->assertSame(4.0, (float) $conversion->factor);
    }

    public function test_a_new_material_without_a_receipt_is_allowed(): void
    {
        $line = $this->unlinkedLine();

        $res = $this->resolve($line->id, [
            'new_material' => ['material_name' => 'Contact Adhesive 1L', 'material_category_id' => $this->categoryId],
            'reason' => 'Registering material for purchasing without immediate receipt.',
        ]);

        $res->assertOk();
        $this->assertNotNull($line->fresh()->library_material_id);
        $this->assertNull($res->json('data.movement'));
    }

    public function test_naming_both_an_existing_and_a_new_material_is_refused(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(10);

        $this->resolve($line->id, [
            'material_id' => $material->id,
            'new_material' => ['material_name' => 'Duplicate', 'material_category_id' => $this->categoryId],
            'reason' => 'Ambiguous request.',
        ])->assertStatus(422)->assertJsonValidationErrors('material_id');
    }

    public function test_an_already_linked_line_cannot_be_resolved_again(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(10);
        $line->update(['library_material_id' => $material->id]);

        $another = $this->existingMaterial(10);

        $this->resolve($line->id, [
            'material_id' => $another->id,
            'reason' => 'Trying to swap an established link.',
        ])->assertStatus(422);

        $this->assertSame($material->id, $line->fresh()->library_material_id);
    }

    public function test_an_unapproved_material_list_cannot_be_resolved(): void
    {
        $line = $this->unlinkedLine(approved: false);
        $material = $this->existingMaterial(10);

        $this->resolve($line->id, [
            'material_id' => $material->id,
            'reason' => 'Plan is still a draft.',
        ])->assertStatus(422);

        $this->assertNull($line->fresh()->library_material_id);
    }

    public function test_only_stores_staff_can_resolve_a_material(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(10);

        $outsider = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($outsider->fresh());

        $this->resolve($line->id, [
            'material_id' => $material->id,
            'reason' => 'Not a Stores user.',
        ])->assertStatus(403);

        $this->assertNull($line->fresh()->library_material_id);
    }

    public function test_resolves_and_receives_consumable_unit_with_controlled_units(): void
    {
        $line = $this->unlinkedLine();
        $uomId = DB::table('units_of_measure')->where('code', 'm')->value('id')
            ?? DB::table('units_of_measure')->insertGetId(['code' => 'm', 'name' => 'Metres', 'dimension' => 'length', 'is_active' => true]);

        $res = $this->resolve($line->id, [
            'new_material' => [
                'material_name' => 'Vinyl Sticker Roll 50m',
                'material_category_id' => $this->categoryId,
                'tracking_mode' => 'consumable_unit',
                'issue_disposition' => 'consumed',
                'base_uom_id' => $uomId,
            ],
            'receive' => [
                'quantity' => 100,
                'receipt_unit_cost' => 250,
                'controlled_units' => [
                    ['quantity' => 50],
                    ['quantity' => 50],
                ],
            ],
            'reason' => 'Sticker roll registered and received as 2 physical rolls.',
        ]);

        $res->assertOk();
        $this->assertCount(2, $res->json('data.controlled_units'));
        $line->refresh();
        $this->assertNotNull($line->library_material_id);
        $material = LibraryMaterial::find($line->library_material_id);
        $this->assertSame('CONSUMABLE_UNIT', $material->tracking_method);
        $this->assertSame(100.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
        $this->assertDatabaseCount('consumable_units', 2);
    }

    public function test_resolves_existing_material_without_stock_receipt(): void
    {
        $line = $this->unlinkedLine();
        $material = $this->existingMaterial(0);

        $res = $this->resolve($line->id, [
            'material_id' => $material->id,
            'reason' => 'Goods not yet in stores; linking material for purchase requisition.',
        ]);

        $res->assertOk();
        $line->refresh();
        $this->assertSame($material->id, $line->library_material_id);
        $this->assertNull($res->json('data.movement'));
        // Stock should remain whatever it was (0)
        $this->assertSame(0.0, (float) (DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand') ?? 0));
    }

    public function test_resolves_new_material_without_stock_receipt(): void
    {
        $line = $this->unlinkedLine();
        $uomId = DB::table('units_of_measure')->where('code', 'pcs')->value('id')
            ?? DB::table('units_of_measure')->insertGetId(['code' => 'pcs', 'name' => 'Pieces', 'dimension' => 'count', 'is_active' => true]);

        $res = $this->resolve($line->id, [
            'new_material' => [
                'material_name' => 'Special Bracket No Stock Yet',
                'material_category_id' => $this->categoryId,
                'tracking_mode' => 'bulk_quantity',
                'issue_disposition' => 'consumed',
                'base_uom_id' => $uomId,
            ],
            'reason' => 'Registered new catalogue item for procurement without immediate receipt.',
        ]);

        $res->assertOk();
        $line->refresh();
        $this->assertNotNull($line->library_material_id);
        $material = LibraryMaterial::find($line->library_material_id);
        $this->assertSame('Special Bracket No Stock Yet', $material->material_name);
        $this->assertNull($res->json('data.movement'));
        $this->assertSame(0.0, (float) (DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand') ?? 0));
    }
}
