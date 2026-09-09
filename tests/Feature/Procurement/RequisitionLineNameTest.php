<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every requisition line can say what was asked for.
 *
 * A line names itself either by pointing at a catalogue material or by carrying
 * a description. With neither it reads as nothing for the rest of its life —
 * on the approver's list, on the order raised from it, on the bill matched
 * against that order — and the requisition arrives asking for a blank.
 *
 * The name is derived before it is demanded, because project-sourced lines keep
 * theirs in the budget snapshot rather than in the field. And it is demanded
 * only when creating: update() rebuilds every line from the request, so the
 * same rule there would re-judge records made before it existed.
 */
class RequisitionLineNameTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/procurement-stores/requisitions';

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'name' => 'Requester',
            'email' => uniqid('staff_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]));

        $this->department = Department::create(['name' => 'Operations']);
    }

    /** An office requisition carrying exactly the lines given. */
    private function payload(array ...$items): array
    {
        return [
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $this->department->id,
            'urgency' => 'normal',
            'items' => array_map(fn ($item) => array_merge([
                'quantity' => 2,
                'unit_price' => 150,
                'purpose' => 'ADM014/1.26/1',
            ], $item), $items),
        ];
    }

    private function material(): LibraryMaterial
    {
        return LibraryMaterial::create([
            'material_name' => 'Plywood 18mm',
            'material_code' => 'MAT-'.uniqid(),
            'category' => 'Boards',
            'material_type' => 'consumable',
            'unit_of_measure' => 'sheet',
            'unit_cost' => 1200,
            'item_status' => 'Active',
        ]);
    }

    public function test_a_line_naming_nothing_is_refused(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(
            ['material_id' => null, 'custom_description' => null]
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('items.0.custom_description', $response->json('error'));

        $this->assertSame(0, Requisition::count());
    }

    public function test_a_line_named_only_by_whitespace_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(
            ['material_id' => null, 'custom_description' => '   ']
        ))->assertStatus(422);

        $this->assertSame(0, Requisition::count());
    }

    /**
     * The naming runs before validation, so it sees whatever was posted. A
     * malformed line has to reach the rules as unnamed rather than crash on the
     * way, or become a line called "Array".
     */
    public function test_a_malformed_line_is_refused_rather_than_fataling(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload([
            'material_id' => null,
            'custom_description' => ['not', 'a', 'string'],
            'procurement_item_snapshot' => 'not an array',
        ]))->assertStatus(422);

        $this->assertSame(0, Requisition::count());
    }

    public function test_only_the_unnamed_line_is_refused_not_the_whole_form(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(
            ['custom_description' => 'Site signage board'],
            ['material_id' => null, 'custom_description' => null],
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('items.1.custom_description', $response->json('error'));
        $this->assertArrayNotHasKey('items.0.custom_description', $response->json('error'));
    }

    public function test_a_catalogue_line_needs_no_description_of_its_own(): void
    {
        $material = $this->material();

        $this->postJson(self::ENDPOINT, $this->payload(
            ['material_id' => $material->id, 'custom_description' => null]
        ))->assertSuccessful();

        $this->assertSame($material->id, Requisition::first()->items->first()->material_id);
    }

    public function test_a_described_line_is_kept_as_written(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(
            ['custom_description' => 'Site signage board']
        ))->assertSuccessful();

        $this->assertSame(
            'Site signage board',
            Requisition::first()->items->first()->custom_description
        );
    }

    /**
     * The project path: the requester never typed a description because the
     * Procurement task built the line from a budget element, which is where its
     * name is. Rejecting it would break raising a requisition from a project.
     */
    public function test_a_project_line_is_named_from_its_budget_snapshot(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload([
            'material_id' => null,
            'custom_description' => null,
            'procurement_item_snapshot' => [
                'elementName' => 'Reception counter',
                'description' => 'Corian sheet 12mm',
            ],
        ]))->assertSuccessful();

        $this->assertSame(
            'Corian sheet 12mm',
            Requisition::first()->items->first()->custom_description
        );
    }

    public function test_the_element_names_the_line_when_the_snapshot_has_no_description(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload([
            'material_id' => null,
            'custom_description' => null,
            'procurement_item_snapshot' => ['elementName' => 'Reception counter'],
        ]))->assertSuccessful();

        $this->assertSame(
            'Reception counter',
            Requisition::first()->items->first()->custom_description
        );
    }

    /**
     * The reason store()'s rule is not repeated on update(): editing rebuilds
     * every line, so demanding a name here would make a requisition holding one
     * unnamed legacy line impossible to save — failing on a field the requester
     * cannot see and cannot fill.
     */
    public function test_an_older_unnamed_line_does_not_make_its_requisition_uneditable(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(
            ['custom_description' => 'Site signage board']
        ))->assertSuccessful();

        $requisition = Requisition::first();

        // Written the way a line predating the rule sits in the table today.
        $requisition->items()->first()->update(['custom_description' => null]);

        $this->putJson(self::ENDPOINT.'/'.$requisition->id, [
            'items' => [[
                'material_id' => null,
                'custom_description' => null,
                'quantity' => 3,
                'unit_price' => 150,
            ]],
        ])->assertSuccessful();

        $this->assertSame(3.0, (float) $requisition->fresh()->items->first()->quantity);
    }

    /** An edit still cannot introduce a nameless line where a name exists. */
    public function test_an_edit_names_a_line_from_its_snapshot_too(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(
            ['custom_description' => 'Site signage board']
        ))->assertSuccessful();

        $requisition = Requisition::first();

        $this->putJson(self::ENDPOINT.'/'.$requisition->id, [
            'items' => [[
                'material_id' => null,
                'custom_description' => null,
                'quantity' => 1,
                'unit_price' => 150,
                'procurement_item_snapshot' => ['description' => 'Corian sheet 12mm'],
            ]],
        ])->assertSuccessful();

        $this->assertSame(
            'Corian sheet 12mm',
            $requisition->fresh()->items->first()->custom_description
        );
    }
}
