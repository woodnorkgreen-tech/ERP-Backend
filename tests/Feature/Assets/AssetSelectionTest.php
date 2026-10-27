<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetHireRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Report 79B — choosing a registered asset.
 *
 * The hire forms loaded the first 200 available assets with no search and
 * offered assets that were Retired or In Repair. The picker endpoint is
 * searched and paged and says why an asset cannot be requested; approval,
 * the actual hand-over, locks the asset so it cannot go out twice.
 */
class AssetSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Super Admin');
        $this->user = User::create(['name' => 'Lead', 'email' => uniqid('a_').'@test.local', 'password' => bcrypt('x'), 'is_active' => true]);
        $this->user->assignRole('Super Admin');
        Sanctum::actingAs($this->user->fresh());
    }

    private function asset(string $name, array $extra = []): Asset
    {
        return Asset::create(array_merge([
            'asset_code' => 'AST-'.uniqid(),
            'name' => $name,
            'status' => 'Active',
            'is_available' => true,
            'is_active' => true,
            'condition' => 'Good',
            'location' => 'Main Store',
            'qty' => 1,
        ], $extra));
    }

    public function test_search_finds_words_in_any_order_and_pages_past_the_old_limit(): void
    {
        foreach (range(1, 25) as $n) {
            $this->asset("Folding Chair {$n}");
        }
        $wanted = $this->asset('Honda Generator 5kVA');

        $this->getJson('/api/assets/options?search=generator+honda')
            ->assertOk()
            ->assertJsonPath('data.0.id', $wanted->id)
            ->assertJsonPath('data.0.offerable', true);

        $this->getJson('/api/assets/options?search=chair&page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(5, 'data');
    }

    public function test_an_asset_not_in_service_is_shown_but_not_offerable(): void
    {
        $this->asset('Projector Epson', ['status' => 'In Repair']);
        $this->asset('Projector Benq', ['status' => 'Retired']);
        $ready = $this->asset('Projector Sony');

        $rows = collect($this->getJson('/api/assets/options?search=projector')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame($ready->id, $this->getJson('/api/assets/options?search=projector')->json('data.0.id'));
        $this->assertFalse($rows['Projector Epson']['offerable']);
        $this->assertStringContainsString('In Repair', $rows['Projector Epson']['unavailable_reason']);
        $this->assertFalse($rows['Projector Benq']['offerable']);

        $this->getJson('/api/assets/options?search=projector&offerable_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id);
    }

    public function test_a_request_for_an_asset_not_in_service_is_refused(): void
    {
        $asset = $this->asset('Laser Level', ['status' => 'Lost']);

        $this->postJson('/api/assets/hire-requests', $this->requestFor($asset))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This asset is Lost and cannot be handed out.');
    }

    public function test_one_asset_cannot_be_approved_out_twice(): void
    {
        $asset = $this->asset('Scissor Lift');
        $first = $this->postJson('/api/assets/hire-requests', $this->requestFor($asset))->assertCreated()->json('data.id');
        $second = $this->postJson('/api/assets/hire-requests', $this->requestFor($asset))->assertCreated()->json('data.id');

        $this->postJson("/api/assets/hire-requests/{$first}/approve")->assertOk();
        $this->postJson("/api/assets/hire-requests/{$second}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This asset is not currently available.');

        $this->assertSame(1, AssetHireRequest::where('asset_id', $asset->id)->where('status', 'approved')->count());
        $this->getJson('/api/assets/options?search=scissor+lift')
            ->assertJsonPath('data.0.offerable', false)
            ->assertJsonPath('data.0.holder', 'Lead');
    }

    public function test_choosing_an_asset_creates_no_material_stock_or_cost(): void
    {
        $asset = $this->asset('Pressure Washer');
        $tables = ['library_materials', 'stocks', 'inventory_logs', 'cost_lines', 'journal_entries', 'assets'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->getJson('/api/assets/options?search=washer')->assertOk();
        $id = $this->postJson('/api/assets/hire-requests', $this->requestFor($asset))->assertCreated()->json('data.id');
        $this->postJson("/api/assets/hire-requests/{$id}/approve")->assertOk();

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertSame($asset->id, (int) AssetHireRequest::findOrFail($id)->asset_id);
    }

    private function requestFor(Asset $asset): array
    {
        return [
            'asset_id' => $asset->id,
            'request_type' => 'hire',
            'for_user_id' => $this->user->id,
            'out_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'purpose' => 'Site setup',
        ];
    }
}
