<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Report 79B — one catalogue search behind every picker.
 *
 * The BOM, Resolve, procurement and Stores screens read three endpoints. All
 * three now rank through MaterialSearch, so the same text finds the same item
 * first wherever it is typed, and searching never writes anything.
 */
class IntelligentMaterialSearchTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = '/api/procurement-stores/material-options';

    private function material(string $name, string $status = 'Active', ?float $onHand = null): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => $name,
            'material_code' => 'R79B-'.uniqid(),
            'category' => 'Boards',
            'material_type' => 'consumable',
            'unit_of_measure' => 'sheet',
            'unit_cost' => 100,
            'item_status' => $status,
            'is_active' => $status === 'Active',
        ]);
        if ($onHand !== null) {
            Stock::create(['material_id' => $material->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'warehouse_code' => 'MAIN']);
        }

        return $material;
    }

    private function actAs(array $permissions = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::create(['name' => 'Searcher', 'email' => uniqid('s_').'@test.local', 'password' => bcrypt('x'), 'is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_words_in_any_order_find_the_item_first(): void
    {
        $this->actAs();
        $wanted = $this->material('MDF 18mm Standard');
        $this->material('MDF 9mm Sheet');
        $this->material('Masking Tape 24mm');

        $response = $this->getJson(self::OPTIONS.'?search=18mm+mdf')->assertOk();

        $response->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.match.tier', 'likely');
        // The 9mm board may be listed, but it is labelled as a different size.
        $nine = collect($response->json('data'))->firstWhere('material_name', 'MDF 9mm Sheet');
        $this->assertTrue($nine === null || $nine['match']['spec_differs'] === true);
    }

    public function test_a_typo_still_finds_the_item_but_is_not_called_exact(): void
    {
        $this->actAs();
        $wanted = $this->material('Plywood 18mm Hardwood');

        $this->getJson(self::OPTIONS.'?search=plywod+18mm')
            ->assertOk()
            ->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.match.reason', 'Close spelling')
            ->assertJsonPath('verdict', 'likely');
    }

    public function test_under_review_items_appear_only_when_asked_for_and_are_labelled(): void
    {
        $this->actAs();
        $pending = $this->material('Gold Leaf Sheet', 'Under Review');

        $this->getJson(self::OPTIONS.'?search=gold+leaf')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson(self::OPTIONS.'?search=gold+leaf&include_under_review=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.item_status', 'Under Review');
    }

    public function test_retired_items_are_never_offered_even_with_under_review(): void
    {
        $this->actAs();
        $this->material('Old Chipboard 12mm', 'Inactive');

        $this->getJson(self::OPTIONS.'?search=chipboard&include_under_review=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_earlier_bom_link_for_the_same_description_ranks_first(): void
    {
        $this->actAs();
        $this->material('Board Panel 18mm Grey');
        $linked = $this->material('Plywood 18mm Hardwood');
        $this->linkedBomLine('board 18mm', $linked->id);

        $this->getJson(self::OPTIONS.'?search=board+18mm&history=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $linked->id);
    }

    public function test_stores_inventory_and_the_library_rank_the_same_way(): void
    {
        $this->actAs([Permissions::MATERIALS_LIBRARY_VIEW]);
        $wanted = $this->material('Masking Tape 24mm', onHand: 3);
        $this->material('Laser Masking Tape Wide', onHand: 3);

        $this->getJson('/api/procurement-stores/inventory?search=tape+masking+24mm&include_unstocked=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $wanted->id)
            ->assertJsonPath('data.data.0.match.tier', 'exact');

        $this->getJson('/api/materials-library/materials?search=tape+masking+24mm')
            ->assertOk()
            ->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.match.tier', 'exact');

        $this->getJson(self::OPTIONS.'?search=tape+masking+24mm')
            ->assertOk()
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_searching_writes_nothing(): void
    {
        $this->actAs([Permissions::MATERIALS_LIBRARY_VIEW]);
        $this->material('MDF 18mm Standard', onHand: 5);
        $tables = ['library_materials', 'stocks', 'inventory_logs', 'cost_lines', 'journal_entries', 'element_materials'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->getJson(self::OPTIONS.'?search=mdf+18&include_under_review=1&history=1')->assertOk();
        $this->getJson('/api/procurement-stores/inventory?search=mdf+18')->assertOk();
        $this->getJson('/api/materials-library/materials?search=mdf+18')->assertOk();

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
    }

    /** A BOM line already linked to an item under the given description. */
    private function linkedBomLine(string $description, int $materialId): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'status' => 'planning', 'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'R79B job', 'contact_person' => 'Contact', 'enquiry_number' => 'ENQ-79B-'.uniqid(),
            'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiryId, 'type' => 'materials', 'title' => 'Materials',
            'status' => 'in_progress', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId, 'project_info' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $elementId = \App\Models\ProjectElement::create([
            'persistent_id' => (string) \Illuminate\Support\Str::uuid(), 'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'DESK', 'category' => 'production', 'is_included' => true,
        ])->id;
        DB::table('element_materials')->insert([
            'persistent_id' => (string) \Illuminate\Support\Str::uuid(), 'project_element_id' => $elementId,
            'description' => $description, 'unit_of_measurement' => 'Pcs', 'quantity' => 1,
            'library_material_id' => $materialId, 'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
