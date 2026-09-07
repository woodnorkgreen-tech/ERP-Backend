<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Material bought for stock is an asset until it is consumed, so an accepted
 * delivery has to reach the books as `Dr Raw-material Inventory / Cr Accrued
 * Expenses`. If it does not, the stores issue that follows still credits
 * Inventory — relieving stock from a shelf the books never recorded it arriving
 * on, and leaving the supplier liability unrecorded.
 *
 * The accrual was skipped for any line without an expense code, by a bare
 * `continue` that logged nothing and failed nothing. These tests pin both ends
 * of the fix: the receipt now says so out loud, and a requisition can no longer
 * reach approval carrying the lines that cause it.
 */
class GoodsReceiptAccrualTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);

        Role::findOrCreate('Accounts', 'web');
        $this->accounts = User::create([
            'name' => 'Accounts Clerk',
            'email' => uniqid('accounts_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->accounts->assignRole('Accounts');
        Sanctum::actingAs($this->accounts);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd',
            'contact_person' => 'Supplier Contact',
            'phone' => '0700000002',
            'email' => uniqid('supplier_').'@test.local',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->accounts->id,
        ]);

        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store',
            'description' => 'Board stock',
            'total_amount' => 50000,
            'status' => 'approved',
            'user_id' => $this->accounts->id,
            'approved_at' => now(),
            'approved_by' => $this->accounts->id,
        ]);
    }

    private function expenseCode(): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'TST-MAT-001',
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct expenses',
            'expense_type' => 'Boards and panels',
            'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
            'is_active' => true,
        ]);
    }

    private function requisition(string $status = 'approved'): Requisition
    {
        return Requisition::create([
            'requisition_number' => 'PR-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'urgency' => 'normal',
            'total_amount' => 50000,
            'status' => $status,
            'user_id' => $this->accounts->id,
        ]);
    }

    /** One order line, traced back to a requisition line that may or may not be coded. */
    private function orderLine(Requisition $requisition, ?ExpenseCode $code, float $unitPrice = 5000): PurchaseOrderItem
    {
        $reqItem = RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'custom_description' => 'MDF 18mm sheet',
            'expense_code_id' => $code?->id,
            'quantity' => 5,
            'unit_price' => $unitPrice,
            'total' => 5 * $unitPrice,
            'purpose' => 'Workshop stock',
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id,
            'requisition_item_id' => $reqItem->id,
            'custom_description' => 'MDF 18mm sheet',
            'quantity' => 5,
            'unit_price' => $unitPrice,
            'total' => 5 * $unitPrice,
        ]);
    }

    /** @param  PurchaseOrderItem[]  $orderItems */
    private function deliver(array $orderItems): GoodsReceiptNote
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id,
            'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store',
            'quality_check' => 'pass',
            'store_status' => 'confirmed',
            'received_by' => $this->accounts->id,
        ]);

        foreach ($orderItems as $orderItem) {
            GoodsReceiptNoteItem::create([
                'goods_receipt_note_id' => $note->id,
                'purchase_order_item_id' => $orderItem->id,
                'ordered_quantity' => 5,
                'received_quantity' => 5,
                'condition' => 'good',
                'accepted' => true,
                'store_status' => 'confirmed',
                'stock_status' => 'posted',
                'unit_price' => $orderItem->unit_price,
            ]);
        }

        return $note;
    }

    public function test_an_accepted_delivery_becomes_a_stock_asset_and_a_supplier_liability(): void
    {
        $requisition = $this->requisition();
        $note = $this->deliver([$this->orderLine($requisition, $this->expenseCode())]);

        app(ProcurementCostProducer::class)->postGoodsReceipt($note->id);

        $accrual = CostLine::where('nature', CostLine::NATURE_ACCRUED)->firstOrFail();
        $this->assertSame('25000.00', $accrual->net_amount);

        // Dr Raw-material Inventory, Cr Accrued Expenses — not project WIP, which
        // is only reached when the material is actually issued to a job.
        $legs = $accrual->journalEntry->lines
            ->mapWithKeys(fn ($leg) => [$leg->account->code => $leg->entry_type]);

        $this->assertSame('debit', $legs['1200'], 'Receipt must debit Raw-material Inventory.');
        $this->assertSame('credit', $legs['2150'], 'Receipt must credit Accrued Expenses.');
    }

    /**
     * An unclassified delivery is still a delivery.
     *
     * The bare `continue` this replaces is why nothing ever reached Accrued
     * Expenses. Refusing outright would have been no better: the accrual is the
     * entry that debits Inventory on receipt, and withholding it leaves the
     * issue that follows crediting stock the books never recorded arriving.
     * The journal needs no code — both legs are fixed by nature — so the line
     * posts and is marked unclaimable instead.
     */
    public function test_an_uncoded_delivery_line_still_accrues_and_is_marked(): void
    {
        $requisition = $this->requisition();
        $note = $this->deliver([$this->orderLine($requisition, null)]);

        app(ProcurementCostProducer::class)->postGoodsReceipt($note->id);

        $accrual = CostLine::where('nature', CostLine::NATURE_ACCRUED)->firstOrFail();
        $this->assertNull($accrual->expense_code_id);
        $this->assertNull($accrual->vat_treatment_id, 'With no code there is no VAT to claim.');
        $this->assertTrue($accrual->details['unclassified_expense_code'] ?? false);

        // The entry that matters still lands: stock in, supplier owed.
        $legs = $accrual->journalEntry->lines
            ->mapWithKeys(fn ($leg) => [$leg->account->code => $leg->entry_type]);
        $this->assertSame('debit', $legs['1200']);
        $this->assertSame('credit', $legs['2150']);
    }

    /** A classified line is not marked, so the flag stays a real signal. */
    public function test_a_coded_delivery_line_is_not_marked_unclassified(): void
    {
        $requisition = $this->requisition();
        $note = $this->deliver([$this->orderLine($requisition, $this->expenseCode())]);

        app(ProcurementCostProducer::class)->postGoodsReceipt($note->id);

        $accrual = CostLine::where('nature', CostLine::NATURE_ACCRUED)->firstOrFail();
        $this->assertArrayNotHasKey('unclassified_expense_code', $accrual->details ?? []);
    }

    /** Finance completes the category before approval; legacy receipts still accrue. */
    public function test_a_requisition_needs_a_category_before_approval(): void
    {
        $requisition = $this->requisition('pending_approval');
        $this->orderLine($requisition, null);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CODE_REQUIRED');

        $this->assertSame('pending_approval', $requisition->fresh()->status);
    }

    public function test_a_project_request_can_be_saved_without_a_category(): void
    {
        $project = $this->projectWithEnquiry('WNG-CATEGORY-TEST');
        $response = $this->postJson('/api/procurement-stores/requisitions', [
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $project['project_id'],
            'urgency' => 'normal',
            'items' => [[
                'custom_description' => 'Purchase awaiting Finance classification',
                'expense_code_id' => null,
                'quantity' => 1,
                'unit_price' => 100,
                'purpose' => 'project_use',
            ]],
        ])->assertSuccessful();

        $this->assertDatabaseHas('requisition_items', [
            'requisition_id' => $response->json('data.id'),
            'expense_code_id' => null,
            'project_enquiry_id' => $project['enquiry_id'],
        ]);
    }

    public function test_a_staff_payment_category_cannot_be_approved_for_procurement(): void
    {
        $code = $this->expenseCode();
        $code->update(['is_procurable' => false, 'expense_type' => 'Site allowance and overtime']);
        $requisition = $this->requisition('pending_approval');
        $this->orderLine($requisition, $code);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CODE_NOT_PROCURABLE');
        $this->assertSame('pending_approval', $requisition->fresh()->status);
    }

    /** A project with a real enquiry behind it, as procurement actually links them. */
    private function projectWithEnquiry(string $jobNumber): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A decoy first, so the enquiry's id can never coincide with the
        // project's. Without it the assertion passes on a fresh database purely
        // because both tables start at 1, and would keep passing with the bug
        // back in place.
        DB::table('project_enquiries')->insert([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Decoy', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => 'WNG-00-2026-000',
            'created_by' => $this->accounts->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Store takeover', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->accounts->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'project_id' => 'PRJ-'.uniqid(), 'enquiry_id' => $enquiryId,
            'current_phase' => 1, 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['project_id' => $projectId, 'enquiry_id' => $enquiryId];
    }

    /**
     * The live defect: `requisition_items.project_enquiry_id` held a Projects
     * primary key, so the collector was asked to own a cost against an enquiry
     * that does not exist and the whole purchase order failed to commit.
     */
    public function test_a_project_id_standing_in_for_an_enquiry_id_resolves_to_the_real_enquiry(): void
    {
        ['project_id' => $projectId, 'enquiry_id' => $enquiryId] =
            $this->projectWithEnquiry('WNG-03-2026-058');

        $requisition = $this->requisition();
        $requisition->update([
            'requested_by_type' => 'project',
            'project_id' => $projectId,
            // As stored today: a display string, not something resolvable.
            'job_number' => 'WNG-03-2026-058 - LRP EFFACLAR STORE TAKEOVER',
        ]);

        // The order is raised from that requisition, as procurement links them —
        // this is the path identityFor() actually reads.
        $this->order->update(['requisition_id' => $requisition->id]);

        $orderItem = $this->orderLine($requisition, $this->expenseCode());
        // The corruption itself: a project id sitting in the enquiry column.
        $orderItem->requisitionItem->update(['project_enquiry_id' => $projectId]);

        $this->assertNotSame($projectId, $enquiryId, 'The ids must differ or this proves nothing.');

        app(ProcurementCostProducer::class)->postPurchaseOrder($this->order->id);

        $commitment = CostLine::where('nature', CostLine::NATURE_COMMITTED)->firstOrFail();
        $this->assertSame($enquiryId, $commitment->project_enquiry_id, 'Must own the enquiry, not the project.');
        $this->assertSame('WNG-03-2026-058', $commitment->job_number, 'Job number comes off the enquiry, cleanly.');
    }

    public function test_a_fully_classified_requisition_still_approves(): void
    {
        $requisition = $this->requisition('pending_approval');
        $this->orderLine($requisition, $this->expenseCode());

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")->assertOk();

        $this->assertSame('approved', $requisition->fresh()->status);
    }
}
