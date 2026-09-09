<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\StockMovementPostingService;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\StockCount;
use App\Modules\ProcurementStores\Models\StockCountItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Counting the stock now tells the accounts what it found.
 *
 * Before this, approving a count corrected a quantity and wrote no journal at
 * all, so the ledger's stock value and the store's could only drift apart with
 * nothing detecting it. Measured on the development database on 2026-09-08:
 * the ledger held **negative 76,580** of inventory against a store worth
 * **positive 55,910**. You cannot own less than nothing.
 *
 * The distinction these tests exist to protect is that opening inventory and a
 * later count are NOT the same accounting event. Getting them the same way round
 * would report the whole of WNG's existing store as a loss in the month the
 * books opened.
 */
class StockCountPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $this->storekeeper = User::factory()->create(['is_active' => true]);
        $this->storekeeper->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
        $this->actingAs($this->storekeeper, 'sanctum');
    }

    /**
     * A catalogue material. `$unitCost` of zero means "we do not know what this
     * is worth" — the column is NOT NULL, so zero is how the catalogue expresses
     * an unpriced item, and the posting service treats it the same way.
     */
    private function material(string $name, float $unitCost): LibraryMaterial
    {
        // material_code is NOT NULL with no default, and the catalogue calls the
        // description `material_name` rather than `name`.
        return LibraryMaterial::create([
            'material_code' => 'MAT-' . uniqid(),
            'material_name' => $name,
            'unit_cost' => $unitCost,
            'is_active' => true,
        ]);
    }

    private function countWith(string $mode, array $items): StockCount
    {
        $count = StockCount::create([
            'count_number' => 'SC-' . uniqid(),
            'mode' => $mode,
            'status' => 'submitted',
            'counted_on' => now()->toDateString(),
            'created_by' => $this->storekeeper->id,
            'reviewed_at' => now(),
        ]);

        foreach ($items as $item) {
            StockCountItem::create(array_merge([
                'stock_count_id' => $count->id,
                'system_quantity' => 0,
                'counted_quantity' => 0,
                'variance_quantity' => 0,
            ], $item));
        }

        return $count->fresh('items.material');
    }

    private function movement(string $code): array
    {
        $accountId = ChartOfAccount::where('code', $code)->value('id');
        $rows = JournalLine::where('account_id', $accountId)->get();

        return [
            'debit' => (float) $rows->where('entry_type', 'debit')->sum(fn ($l) => (float) $l->amount),
            'credit' => (float) $rows->where('entry_type', 'credit')->sum(fn ($l) => (float) $l->amount),
        ];
    }

    public function test_opening_stock_is_an_asset_against_equity_never_an_expense(): void
    {
        // The stock WNG already had when the books opened. There is no purchase
        // behind it — the buying happened before this ledger existed.
        $material = $this->material('Aluminium profile', 0);
        $count = $this->countWith(StockCount::MODE_OPENING, [
            ['material_id' => $material->id, 'counted_quantity' => 100, 'opening_unit_cost' => 250],
        ]);

        app(StockMovementPostingService::class)->postStockCount($count, $this->storekeeper->id);

        $this->assertSame(25000.0, $this->movement('1200')['debit']);
        $this->assertSame(25000.0, $this->movement('3900')['credit']);

        // The expensive mistake this guards against: charging opening stock to
        // an expense would report the whole existing store as a loss.
        $this->assertSame(0.0, $this->movement('6800')['debit']);
    }

    public function test_a_shortage_found_on_a_count_is_recorded_as_a_loss(): void
    {
        $material = $this->material('Plywood sheet', 1200);
        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $material->id, 'system_quantity' => 10, 'counted_quantity' => 7, 'variance_quantity' => -3],
        ]);

        app(StockMovementPostingService::class)->postStockCount($count, $this->storekeeper->id);

        // Stock the books claimed and the shelf does not have is a real loss —
        // breakage, theft, a mis-recorded issue — not a quiet edit to a number.
        $this->assertSame(3600.0, $this->movement('6800')['debit']);
        $this->assertSame(3600.0, $this->movement('1200')['credit']);
        $this->assertSame(0.0, $this->movement('3900')['credit']);
    }

    public function test_a_surplus_is_the_same_entry_the_other_way_round(): void
    {
        $material = $this->material('Screws box', 500);
        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $material->id, 'system_quantity' => 4, 'counted_quantity' => 6, 'variance_quantity' => 2],
        ]);

        app(StockMovementPostingService::class)->postStockCount($count, $this->storekeeper->id);

        $this->assertSame(1000.0, $this->movement('1200')['debit']);
        $this->assertSame(1000.0, $this->movement('6800')['credit']);
    }

    public function test_a_count_that_agrees_with_the_records_posts_nothing(): void
    {
        $material = $this->material('Timber batten', 300);
        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $material->id, 'system_quantity' => 5, 'counted_quantity' => 5, 'variance_quantity' => 0],
        ]);

        $this->assertNull(app(StockMovementPostingService::class)->postStockCount($count, null));
        $this->assertSame(0, JournalEntry::where('entry_no', 'like', 'JE-STK-%')->count());
    }

    public function test_a_material_with_no_cost_is_left_out_rather_than_valued_at_nothing(): void
    {
        // "We do not know what this is worth" must not become "it is worth
        // nothing" — inventing a figure puts an unsupported number in the
        // accounts. The quantity correction still stands; only the value is
        // withheld.
        $priced = $this->material('Priced material', 400);
        $unpriced = $this->material('Unpriced material', 0);

        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $priced->id, 'system_quantity' => 10, 'counted_quantity' => 8, 'variance_quantity' => -2],
            ['material_id' => $unpriced->id, 'system_quantity' => 50, 'counted_quantity' => 20, 'variance_quantity' => -30],
        ]);

        app(StockMovementPostingService::class)->postStockCount($count, null);

        $this->assertSame(800.0, $this->movement('6800')['debit']);
    }

    public function test_offsetting_differences_net_within_one_count(): void
    {
        // One entry per count, not per item: a count is a single event somebody
        // signed off, and a surplus on one material genuinely offsets a shortage
        // on another within it.
        $up = $this->material('Found material', 1000);
        $down = $this->material('Missing material', 1000);

        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $up->id, 'system_quantity' => 1, 'counted_quantity' => 4, 'variance_quantity' => 3],
            ['material_id' => $down->id, 'system_quantity' => 5, 'counted_quantity' => 4, 'variance_quantity' => -1],
        ]);

        $entry = app(StockMovementPostingService::class)->postStockCount($count, null);

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
        $this->assertSame(2000.0, $this->movement('1200')['debit']);
        $this->assertSame(2000.0, $this->movement('6800')['credit']);
    }

    public function test_a_count_cannot_post_twice(): void
    {
        $material = $this->material('Plywood sheet', 1200);
        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $material->id, 'system_quantity' => 10, 'counted_quantity' => 7, 'variance_quantity' => -3],
        ]);

        $service = app(StockMovementPostingService::class);
        $first = $service->postStockCount($count, null);
        $second = $service->postStockCount($count->fresh('items.material'), null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(3600.0, $this->movement('6800')['debit']);
    }

    public function test_a_count_cannot_be_posted_into_a_closed_month(): void
    {
        AccountingPeriod::forDate(now())->forceFill(['status' => AccountingPeriod::STATUS_CLOSED])->save();

        $material = $this->material('Plywood sheet', 1200);
        $count = $this->countWith(StockCount::MODE_CYCLE, [
            ['material_id' => $material->id, 'system_quantity' => 10, 'counted_quantity' => 7, 'variance_quantity' => -3],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(StockMovementPostingService::class)->postStockCount($count, null);
    }

    public function test_the_entry_balances_and_names_the_count(): void
    {
        $material = $this->material('Aluminium profile', 250);
        $count = $this->countWith(StockCount::MODE_OPENING, [
            ['material_id' => $material->id, 'counted_quantity' => 40, 'opening_unit_cost' => 250],
        ]);

        $entry = app(StockMovementPostingService::class)->postStockCount($count, null);

        $this->assertTrue($entry->isBalanced());
        $this->assertSame($count->count_number, $entry->source_ref);
        $this->assertSame(StockCount::class, $entry->source_type);
    }
}
