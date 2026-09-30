<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\StockMovementPostingService;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StockCount;
use App\Modules\ProcurementStores\Models\StockCountItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

/**
 * W5 Finance-facing inventory (Report 61): a read workspace over what Stores
 * owns. The accounting chain itself (GRN → ACCRUED in Inventory, issue →
 * ACTUAL in WIP, accrual retired on issue, bill settles the accrual without a
 * second cost) is proven in SettlementAccountTest and SupplierLedgerRailTest;
 * these tests prove the Finance screen reports that chain truthfully.
 */
class InventoryFinanceTest extends TestCase
{
    use GrantsMatrixPermissions;
    use RefreshDatabase;

    private User $finance;
    private User $storekeeper;
    private LibraryMaterial $material;
    private JournalPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->posting = app(JournalPostingService::class);

        $this->grantMatrixPermissions('Accounts', 'Stores');
        $this->finance = User::factory()->create(['is_active' => true, 'email' => 'finance@wng.test']);
        $this->finance->assignRole('Accounts');
        $this->storekeeper = User::factory()->create(['is_active' => true, 'email' => 'stores@wng.test']);
        $this->storekeeper->assignRole('Stores');

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'General', 'code' => 'WS-GEN-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->material = LibraryMaterial::create([
            'workstation_id' => $workstationId, 'material_name' => 'Contact Adhesive 5L', 'material_code' => 'MAT-ADH-'.uniqid(),
            'category' => 'Consumables', 'material_type' => 'consumable', 'tracking_mode' => 'bulk_quantity',
            'issue_disposition' => 'consumed', 'unit_of_measure' => 'tin', 'unit_cost' => 100,
            'item_status' => 'Active', 'valuation_method' => 'Weighted Average',
        ]);
        Stock::create(['material_id' => $this->material->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
    }

    private function postedLine(array $overrides): CostLine
    {
        $line = CostLine::create(array_merge([
            'ref' => 'CL-'.uniqid(), 'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => '1000.00', 'tax_amount' => '0.00', 'net_amount' => '1000.00', 'base_net_amount' => '1000.00',
            'fx_rate' => '1.00', 'submitted_by_user_id' => $this->finance->id,
        ], $overrides));
        $this->posting->postCostLine($line);

        return $line->fresh();
    }

    private function accrual(string $amount, ?int $materialId): CostLine
    {
        return $this->postedLine([
            'nature' => CostLine::NATURE_ACCRUED, 'source_ref' => 'accrual',
            'amount' => $amount, 'net_amount' => $amount, 'base_net_amount' => $amount,
            'details' => $materialId ? ['library_material_id' => $materialId] : ['description' => 'Site survey service'],
        ]);
    }

    private function enquiry(): int
    {
        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(),
            'client_id' => DB::table('clients')->insertGetId([
                'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi',
                'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
                'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]),
            'title' => 'Activation', 'contact_person' => 'Contact', 'enquiry_number' => 'ENQ-'.uniqid(),
            'job_number' => 'WNG-INV-1', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inventory_figures_are_a_finance_read_not_a_stores_one(): void
    {
        foreach (['position', 'issues', 'adjustments'] as $endpoint) {
            $this->actingAs($this->storekeeper)->getJson("/api/finance/inventory/{$endpoint}")->assertForbidden();
            $this->actingAs($this->finance)->getJson("/api/finance/inventory/{$endpoint}")->assertOk();
        }
    }

    public function test_the_stores_valuation_agrees_with_the_stores_screen_and_reconciles_to_the_inventory_account(): void
    {
        // Received through a GRN: Dr Inventory 1,000 / Cr Accrued 1,000.
        $this->accrual('1000.00', $this->material->id);

        $position = $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')->assertOk()->json('data');

        $this->assertSame('Moving weighted average', $position['valuation']['method']);
        $this->assertSame(['Weighted Average'], $position['valuation']['material_methods']);
        $this->assertSame('1000.00', $position['valuation']['total']);
        $this->assertSame('1000.00', $position['reconciliation']['inventory_gl']);
        $this->assertSame('0.00', $position['reconciliation']['difference']);
        $this->assertTrue($position['reconciliation']['reconciled']);
        $this->assertSame('1200', $position['reconciliation']['inventory_account']['code']);
        $this->assertSame(1, $position['received_not_issued']['stock']['count']);

        // The Stores screen values the same stock the same way.
        $this->assertEquals(1000, $this->actingAs($this->storekeeper)->getJson('/api/procurement-stores/inventory')
            ->assertOk()->json('summary.total_value'));
    }

    public function test_a_non_stock_receipt_is_shown_as_a_named_contributor_to_the_difference(): void
    {
        $this->accrual('1000.00', $this->material->id);
        // A service line forced through a GRN: it sits in Inventory, but it is not stock.
        $this->accrual('300.00', null);

        $position = $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')->json('data');

        $this->assertSame('1300.00', $position['reconciliation']['inventory_gl']);
        $this->assertSame('-300.00', $position['reconciliation']['difference']);
        $this->assertFalse($position['reconciliation']['reconciled']);
        $contributor = collect($position['reconciliation']['contributors'])->firstWhere('key', 'non_stock_receipts');
        $this->assertSame(1, $contributor['count']);
        $this->assertSame('300.00', $contributor['amount']);
        $this->assertSame(['count' => 1, 'amount' => '300.00'], $position['received_not_issued']['non_stock']);

        // No opening inventory has been approved, so the screen says so (and creates nothing).
        $opening = collect($position['reconciliation']['contributors'])->firstWhere('key', 'opening_inventory_missing');
        $this->assertSame(1, $opening['count']);
        $this->assertFalse($position['opening_inventory_approved']);
    }

    public function test_a_project_issue_is_listed_as_actual_cost_with_safe_identities(): void
    {
        $enquiryId = $this->enquiry();
        $this->postedLine([
            'source_type' => InventoryLog::class, 'source_id' => 55, 'source_ref' => 'stock-issue',
            'project_enquiry_id' => $enquiryId, 'job_number' => 'WNG-INV-1', 'description' => 'Adhesive to site',
            'details' => ['library_material_id' => $this->material->id, 'stores_reference' => 'ISS-001', 'quantity' => 2],
            'amount' => '200.00', 'net_amount' => '200.00', 'base_net_amount' => '200.00',
        ]);

        $response = $this->actingAs($this->finance)->getJson('/api/finance/inventory/issues')->assertOk();
        $row = $response->json('data.0');

        $this->assertSame('issue', $row['kind']);
        $this->assertSame(CostLine::NATURE_ACTUAL, $row['nature']);
        $this->assertSame('200.00', $row['value']);
        $this->assertTrue($row['posted']);
        $this->assertSame('ISS-001', $row['stores_reference']);
        $this->assertSame(['id' => $enquiryId, 'job_number' => 'WNG-INV-1', 'title' => 'Activation'], $row['project']);
        $this->assertSame(['id' => $this->material->id, 'name' => 'Contact Adhesive 5L'], $row['material']);
        $this->assertStringNotContainsString('@wng.test', $response->getContent());

        $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')
            ->assertJsonPath('data.project_issues.count', 1)->assertJsonPath('data.project_issues.net_value', '200.00');
    }

    public function test_adjustments_say_which_reached_the_ledger(): void
    {
        // A write-off: no posting rule exists, so it did not reach the ledger.
        $writeOff = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => $this->storekeeper->id, 'type' => 'defective',
            'quantity' => 2, 'balance_after' => 8, 'logged_at' => now(), 'notes' => 'Tins dented',
        ]);

        // An approved cycle count: posts once to Inventory Adjustments (INV-001, 6800).
        $counted = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => $this->storekeeper->id, 'type' => 'adjustment',
            'quantity' => -1, 'balance_after' => 10, 'logged_at' => now(), 'reference_no' => 'SC-001',
        ]);
        $count = StockCount::create([
            'count_number' => 'SC-001', 'mode' => StockCount::MODE_CYCLE, 'warehouse_code' => 'MAIN', 'status' => 'approved',
            'counted_on' => now()->toDateString(), 'created_by' => $this->storekeeper->id,
            'reviewed_by' => $this->finance->id, 'reviewed_at' => now(),
        ]);
        StockCountItem::create([
            'stock_count_id' => $count->id, 'material_id' => $this->material->id, 'system_quantity' => 11,
            'counted_quantity' => 10, 'variance_quantity' => -1, 'adjustment_log_id' => $counted->id,
        ]);
        $this->assertNotNull(app(StockMovementPostingService::class)->postStockCount($count->fresh('items.material')));

        $rows = collect($this->actingAs($this->finance)->getJson('/api/finance/inventory/adjustments')->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($rows[$writeOff->id]['posted_to_ledger']);
        $this->assertSame('write_off', $rows[$writeOff->id]['source']);
        $this->assertSame('200.00', $rows[$writeOff->id]['value_at_current_average']);
        $this->assertSame('6800', $rows[$writeOff->id]['mapped_account']['code']);

        $this->assertTrue($rows[$counted->id]['posted_to_ledger']);
        $this->assertSame('stock_count', $rows[$counted->id]['source']);
        $this->assertSame('JE-STK-'.str_pad((string) $count->id, 7, '0', STR_PAD_LEFT), $rows[$counted->id]['journal_entry']['entry_no']);

        // Only the write-off is counted as an unposted contributor.
        $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')
            ->assertJsonPath('data.adjustments.count', 1)
            ->assertJsonPath('data.adjustments.value_at_current_average', '200.00');
    }

    public function test_the_wip_policy_is_reported_as_configured_and_never_assumed(): void
    {
        config(['finance_accounts.wip_policy' => null]);
        $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')->assertJsonPath('data.wip_policy', null);

        config(['finance_accounts.wip_policy' => 'capitalise']);
        $this->actingAs($this->finance)->getJson('/api/finance/inventory/position')->assertJsonPath('data.wip_policy', 'capitalise');
    }
}
