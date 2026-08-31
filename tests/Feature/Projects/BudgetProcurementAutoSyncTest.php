<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Models\TaskProcurementData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Procurement mirrors the budget's material rows. It already pulls fresh
 * figures when a user opens the procurement task, but that left every other
 * reader on stale numbers until someone happened to open it. Saving the budget
 * must now push through to procurement — while preserving the procurement-only
 * fields a buyer has filled in (vendor, PO number, stock status).
 */
class BudgetProcurementAutoSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_budget_pushes_updated_quantities_into_procurement(): void
    {
        [$budgetTask, $procurementTask] = $this->project();

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'materials_data' => [$this->budgetElement(quantity: 10)],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 500, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 500],
            'status' => 'draft',
            // Procurement only tracks a budget that is complete and sourced from
            // the approved materials list (ensureBudgetReadyForProcurement).
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'approved_materials_list'],
        ]);

        // The buyer has already worked this item: vendor chosen, PO raised.
        TaskProcurementData::create([
            'enquiry_task_id' => $procurementTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'budget_imported' => true,
            'procurement_items' => [[
                'budgetItemId' => 'mat-1',
                'budgetItemPersistentId' => 'mat-persist-1',
                'budgetElementPersistentId' => 'elem-persist-1',
                'elementName' => 'Main Stage',
                'description' => 'Timber Sheet',
                'quantity' => 10,
                'vendorName' => 'Acme Supplies',
                'purchaseOrderNumber' => 'PO-001',
                'stockStatus' => 'out_of_stock',
                'procurementStatus' => 'ordered',
            ]],
            'budget_summary' => [],
        ]);

        // Budget quantity revised 10 -> 25.
        app(BudgetService::class)->saveBudgetData($budgetTask->id, [
            'projectInfo' => ['projectId' => 'ENQ-1'],
            'materials' => [$this->budgetElement(quantity: 25)],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
        ]);

        $items = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first()->procurement_items;

        $this->assertCount(1, $items);
        $this->assertSame(25.0, (float) $items[0]['quantity'], 'Procurement must follow the budget quantity without anyone opening the task.');

        // The push must not wipe the buyer's own work.
        $this->assertSame('Acme Supplies', $items[0]['vendorName']);
        $this->assertSame('PO-001', $items[0]['purchaseOrderNumber']);
        $this->assertSame('ordered', $items[0]['procurementStatus']);
    }

    /**
     * The first import used to be a manual "Import Budget" click, which is why
     * budget additions only appeared after a sync/save/submit round trip.
     */
    public function test_first_import_happens_automatically_once_the_budget_is_ready(): void
    {
        [$budgetTask, $procurementTask] = $this->project();

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'materials_data' => [$this->budgetElement(quantity: 10)],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => [],
            'status' => 'draft',
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'approved_materials_list'],
        ]);

        // No TaskProcurementData at all — nobody has opened or imported anything.
        $this->assertNull(TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first());

        app(BudgetService::class)->saveBudgetData($budgetTask->id, [
            'projectInfo' => ['projectId' => 'ENQ-1'],
            'materials' => [$this->budgetElement(quantity: 25)],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
        ]);

        $procurementData = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first();

        $this->assertNotNull($procurementData, 'Saving a ready budget must populate procurement without a manual import.');
        $this->assertTrue((bool) $procurementData->budget_imported);
        $this->assertCount(1, $procurementData->procurement_items);
        $this->assertSame(25.0, (float) $procurementData->procurement_items[0]['quantity']);
    }

    /**
     * Materials re-approved after the budget was completed reopen that budget
     * for review. Procurement used to stop tracking it at that point and sit on
     * figures it knew were stale — it must keep following instead.
     */
    public function test_a_reopened_budget_keeps_feeding_procurement(): void
    {
        [$budgetTask, $procurementTask] = $this->project();
        $budgetTask->update(['status' => 'completed', 'completed_at' => now()]);

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'materials_data' => [$this->budgetElement(quantity: 10)],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => [],
            'status' => 'draft',
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'approved_materials_list'],
        ]);

        // Budget goes back under revision, exactly as a materials re-approval leaves it.
        $budgetTask->update(['status' => 'in_progress', 'completed_at' => null]);

        app(BudgetService::class)->saveBudgetData($budgetTask->id, [
            'projectInfo' => ['projectId' => 'ENQ-1'],
            'materials' => [$this->budgetElement(quantity: 40)],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
        ]);

        $procurementData = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first();

        $this->assertNotNull($procurementData, 'A reopened budget must still reach procurement.');
        $this->assertSame(40.0, (float) $procurementData->procurement_items[0]['quantity']);
    }

    /**
     * The regression test for the gate itself.
     *
     * Every other case here hand-writes `materials_import_metadata` into its
     * fixture. That fabricated a state the application never actually produced:
     * `syncFromMaterialsList` — the one and only importer — set
     * `materials_imported_at` but never stamped the metadata, while procurement
     * refused to import unless `metadata.source` equalled a string nothing wrote.
     * So the suite stayed green while procurement import failed on every real
     * project with "budget source is not the approved materials list".
     *
     * This drives the real importer end to end and touches no metadata, so it
     * fails if the stamp and the gate ever disagree again.
     */
    public function test_a_budget_built_by_the_real_importer_reaches_procurement(): void
    {
        [$budgetTask, $procurementTask, $materialsTask] = $this->project(withMaterialsTask: true);

        $materialsData = TaskMaterialsData::create([
            'enquiry_task_id' => $materialsTask->id,
            'project_info' => [
                'approval_status' => [
                    'all_approved' => true,
                    'project_officer' => ['approved' => true],
                    'production' => ['approved' => true],
                ],
            ],
        ]);

        $element = ProjectElement::create([
            'task_materials_data_id' => $materialsData->id,
            'element_type' => 'Stage',
            'name' => 'Main Stage',
            'persistent_id' => 'elem-persist-1',
            'category' => 'production',
            'is_included' => true,
            'sort_order' => 1,
        ]);

        ElementMaterial::create([
            'project_element_id' => $element->id,
            'persistent_id' => 'mat-persist-1',
            'description' => 'Timber Sheet',
            'unit_of_measurement' => 'Pcs',
            'quantity' => 12,
            'unit_cost' => 50,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        // The real path: no fixture stamps anything by hand.
        app(BudgetService::class)->syncFromMaterialsList($budgetTask->id);

        $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

        $this->assertSame(
            BudgetService::IMPORT_SOURCE_APPROVED_MATERIALS,
            $budgetData->materials_import_metadata['source'] ?? null,
            'The importer must stamp the provenance that procurement gates on.'
        );

        $procurementData = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first();

        $this->assertNotNull(
            $procurementData,
            'A budget built from the approved materials list must reach procurement without a manual import.'
        );
        $this->assertSame(12.0, (float) $procurementData->procurement_items[0]['quantity']);
    }

    /**
     * The gate still has to refuse a budget that positively names some other
     * origin — absent provenance is "unknown", but a foreign stamp is "no".
     */
    public function test_a_budget_stamped_with_a_foreign_source_is_refused(): void
    {
        [$budgetTask, $procurementTask] = $this->project();

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'materials_data' => [$this->budgetElement(quantity: 10)],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => [],
            'status' => 'draft',
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'manual_entry'],
        ]);

        app(BudgetService::class)->saveBudgetData($budgetTask->id, [
            'projectInfo' => ['projectId' => 'ENQ-1'],
            'materials' => [$this->budgetElement(quantity: 25)],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
        ]);

        $this->assertNull(
            TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first(),
            'A budget stamped with a non-approved source must not reach procurement.'
        );
    }

    public function test_an_unready_budget_is_never_pulled_into_procurement(): void
    {
        [$budgetTask, $procurementTask] = $this->project();

        // Nothing was ever imported — no `materials_imported_at`, so these
        // figures have no approved-materials provenance at all.
        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => 'ENQ-1'],
            'materials_data' => [$this->budgetElement(quantity: 10)],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => [],
            'status' => 'draft',
        ]);

        app(BudgetService::class)->saveBudgetData($budgetTask->id, [
            'projectInfo' => ['projectId' => 'ENQ-1'],
            'materials' => [$this->budgetElement(quantity: 25)],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
        ]);

        $this->assertNull(
            TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first(),
            'A budget that is not sourced from the approved materials list must not reach procurement.'
        );
    }

    private function budgetElement(float $quantity): array
    {
        return [
            'id' => 'elem-1',
            'persistent_id' => 'elem-persist-1',
            'name' => 'Main Stage',
            'elementType' => 'Stage',
            'category' => 'production',
            'isIncluded' => true,
            'materials' => [[
                'id' => 'mat-1',
                'persistent_id' => 'mat-persist-1',
                'description' => 'Timber Sheet',
                'unitOfMeasurement' => 'Pcs',
                'quantity' => $quantity,
                'isIncluded' => true,
                'unitPrice' => 50,
                'totalPrice' => 50 * $quantity,
            ]],
        ];
    }

    /** @return array{0: EnquiryTask, 1: EnquiryTask, 2: ?EnquiryTask} */
    private function project(bool $withMaterialsTask = false): array
    {
        $creator = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ])->fresh();

        $client = Client::create([
            'full_name' => 'Acme Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $client->id,
            'title' => 'Budget/Procurement Sync Project',
            'description' => 'Budget changes must reach procurement',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator->id,
            'selected_workflow_tasks' => $withMaterialsTask
                ? ['materials', 'budget', 'procurement']
                : ['budget', 'procurement'],
            'workflow_preset_type' => 'external_project',
        ]);

        $materialsTask = $withMaterialsTask
            ? EnquiryTask::create([
                'project_enquiry_id' => $enquiry->id,
                'title' => 'Materials',
                'type' => 'materials',
                'status' => 'completed',
                'priority' => EnquiryConstants::PRIORITY_MEDIUM,
                'task_description' => 'Materials task',
                'task_order' => 0,
                'created_by' => $creator->id,
            ])
            : null;

        $budgetTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Budget',
            'type' => 'budget',
            // Still in progress on purpose: procurement follows a budget built
            // from the approved materials list, whether or not anyone has
            // pressed "Complete Task" yet.
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Budget task',
            'task_order' => 1,
            'created_by' => $creator->id,
        ]);

        $procurementTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Procurement',
            'type' => 'procurement',
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Procurement task',
            'task_order' => 2,
            'created_by' => $creator->id,
        ]);

        return [$budgetTask, $procurementTask, $materialsTask];
    }
}
