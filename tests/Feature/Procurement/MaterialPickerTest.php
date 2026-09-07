<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a requisition or an order may ask for comes from the governed catalogue,
 * not from stock on hand: you requisition what you do not have. Stock rides
 * along on each result so the requester can see what is already on the shelf
 * before asking to buy more.
 */
class MaterialPickerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/procurement-stores/material-options';

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->staff());
    }

    /** An ordinary member of staff: no roles, no library permission. */
    private function staff(): User
    {
        return User::create([
            'name' => 'Requester',
            'email' => uniqid('staff_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    private function material(string $name, float $cost, ?float $onHand, ?float $reserved = 0): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => $name,
            'material_code' => 'MAT-'.uniqid(),
            'category' => 'Boards',
            'material_type' => 'consumable',
            'unit_of_measure' => 'sheet',
            'unit_cost' => $cost,
            'item_status' => 'Active',
        ]);

        if ($onHand !== null) {
            Stock::create([
                'material_id' => $material->id,
                'quantity_on_hand' => $onHand,
                'quantity_reserved' => $reserved,
                'warehouse_code' => 'MAIN',
            ]);
        }

        return $material;
    }

    /**
     * The whole point of the endpoint: anyone who may raise a requisition can
     * read the catalogue it draws from. Gating this on materials_library.view
     * left most requesters with a picker that silently returned nothing.
     */
    public function test_anyone_who_may_raise_a_requisition_can_read_the_catalogue(): void
    {
        $this->material('Picker Plywood Sheet', 3200, onHand: null);

        $this->getJson(self::ENDPOINT.'?search=Picker+Plywood')
            ->assertOk()
            ->assertJsonPath('data.0.material_name', 'Picker Plywood Sheet')
            ->assertJsonPath('data.0.unit_cost', fn ($cost) => (float) $cost === 3200.0);
    }

    public function test_it_offers_catalogue_items_that_have_no_stock(): void
    {
        $this->material('Picker Batten', 400, onHand: null);

        $this->getJson(self::ENDPOINT.'?search=Picker+Batten')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', fn ($available) => (float) $available === 0.0);
    }

    public function test_each_result_says_what_is_already_free_on_the_shelf(): void
    {
        $this->material('Picker MDF Sheet', 5000, onHand: 7, reserved: 2);

        $this->getJson(self::ENDPOINT.'?search=Picker+MDF')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', fn ($onHand) => (float) $onHand === 7.0)
            ->assertJsonPath('data.0.available', fn ($available) => (float) $available === 5.0);
    }

    /** An unfiltered read of the catalogue is a browse, not a pick. */
    public function test_it_returns_nothing_until_something_is_typed(): void
    {
        $this->material('Picker Ply', 100, onHand: 3);

        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ENDPOINT.'?search=P')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_retired_material_is_not_offered(): void
    {
        $this->material('Picker Live Board', 100, onHand: 1);
        $retired = $this->material('Picker Retired Board', 100, onHand: 1);
        $retired->update(['item_status' => 'Inactive']);

        $names = $this->getJson(self::ENDPOINT.'?search=Picker')
            ->assertOk()
            ->json('data.*.material_name');

        $this->assertContains('Picker Live Board', $names);
        $this->assertNotContains('Picker Retired Board', $names);
    }

    public function test_buying_a_box_uses_the_box_price_and_preserves_stock_units(): void
    {
        $piece = UnitOfMeasure::create(['code' => 'PICK-PC', 'name' => 'Piece', 'dimension' => 'count', 'is_active' => true]);
        $box = UnitOfMeasure::create(['code' => 'PICK-BOX', 'name' => 'Box', 'dimension' => 'count', 'is_active' => true]);
        $material = $this->material('Picker Screws', 25, 100);
        $material->update(['base_uom_id' => $piece->id, 'purchase_uom_id' => $box->id]);
        $material->uomConversions()->create(['from_uom_id' => $box->id, 'to_uom_id' => $piece->id, 'factor' => 20]);

        $this->getJson(self::ENDPOINT.'?search=Picker+Screws')->assertOk()
            ->assertJsonPath('data.0.ordering_uom.id', $box->id)
            ->assertJsonPath('data.0.ordering_unit_cost', fn ($cost) => (float) $cost === 500.0)
            ->assertJsonPath('data.0.available', fn ($quantity) => (float) $quantity === 100.0)
            ->assertJsonPath('data.0.purchase_setup_warning', null);
    }

    public function test_missing_conversion_falls_back_to_stock_unit_with_an_explanation(): void
    {
        $piece = UnitOfMeasure::create(['code' => 'PICK-PC', 'name' => 'Piece', 'dimension' => 'count', 'is_active' => true]);
        $box = UnitOfMeasure::create(['code' => 'PICK-BOX', 'name' => 'Box', 'dimension' => 'count', 'is_active' => true]);
        $material = $this->material('Picker Screws', 25, null);
        $material->update(['base_uom_id' => $piece->id, 'purchase_uom_id' => $box->id]);

        $this->getJson(self::ENDPOINT.'?search=Picker+Screws')->assertOk()
            ->assertJsonPath('data.0.ordering_uom.id', $piece->id)
            ->assertJsonPath('data.0.ordering_unit_cost', fn ($cost) => (float) $cost === 25.0)
            ->assertJsonPath('data.0.purchase_setup_warning', fn ($warning) => str_contains($warning, 'conversion'));
    }

    public function test_individual_boards_do_not_offer_a_pack_unit(): void
    {
        $sheet = UnitOfMeasure::create(['code' => 'PICK-SH', 'name' => 'Sheet', 'dimension' => 'count', 'is_active' => true]);
        $pack = UnitOfMeasure::create(['code' => 'PICK-PACK', 'name' => 'Pack', 'dimension' => 'count', 'is_active' => true]);
        $material = $this->material('Picker Tracked Board', 0, null);
        $material->update(['base_uom_id' => $sheet->id, 'purchase_uom_id' => $pack->id,
            'default_unit_cost' => 700, 'tracking_mode' => 'dimension_piece', 'issue_disposition' => 'recoverable_remainder']);
        $material->uomConversions()->create(['from_uom_id' => $pack->id, 'to_uom_id' => $sheet->id, 'factor' => 10]);

        $this->getJson(self::ENDPOINT.'?search=Picker+Tracked')->assertOk()
            ->assertJsonPath('data.0.ordering_uom.id', $sheet->id)
            ->assertJsonPath('data.0.ordering_unit_cost', fn ($cost) => (float) $cost === 700.0);
    }

    public function test_unmapped_material_is_not_suggested_as_mdf(): void
    {
        $code = ExpenseCode::create([
            'code' => 'DM-WD-001', 'expense_type' => 'MDF boards',
            'expense_family' => 'Direct materials', 'accounting_class' => 'Direct project cost',
            'job_id_rule' => 'required', 'is_active' => true, 'is_procurable' => true,
        ]);
        config(['cost-collector.material_category_expense_codes' => [],
            'cost-collector.default_material_expense_code' => $code->code]);
        $this->material('Picker Unmapped Cable', 100, null);

        $this->getJson(self::ENDPOINT.'?search=Picker+Unmapped&job_context=true')
            ->assertOk()->assertJsonPath('data.0.suggested_expense_code', null);
    }

    public function test_a_mapped_category_is_suggested_only_in_a_compatible_context(): void
    {
        $code = ExpenseCode::create([
            'code' => 'TEST-BOARDS', 'expense_type' => 'Boards',
            'expense_family' => 'Direct materials', 'accounting_class' => 'Direct project cost',
            'job_id_rule' => 'required', 'is_active' => true, 'is_procurable' => true,
        ]);
        config(['cost-collector.material_category_expense_codes' => ['Boards' => $code->code]]);
        $this->material('Picker Mapped Board', 100, null);

        $this->getJson(self::ENDPOINT.'?search=Picker+Mapped&job_context=true')
            ->assertOk()->assertJsonPath('data.0.suggested_expense_code.id', $code->id);
        $this->getJson(self::ENDPOINT.'?search=Picker+Mapped&job_context=false')
            ->assertOk()->assertJsonPath('data.0.suggested_expense_code', null);
    }
}
