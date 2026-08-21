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

    /**
     * A budget-priced project material line: the approved plan for one material,
     * with its total carried on a verified planned cost line.
     *
     * This mirrors production, where prices live in the budget rather than the
     * catalogue — 93% of budgets carry a unit price against 2% of catalogue
     * materials.
     */
    private function budgetPricedLine(int $enquiryId, float $quantity, string $plannedTotal): int
    {
        // project_elements hangs off a materials task, so the plan has the same
        // shape production builds: materials task → element → material line.
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'completed',
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $materialsDataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode(['projectId' => 'ENQ-' . $enquiryId]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $elementId = DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) \Illuminate\Support\Str::uuid(),
            'task_materials_data_id' => $materialsDataId,
            'element_type' => 'stand',
            'name' => 'Stand',
            'category' => 'production',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $persistentId = (string) \Illuminate\Support\Str::uuid();

        $materialLineId = DB::table('element_materials')->insertGetId([
            'persistent_id' => $persistentId,
            'project_element_id' => $elementId,
            'description' => 'Budget-priced board',
            'unit_of_measurement' => 'sheet',
            'quantity' => $quantity,
            'unit_cost' => 0,           // unpriced on the line, as in production
            'created_at' => now(), 'updated_at' => now(),
        ]);

        CostLine::create([
            'ref' => 'CL-PLAN-' . uniqid(),
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => $plannedTotal,
            'tax_amount' => '0.00',
            'net_amount' => $plannedTotal,
            'base_net_amount' => $plannedTotal,
            'fx_rate' => '1.00',
            'project_enquiry_id' => $enquiryId,
            'submitted_by_user_id' => $this->user->id,
            'details' => ['budget_category' => 'materials', 'project_material_id' => $persistentId],
        ]);

        return $materialLineId;
    }

    /** A real project row, so tests exercise the identity path production uses. */
    private function createProject(int $enquiryId, string $projectCode): int
    {
        return DB::table('projects')->insertGetId([
            'enquiry_id' => $enquiryId, 'project_id' => $projectCode,
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createMaterial(string $name, float $unitCost): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-MAIN-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => $name,
            'material_code' => 'MAT-' . uniqid(),
            'category' => 'Materials',
            'unit_of_measure' => 'sheet',
            'unit_cost' => $unitCost,
            'item_status' => 'Active',
        ]);
    }

    /**
     * An enquiry occupying a specific id, so an id collision can be constructed
     * deterministically rather than waited for.
     */
    private function enquiryWithId(int $id, string $jobNumber): int
    {
        if (DB::table('project_enquiries')->where('id', $id)->exists()) {
            DB::table('project_enquiries')->where('id', $id)->update(['job_number' => $jobNumber]);

            return $id;
        }

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Decoy Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('project_enquiries')->insert([
            'id' => $id, 'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Decoy Job', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
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

    /**
     * The producer once passed inventory_logs.project_id (a Projects key) as the
     * enquiry id. Where an unrelated enquiry happened to carry that same number,
     * the cost was charged to that enquiry's project and consumed its budget,
     * while the job number Stores wrote survived on the line and hid the swap.
     *
     * This builds exactly that collision: the issuing project's id equals a
     * different project's enquiry id.
     */
    public function test_stock_issue_resolves_identity_from_its_own_project_not_a_colliding_enquiry(): void
    {
        $realEnquiryId = $this->createEnquiry('WNG-08-2026-002');
        $realProjectId = $this->createProject($realEnquiryId, 'WNG-08-2026-003');

        // The two sequences must not already coincide, or the "other" enquiry
        // would be this project's own and there would be no collision to test.
        while ($realProjectId === $realEnquiryId) {
            $realEnquiryId = $this->createEnquiry('WNG-OFFSET-' . uniqid());
            $realProjectId = $this->createProject($realEnquiryId, 'PRJ-' . uniqid());
        }

        // Force the collision: an unrelated enquiry whose id equals the issuing
        // project's id — the exact shape that misrouted the live MDF issues.
        $decoyEnquiryId = $this->enquiryWithId($realProjectId, 'WNG-04-2026-024');
        $decoyProjectId = DB::table('projects')->where('enquiry_id', $decoyEnquiryId)->value('id')
            ?? $this->createProject($decoyEnquiryId, 'WNG-04-2026-023');

        $this->assertSame($realProjectId, $decoyEnquiryId, 'Collision fixture not constructed.');

        $material = $this->createMaterial('MDF 9mm Sheet', 1.00);

        $log = InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => 'check_out',
            'batch_number' => 'ISS-20260813-0001',
            'quantity' => -1.00,
            'receipt_unit_cost' => 1.00,
            'balance_after' => 5.00,
            'project_id' => $realProjectId,
            // Stores writes the project display code, which differs from the
            // enquiry job number on most projects.
            'reference_no' => 'WNG-08-2026-003',
            'recipient_name' => 'Storekeeper',
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        $this->assertNotNull($line);
        $this->assertSame($realProjectId, $line->project_id, 'Cost must belong to the issuing project.');
        $this->assertSame($realEnquiryId, $line->project_enquiry_id, 'Enquiry must come from the project, not the raw id.');
        $this->assertSame('WNG-08-2026-002', $line->job_number, 'Job number must be the canonical enquiry job number.');

        $this->assertNotSame($decoyProjectId, $line->project_id);
        $this->assertNotSame($decoyEnquiryId, $line->project_enquiry_id);

        // The Stores reference is kept as provenance, not published as identity.
        $this->assertSame('WNG-08-2026-003', $line->details['stores_reference'] ?? null);
    }

    /** A triple that contradicts itself must be refused, not silently resolved. */
    public function test_cost_with_job_number_from_a_different_job_is_rejected(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-002');
        $projectId = $this->createProject($enquiryId, 'WNG-08-2026-003');

        $this->expectException(\App\Modules\Finance\CostCollector\Exceptions\CostValidationException::class);

        app(\App\Modules\Finance\CostCollector\Services\CostCollectorService::class)->postFromSource(
            new \App\Modules\Finance\CostCollector\Contracts\CostContext(
                expenseCode: 'DM-WD-001',
                amount: '100.00',
                nature: CostLine::NATURE_ACTUAL,
                projectId: $projectId,
                enquiryId: $enquiryId,
                jobNumber: 'WNG-01-2026-999',
                sourceType: InventoryLog::class,
                sourceId: 999999,
                sourceRef: 'identity-guard-test',
                sourceApproved: true,
            )
        );
    }

    /** An unmatched issue is unbudgeted, never charged to an arbitrary line. */
    public function test_issue_without_an_exact_planned_line_posts_unbudgeted(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-004');
        $projectId = $this->createProject($enquiryId, 'WNG-08-2026-004');
        $material = $this->createMaterial('Adhesive 5L', 250.00);

        // A materials budget line exists for this project, but for a different
        // catalogue item. The old blind fallback would have consumed it anyway.
        $otherMaterial = $this->createMaterial('Plywood 12mm', 900.00);
        CostLine::create([
            'ref' => 'CL-TEST-PLAN', 'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED, 'project_id' => $projectId,
            'project_enquiry_id' => $enquiryId, 'job_number' => 'WNG-08-2026-004',
            'amount' => '9000.00', 'net_amount' => '9000.00', 'base_net_amount' => '9000.00',
            'details' => ['budget_category' => 'materials', 'library_material_id' => $otherMaterial->id],
        ]);

        $log = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id,
            'type' => 'check_out', 'batch_number' => 'ISS-20260813-0002',
            'quantity' => -2.00, 'receipt_unit_cost' => 250.00, 'balance_after' => 8.00,
            'project_id' => $projectId, 'reference_no' => 'WNG-08-2026-004',
            'recipient_name' => 'Storekeeper', 'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        $this->assertNotNull($line);
        $this->assertNull($line->consumes_line_id, 'An unmatched issue must not consume another material\'s budget line.');
        $this->assertTrue($line->details['unbudgeted'] ?? false);
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

    public function test_quarantine_review_caps_credit_at_accepted_recoverable_value(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-QR');
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Review Workshop', 'code' => 'WS-QR-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId, 'material_name' => 'Review Board',
            'material_code' => 'MAT-QR-' . uniqid(), 'category' => 'Boards',
            'unit_of_measure' => 'sheet', 'unit_cost' => 8000, 'item_status' => 'Active',
        ]);
        $issue = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'check_out',
            'usage_type' => 'reusable', 'batch_number' => 'ISS-QR', 'quantity' => -1,
            'balance_after' => 0, 'project_id' => $enquiryId, 'reference_no' => 'WNG-08-2026-QR', 'logged_at' => now(),
        ]);
        $original = $this->producer->postStockIssue($issue);
        $return = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'return',
            'usage_type' => 'reusable', 'batch_number' => 'RET-QR', 'quantity' => 1,
            'receipt_unit_cost' => 3000, 'balance_after' => 1, 'project_id' => $enquiryId,
            'reference_no' => 'WNG-08-2026-QR', 'original_issue_log_id' => $issue->id, 'logged_at' => now(),
        ]);

        $credit = $this->producer->postStockReturn($return);

        $this->assertSame('8000.00', $original?->net_amount);
        $this->assertSame('-3000.00', $credit?->net_amount);
    }

    public function test_a_stores_issue_records_the_element_it_served(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-095');
        $this->createProject($enquiryId, 'WNG-08-2026-095');

        $material = $this->createMaterial('MDF 9mm Sheet', 1500.0);
        $materialLineId = $this->budgetPricedLine($enquiryId, 4.0, '6000.00');

        $log = InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => 'check_out',
            'batch_number' => 'ISS-ELEM-0001',
            'quantity' => -1.00,
            'balance_after' => 9.00,
            'project_id' => $enquiryId,
            'project_material_id' => $materialLineId,
            'reference_no' => 'WNG-08-2026-095',
            'recipient_name' => 'Site Worker',
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        // Without this the cost account can total materials but cannot say which
        // part of the job consumed them — which is the question it is opened for.
        $this->assertSame('Stand', $line?->details['element'] ?? null);
    }

    public function test_an_unbudgeted_issue_still_records_its_element(): void
    {
        $enquiryId = $this->createEnquiry('WNG-08-2026-096');
        $this->createProject($enquiryId, 'WNG-08-2026-096');

        $material = $this->createMaterial('Contingency Ply', 900.0);
        $materialLineId = $this->budgetPricedLine($enquiryId, 4.0, '6000.00');

        // A different material against the same project material line finds no
        // planned counterpart, so it posts unbudgeted. That is exactly the spend
        // worth grouping, so the element must be resolved directly rather than
        // inherited — otherwise unplanned cost is the one thing that escapes the
        // breakdown meant to catch it.
        $log = InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => 'check_out',
            'batch_number' => 'ISS-ELEM-0002',
            'quantity' => -2.00,
            'balance_after' => 4.00,
            'project_id' => $enquiryId,
            'project_material_id' => $materialLineId,
            'reference_no' => 'WNG-08-2026-096',
            'recipient_name' => 'Site Worker',
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);

        $this->assertSame('Stand', $line?->details['element'] ?? null);
    }

}
