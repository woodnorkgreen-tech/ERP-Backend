<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use App\Modules\Assets\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Report 79C — a Tools & Equipment row on a loading sheet may name the
 * registered asset going to site. A reference only: it creates no hire
 * request, material, stock or cost, and other rows cannot carry one.
 */
class LoadingSheetAssetReferenceTest extends TestCase
{
    use RefreshDatabase;

    private int $taskId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'status' => 'planning', 'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Expo stand', 'contact_person' => 'Contact', 'enquiry_number' => 'ENQ-79C-'.uniqid(),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('projects')->insert(['enquiry_id' => $enquiryId, 'project_id' => 'WNG-79C-001', 'status' => 'planning', 'created_at' => now(), 'updated_at' => now()]);
        $this->taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiryId, 'type' => 'logistics', 'title' => 'Logistics',
            'status' => 'in_progress', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function asset(string $name): Asset
    {
        return Asset::create(['asset_code' => 'AST-'.uniqid(), 'name' => $name, 'status' => 'Active', 'is_available' => true, 'is_active' => true, 'qty' => 1]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/tasks/{$this->taskId}/logistics/transport-items{$suffix}";
    }

    public function test_a_tools_row_can_name_a_registered_asset_without_creating_anything_else(): void
    {
        $drill = $this->asset('Makita Drill 18V');
        $tables = ['asset_hire_requests', 'library_materials', 'stocks', 'inventory_logs', 'cost_lines', 'assets'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->postJson($this->url(), [
            'name' => $drill->name, 'quantity' => 1, 'unit' => 'pcs',
            'main_category' => 'TOOLS_EQUIPMENTS', 'is_returnable' => true, 'asset_id' => $drill->id,
        ])->assertCreated()
            ->assertJsonPath('data.asset_id', $drill->id)
            ->assertJsonPath('data.asset.asset_code', $drill->asset_code);

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertTrue((bool) $drill->fresh()->is_available, 'Referencing an asset is not a hand-over.');
    }

    public function test_only_a_tools_and_equipment_row_may_refer_to_an_asset(): void
    {
        $drill = $this->asset('Makita Drill 18V');

        $this->postJson($this->url(), [
            'name' => 'Glue', 'quantity' => 2, 'unit' => 'pcs', 'main_category' => 'STORES', 'asset_id' => $drill->id,
        ])->assertStatus(422)->assertJsonValidationErrors('asset_id');

        $id = $this->postJson($this->url(), ['name' => 'Glue', 'quantity' => 2, 'unit' => 'pcs', 'main_category' => 'STORES'])
            ->assertCreated()->json('data.id');
        $this->putJson($this->url("/{$id}"), ['asset_id' => $drill->id])->assertStatus(422)->assertJsonValidationErrors('asset_id');
    }

    public function test_the_link_can_be_set_and_removed_on_an_existing_row(): void
    {
        $generator = $this->asset('Honda Generator 5kVA');
        $id = $this->postJson($this->url(), ['name' => 'Generator', 'quantity' => 1, 'unit' => 'pcs', 'main_category' => 'TOOLS_EQUIPMENTS'])
            ->assertCreated()->json('data.id');

        $this->putJson($this->url("/{$id}"), ['asset_id' => $generator->id])->assertOk()->assertJsonPath('data.asset.id', $generator->id);
        $this->putJson($this->url("/{$id}"), ['asset_id' => null])->assertOk()->assertJsonPath('data.asset_id', null);
    }
}
