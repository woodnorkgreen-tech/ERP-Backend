<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Services\MaterialRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 79C — every way into the Material Library passes one duplicate gate.
 *
 * Exact (same words, same stock unit) is refused with the existing item;
 * similar is allowed only with each candidate acknowledged and a reason; the
 * check runs again at save time, under a lock.
 */
class MaterialCreationIntegrityTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;

    private MaterialCategory $category;
    private int $pcs;
    private int $roll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantMatrixPermissions('Stores');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Stores');
        $user->givePermissionTo(Permission::findOrCreate(Permissions::STORES_REVIEW));
        Sanctum::actingAs($user->fresh());

        $this->pcs = (int) UnitOfMeasure::firstOrCreate(['code' => 'pcs'], ['name' => 'Pieces', 'dimension' => 'count', 'is_active' => true])->id;
        $this->roll = (int) UnitOfMeasure::firstOrCreate(['code' => 'roll'], ['name' => 'Roll', 'dimension' => 'count', 'is_active' => true])->id;
        $itemType = MaterialItemType::create([
            'code' => 'R79C'.random_int(1000, 9999), 'name' => 'R79C Type',
            'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity',
            'is_stock_item' => true, 'is_active' => true,
        ]);
        $this->category = MaterialCategory::create([
            'name' => 'R79C Boards '.uniqid(), 'code' => 'R79'.random_int(1000, 9999),
            'item_type_id' => $itemType->id, 'is_active' => true, 'is_selectable' => true,
            'allowed_uoms' => ['pcs', 'roll'],
        ]);
    }

    private function existing(string $name, string $status = 'Active', ?int $uom = null): LibraryMaterial
    {
        return LibraryMaterial::create([
            'material_name' => $name, 'material_code' => 'R79C-'.uniqid(),
            'material_category_id' => $this->category->id, 'category' => 'Boards',
            'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
            'base_uom_id' => $uom ?? $this->pcs, 'item_status' => $status, 'is_active' => $status === 'Active',
        ]);
    }

    private function form(string $name, array $extra = []): array
    {
        return array_merge([
            'material_name' => $name,
            'material_category_id' => $this->category->id,
            'base_uom_id' => $this->pcs,
        ], $extra);
    }

    public function test_an_exact_duplicate_is_refused_with_the_existing_item(): void
    {
        $existing = $this->existing('MDF 18mm Standard');

        $this->postJson('/api/materials-library/materials', $this->form('standard mdf 18MM'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_EXISTS')
            ->assertJsonPath('candidates.0.id', $existing->id);

        // Acknowledging does not get past an exact duplicate.
        $this->postJson('/api/materials-library/materials', $this->form('MDF 18mm Standard', [
            'duplicate_review' => ['acknowledged' => [$existing->id], 'reason' => 'I really want another one'],
        ]))->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_EXISTS');

        $this->assertSame(1, LibraryMaterial::where('material_name', 'like', '%MDF%')->count());
    }

    public function test_an_item_waiting_for_the_custodian_counts_as_existing(): void
    {
        $pending = $this->existing('Gold Leaf Sheet', 'Under Review');

        $this->postJson('/api/materials-library/materials', $this->form('Gold leaf sheets'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_EXISTS')
            ->assertJsonPath('candidates.0.id', $pending->id);
    }

    public function test_a_different_size_needs_a_reason_and_records_the_review(): void
    {
        $eighteen = $this->existing('MDF 18mm Standard');

        $this->postJson('/api/materials-library/materials', $this->form('MDF 9mm Standard'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_CANDIDATES')
            ->assertJsonPath('candidates.0.id', $eighteen->id);

        $this->postJson('/api/materials-library/materials', $this->form('MDF 9mm Standard', [
            'duplicate_review' => ['acknowledged' => [$eighteen->id], 'reason' => 'short'],
        ]))->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_CANDIDATES');

        $created = $this->postJson('/api/materials-library/materials', $this->form('MDF 9mm Standard', [
            'duplicate_review' => ['acknowledged' => [$eighteen->id], 'reason' => 'Nine millimetre sheet, a different stock item'],
        ]))->assertCreated()->json('data.id');

        $review = LibraryMaterial::findOrFail($created)->registration_review;
        $this->assertSame('Nine millimetre sheet, a different stock item', $review['reason']);
        $this->assertSame($eighteen->id, $review['candidates_reviewed'][0]['id']);
    }

    public function test_the_same_name_in_another_stock_unit_is_similar_not_exact(): void
    {
        $rolls = $this->existing('Masking Tape 24mm', uom: $this->roll);

        $this->postJson('/api/materials-library/materials', $this->form('Masking Tape 24mm'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_CANDIDATES')
            ->assertJsonPath('candidates.0.id', $rolls->id)
            ->assertJsonPath('candidates.0.spec_differs', true);
    }

    public function test_the_pre_check_gives_the_same_verdict_as_the_save(): void
    {
        $existing = $this->existing('MDF 18mm Standard');

        $this->getJson('/api/procurement-stores/material-duplicates?name=18mm+MDF+standard&base_uom_id='.$this->pcs)
            ->assertOk()->assertJsonPath('verdict', 'exact')->assertJsonPath('exact.0.id', $existing->id);
        $this->getJson('/api/procurement-stores/material-duplicates?name=MDF+12mm+standard&base_uom_id='.$this->pcs)
            ->assertOk()->assertJsonPath('verdict', 'similar');
        $this->getJson('/api/procurement-stores/material-duplicates?name=Galvanised+wire&base_uom_id='.$this->pcs)
            ->assertOk()->assertJsonPath('verdict', 'new');
    }

    public function test_bulk_add_refuses_the_duplicate_row_and_keeps_the_rest(): void
    {
        $this->existing('Drywall Screws - 25mm');

        $existing = LibraryMaterial::where('material_name', 'Drywall Screws - 25mm')->sole();
        $batch = [
            'material_name' => 'Drywall Screws',
            'material_category_id' => $this->category->id,
            'base_uom_id' => $this->pcs,
            'variants' => [['label' => '25mm'], ['label' => 'Galvanised'], ['label' => 'Black']],
        ];

        // 25mm exists; the other two resemble it, so they wait for a reason.
        $failed = collect($this->postJson('/api/materials-library/materials/bulk', $batch)->assertStatus(422)->json('failed'))->keyBy('label');
        $this->assertSame('DUPLICATE_EXISTS', $failed['25mm']['code']);
        $this->assertSame('DUPLICATE_CANDIDATES', $failed['Galvanised']['code']);
        $this->assertSame($existing->id, $failed['Galvanised']['candidates'][0]['id']);

        // With the reason given, the distinct variants register; siblings in the
        // batch do not need acknowledging against each other.
        $response = $this->postJson('/api/materials-library/materials/bulk', array_merge($batch, [
            'duplicate_review' => ['acknowledged' => [$existing->id], 'reason' => 'Finishes, not sizes: separate stock items'],
        ]))->assertCreated();
        $this->assertSame(['Drywall Screws - Galvanised', 'Drywall Screws - Black'], collect($response->json('created'))->pluck('material_name')->all());
        $this->assertSame('DUPLICATE_EXISTS', collect($response->json('failed'))->firstWhere('label', '25mm')['code']);
    }

    public function test_receiving_cannot_register_a_duplicate_and_moves_no_stock(): void
    {
        $this->existing('Contact Adhesive 1L');
        $before = DB::table('stocks')->count();

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'receive',
            'lines' => [[
                'new_material' => ['material_name' => 'contact adhesive 1l', 'material_category_id' => $this->category->id],
                'quantity' => 4, 'receipt_unit_cost' => 450,
                'receipt_reason' => 'opening_balance', 'notes' => 'Opening stock counted on the shelf',
            ]],
        ])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_EXISTS')->assertJsonPath('line', 0);

        $this->assertSame($before, DB::table('stocks')->count());
        $this->assertSame(1, LibraryMaterial::where('material_name', 'like', '%adhesive%')->count());
    }

    public function test_a_rename_into_an_exact_duplicate_is_refused_but_other_edits_are_not(): void
    {
        $this->existing('Paint Brush 4 inch');
        $two = $this->existing('Paint Brush 2 inch');

        $this->patchJson("/api/materials-library/materials/{$two->id}", ['material_name' => 'paint brush 4 inch'])
            ->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_EXISTS');

        $this->patchJson("/api/materials-library/materials/{$two->id}", ['notes' => 'Synthetic bristles'])->assertOk();
    }

    public function test_restoring_a_deleted_item_that_now_has_a_twin_is_refused(): void
    {
        $old = $this->existing('Spacer 10mm');
        $old->delete();
        $this->existing('Spacer 10mm');

        $this->postJson("/api/materials-library/materials/{$old->id}/restore")
            ->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_EXISTS');
        $this->assertTrue(LibraryMaterial::withTrashed()->findOrFail($old->id)->trashed());
    }

    public function test_an_import_row_that_duplicates_an_item_is_rejected_and_a_similar_one_is_recorded(): void
    {
        $existing = $this->existing('MDF 18mm Standard');
        $service = app(MaterialRegistrationService::class);

        try {
            $service->create($this->form('MDF 18mm standard'), (int) auth()->id(), ['automatic_reason' => 'Imported from the material workbook while similar items existed.']);
            $this->fail('An exact duplicate must be refused even on import.');
        } catch (ValidationException $exception) {
            $this->assertSame('DUPLICATE_EXISTS', $exception->response?->getData(true)['code']);
        }

        $similar = $service->create($this->form('MDF 25mm Standard'), (int) auth()->id(), ['automatic_reason' => 'Imported from the material workbook while similar items existed.']);
        $this->assertTrue($similar->registration_review['automatic']);
        $this->assertSame($existing->id, $similar->registration_review['candidates_reviewed'][0]['id']);
    }

    public function test_resolve_request_new_uses_the_same_gate(): void
    {
        $existing = $this->existing('Gold Leaf Sheet', 'Under Review');

        $this->getJson('/api/procurement-stores/material-duplicates?name=gold+leaf+sheet')
            ->assertJsonPath('exact.0.id', $existing->id);
        // The Resolve endpoint's candidate list is the gate's list.
        $this->assertSame('exact', app(\App\Modules\MaterialsLibrary\Services\MaterialDuplicateGuard::class)
            ->check(['material_name' => 'Gold leaf sheets', 'material_category_id' => $this->category->id])['verdict']);
    }
}
