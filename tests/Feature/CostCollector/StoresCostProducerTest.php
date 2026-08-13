<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoresCostProducerTest extends TestCase
{
    use RefreshDatabase;

    private StoresCostProducer $producer;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $this->producer = app(StoresCostProducer::class);
        $this->user = User::factory()->create();
    }

    private function createEnquiry(string $jobNumber): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Woodwork Job', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_stock_checkout_posts_actual_material_cost_line(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-001');

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-MAIN-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'MDF Board 18mm',
            'material_code' => 'MAT-MDF-18-' . uniqid(),
            'category' => 'Materials',
            'unit_of_measure' => 'sheet',
            'unit_cost' => 3500.00,
            'item_status' => 'Active',
        ]);

        $log = InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => 'check_out',
            'batch_number' => 'ISS-20260811-0001',
            'quantity' => -2.00,
            'receipt_unit_cost' => 3500.00,
            'balance_after' => 10.00,
            'project_id' => $enquiryId,
            'reference_no' => 'WNG-08-2026-001',
            'recipient_name' => 'Site Worker',
            'notes' => 'Issued for site assembly',
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        $this->assertNotNull($line);
        $this->assertSame(CostLine::NATURE_ACTUAL, $line->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame($enquiryId, $line->project_enquiry_id);
        $this->assertSame('7000.00', $line->net_amount);
        $this->assertSame('Site Worker', $line->payee_name);
        $this->assertStringContainsString('MDF Board 18mm', $line->description);
    }

    public function test_stock_checkout_without_project_is_skipped(): void
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-MAIN-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'General Cleaning Cloth',
            'material_code' => 'MAT-CLN-01-' . uniqid(),
            'category' => 'Consumables',
            'unit_of_measure' => 'pack',
            'unit_cost' => 500.00,
            'item_status' => 'Active',
        ]);

        $log = InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => 'check_out',
            'batch_number' => 'ISS-20260811-0002',
            'quantity' => -1.00,
            'receipt_unit_cost' => 500.00,
            'balance_after' => 20.00,
            'project_id' => null,
            'reference_no' => null,
            'recipient_name' => 'Office Cleaner',
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        $this->assertNull($line);
        $this->assertSame(0, CostLine::count());
    }

    public function test_partial_stock_return_posts_proportional_project_credit(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-RET');
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Returns Workshop', 'code' => 'WS-RET-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'Returnable Fixture',
            'material_code' => 'MAT-RET-' . uniqid(),
            'category' => 'Materials',
            'unit_of_measure' => 'unit',
            'unit_cost' => 1000,
            'item_status' => 'Active',
        ]);
        $issue = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id,
            'type' => 'check_out', 'batch_number' => 'ISS-RET-1', 'quantity' => -10,
            'balance_after' => 10, 'project_id' => $enquiryId,
            'reference_no' => 'WNG-08-2026-RET', 'logged_at' => now(),
        ]);
        $original = $this->producer->postStockIssue($issue);

        $return = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id,
            'type' => 'return', 'batch_number' => 'RET-1', 'quantity' => 4,
            'balance_after' => 14, 'project_id' => $enquiryId,
            'reference_no' => 'WNG-08-2026-RET', 'original_issue_log_id' => $issue->id,
            'logged_at' => now(),
        ]);
        $credit = $this->producer->postStockReturn($return);

        $this->assertSame('-4000.00', $credit?->net_amount);
        $this->assertSame($original?->id, $credit?->reversal_of_id);
        $this->assertSame($original?->consumes_line_id, $credit?->consumes_line_id);
        $this->assertNotNull($credit?->journal_entry_id);
        $this->assertSame('return_credit', $credit?->details['movement']);
    }
}
