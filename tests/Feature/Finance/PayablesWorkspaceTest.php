<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\FinanceWorkQueueService;
use App\Modules\Finance\Services\TaxScheduleService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 60 (Stream C, W2 Purchasing & payables).
 *
 * - Supplier-bill verification is the `finance.payables.verify` permission,
 *   never a role name (the mandatory §51 closure proof).
 * - GET api/finance/payables/* are read-only, permission-gated projections of
 *   backend truth: filters, paging, the document chain, the match, WHT.
 * - Return for Correction on supplier bills with preparer ≠ verifier.
 * - PO → COMMITTED, GRN → ACCRUED, bill → no second cost (PO) / ACTUAL (direct),
 *   payment → no new project cost.
 */
class PayablesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;
    private User $verifier;
    private User $payer;
    private User $reader;
    private Supplier $supplier;
    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(PaymentSourceSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        foreach ([Permissions::FINANCE_PAYABLES_READ, Permissions::FINANCE_PAYABLES_VERIFY, Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::PROCUREMENT_BILLS_OVERRIDE_DUPLICATE, Permissions::APPROVALS_SELF_APPROVE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->preparer = $this->user('Procurement Clerk');
        $this->verifier = $this->user('Accounts Verifier', Permissions::FINANCE_PAYABLES_READ, Permissions::FINANCE_PAYABLES_VERIFY);
        $this->payer = $this->user('Accounts Payer', Permissions::FINANCE_PAYABLES_READ, Permissions::FINANCE_PETTY_CASH_CREATE);
        $this->reader = $this->user('Auditor', Permissions::FINANCE_PAYABLES_READ);

        $this->supplier = $this->supplier('Timber & Board Ltd');
        $this->bank = PaymentSource::query()->where('is_active', true)->where('can_make_payment', true)
            ->whereNotNull('gl_account_id')->where('type', 'bank')->firstOrFail();
    }

    private function user(string $name, string ...$permissions): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('w2_').'@test.local', 'password' => bcrypt('x'), 'is_active' => true]);
        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function supplier(string $name, array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'supplier_name' => $name, 'contact_person' => 'C', 'phone' => '0700'.random_int(100000, 999999),
            'email' => uniqid().'@t.local', 'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->preparer->id,
        ], $attributes));
    }

    private function enquiry(string $title = 'Expo stand', ?int $officer = null): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create(['company_name' => 'Acme', 'full_name' => 'Acme'])->id,
            'title' => $title, 'description' => 'W2', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED, 'contact_person' => 'J',
            'enquiry_number' => 'ENQ-W2-'.uniqid(), 'job_number' => 'JOB-W2-'.uniqid(), 'created_by' => $this->preparer->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
            'project_officer_id' => $officer,
        ]);
    }

    private function expenseCode(string $family = 'Direct expenses'): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'W2-'.strtoupper(substr(uniqid(), -6)), 'accounting_class' => 'Direct project cost',
            'expense_family' => $family, 'expense_type' => 'Boards and panels', 'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating', 'is_procurable' => true, 'is_active' => true,
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
        ]);
    }

    /** An approved order for 10 × 5,000 on a project's requisition, delivered and accepted in full. */
    private function deliveredOrder(?ProjectEnquiry $enquiry = null, ?ExpenseCode $code = null, float $quantity = 10, ?Supplier $supplier = null): PurchaseOrder
    {
        $project = $enquiry ? Project::create(['enquiry_id' => $enquiry->id, 'project_id' => 'PRJ-'.uniqid(), 'status' => 'in_progress']) : null;
        $requisition = Requisition::create([
            'requisition_number' => 'PR-'.uniqid(), 'date' => now()->toDateString(), 'requested_by_type' => 'project',
            'project_id' => $project?->id, 'job_number' => $enquiry?->job_number, 'urgency' => 'normal',
            'total_amount' => 50000, 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->verifier->id,
            'user_id' => $this->preparer->id,
        ]);
        $requisitionItem = RequisitionItem::create([
            'requisition_id' => $requisition->id, 'custom_description' => 'MDF 18mm sheet', 'expense_code_id' => $code?->id,
            'quantity' => 10, 'unit_price' => 5000, 'total' => 50000, 'purpose' => 'Stand build',
        ]);
        $order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'requisition_id' => $requisition->id, 'date' => now()->toDateString(),
            'supplier_id' => ($supplier ?? $this->supplier)->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Store', 'description' => 'Boards', 'total_amount' => 50000, 'status' => 'approved',
            'user_id' => $this->preparer->id, 'approved_at' => now(), 'approved_by' => $this->verifier->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'requisition_item_id' => $requisitionItem->id, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => 10, 'unit_price' => 5000, 'total' => 50000,
        ]);
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.uniqid(), 'date' => now()->toDateString(), 'purchase_order_id' => $order->id,
            'batch_number' => 'B-'.uniqid(), 'store_location' => 'Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->preparer->id,
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $item->id,
            'ordered_quantity' => 10, 'received_quantity' => $quantity, 'condition' => 'good', 'accepted' => true,
            'store_status' => 'confirmed', 'stock_status' => 'posted', 'unit_price' => 5000,
        ]);

        return $order;
    }

    private function recordBill(PurchaseOrder $order, float $amount = 50000, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->preparer, 'sanctum')->postJson('/api/procurement-stores/bills', array_merge([
            'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $amount, 'supplier_invoice_number' => 'SINV-'.uniqid(),
        ], $overrides));
    }

    private function bill(PurchaseOrder $order, float $amount = 50000, array $overrides = []): Bill
    {
        return Bill::findOrFail($this->recordBill($order, $amount, $overrides)->assertSuccessful()->json('data.id'));
    }

    private function verify(Bill $bill, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->verifier, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/verify");
    }

    private function pay(Bill $bill, float $amount, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->payer, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", array_merge([
            'amount_paid' => $amount, 'payment_date' => now()->toDateString(), 'payment_source_id' => $this->bank->id,
            'payment_method' => 'bank_transfer', 'reference_number' => 'FT-'.uniqid(),
        ], $overrides));
    }

    private function show(Bill $bill, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->reader, 'sanctum')->getJson("/api/finance/payables/bills/{$bill->id}")->assertOk()->json('data');
    }

    // ── §51: the permission, never the role name ─────────────────────────

    public function test_a_role_name_alone_never_verifies_a_supplier_bill(): void
    {
        $bill = $this->bill($this->deliveredOrder());

        foreach (['Accounts', 'Admin', 'Finance', 'Accountant'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions([]);   // the name, with no permission behind it
            $user = $this->user("{$roleName} by name only");
            $user->assignRole($role);

            $this->verify($bill, $user)->assertForbidden();
            $this->assertNull($bill->fresh()->verified_at, "{$roleName} verified by role name");
            $this->assertArrayNotHasKey('supplier_invoice',
                app(FinanceWorkQueueService::class)->countsForUser($user)['by_type']);
        }
    }

    public function test_the_verify_permission_alone_is_enough_when_everything_else_holds(): void
    {
        $bill = $this->bill($this->deliveredOrder());
        $holder = $this->user('No roles at all', Permissions::FINANCE_PAYABLES_VERIFY);

        $this->verify($bill, $holder)->assertOk();
        $this->assertSame($holder->id, $bill->fresh()->verified_by);
    }

    public function test_a_role_carrying_the_permission_verifies_through_the_permission(): void
    {
        $role = Role::findOrCreate('Accounts', 'web');
        $role->syncPermissions([Permissions::FINANCE_PAYABLES_VERIFY]);
        $member = $this->user('Accounts member');
        $member->assignRole($role);

        $this->verify($this->bill($this->deliveredOrder()), $member)->assertOk();
    }

    public function test_the_preparer_cannot_verify_their_own_bill_even_with_the_permission(): void
    {
        $this->preparer->givePermissionTo(Permissions::FINANCE_PAYABLES_VERIFY);
        $bill = $this->bill($this->deliveredOrder());

        $this->verify($bill, $this->preparer)->assertStatus(422);
        $this->assertNull($bill->fresh()->verified_at);

        $this->preparer->givePermissionTo(Permissions::FINANCE_PAYABLES_READ);
        $action = $this->show($bill, $this->preparer)['actions']['verify'];
        $this->assertFalse($action['allowed']);
        $this->assertStringContainsString('someone else', $action['reason']);
        $this->assertTrue($this->show($bill, $this->verifier)['actions']['verify']['allowed']);
    }

    // ── My Actions ───────────────────────────────────────────────────────

    public function test_my_actions_offer_verify_correct_and_pay_only_in_an_actionable_state_and_link_to_the_finance_bill(): void
    {
        $bill = $this->bill($this->deliveredOrder());
        $item = fn (User $user, string $type) => collect(app(FinanceWorkQueueService::class)->forUser($user, ['per_page' => 100])['items'])
            ->first(fn (array $row) => $row['work_type'] === $type && (int) $row['source_id'] === $bill->id);

        $this->assertSame("/finance/payables/bills/{$bill->id}", $item($this->verifier, 'supplier_invoice')['target_url']);
        $this->assertNull($item($this->preparer, 'supplier_invoice_correction'));
        $this->assertNull($item($this->payer, 'supplier_payment'), 'An unverified bill is not a payment action.');

        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/return-for-correction", ['reason' => 'Wrong date'])->assertOk();
        $this->assertNull($item($this->verifier, 'supplier_invoice'), 'A returned bill is with its preparer, not the verifier.');
        $this->assertSame("/finance/payables/bills/{$bill->id}", $item($this->preparer, 'supplier_invoice_correction')['target_url']);

        $this->actingAs($this->preparer, 'sanctum')->putJson("/api/procurement-stores/bills/{$bill->id}", [
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 50000,
            'supplier_invoice_number' => $bill->supplier_invoice_number, 'correction_note' => 'Date corrected',
        ])->assertOk();
        $this->assertNull($item($this->preparer, 'supplier_invoice_correction'));
        $this->assertNotNull($item($this->verifier, 'supplier_invoice'));

        $this->verify($bill)->assertOk();
        $this->assertSame("/finance/payables/bills/{$bill->id}", $item($this->payer, 'supplier_payment')['target_url']);
        $this->pay($bill, 50000)->assertSuccessful();
        $this->assertNull($item($this->payer, 'supplier_payment'), 'A settled bill is not a payment action.');
    }

    // ── Read projection ──────────────────────────────────────────────────

    public function test_payables_are_closed_to_anyone_without_the_read_permission(): void
    {
        $bill = $this->bill($this->deliveredOrder());
        $outsider = $this->user('Outsider');
        foreach (['/api/finance/payables/bills', "/api/finance/payables/bills/{$bill->id}", '/api/finance/payables/payments',
            '/api/finance/payables/position', '/api/finance/payables/wht'] as $url) {
            $this->actingAs($outsider, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    public function test_the_bill_index_filters_on_the_server_and_pages(): void
    {
        $officer = $this->user('Pat Officer');
        $expo = $this->enquiry('Expo stand', $officer->id);
        $other = $this->supplier('Paint House');
        $expoBill = $this->bill($this->deliveredOrder($expo));
        $otherBill = $this->bill($this->deliveredOrder(null, null, 10, $other), 50000, ['bill_date' => '2026-08-01', 'due_date' => '2026-08-31']);
        foreach (range(1, 11) as $i) {
            $this->bill($this->deliveredOrder());
        }

        $ids = fn (string $query) => collect($this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/bills?'.$query)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$otherBill->id], $ids("supplier_id={$other->id}"));
        $this->assertSame([$expoBill->id], $ids("enquiry_id={$expo->id}"));
        $this->assertSame([$expoBill->id], $ids("project_officer_id={$officer->id}"));
        $this->assertSame([$otherBill->id], $ids('bill_date_to=2026-08-31'));
        $this->assertSame([$otherBill->id], $ids('overdue=1'));
        $this->assertSame([$otherBill->id], $ids('search='.$otherBill->bill_number));
        $this->assertCount(13, $ids('verification=awaiting_verification&per_page=50'));
        $this->assertSame([], $ids('verification=verified'));

        $page = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/bills?per_page=10&page=2')->assertOk();
        $this->assertSame(13, $page->json('meta.total'));
        $this->assertCount(3, $page->json('data'));
        $this->assertSame(13, $page->json('summary.awaiting_verification'));
    }

    public function test_rows_carry_backend_figures_the_document_chain_and_safe_identities(): void
    {
        $expo = $this->enquiry();
        $order = $this->deliveredOrder($expo);
        $bill = $this->bill($order);

        $row = collect($this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/bills')->json('data'))->firstWhere('id', $bill->id);
        $this->assertSame($order->po_number, $row['purchase_order']['number']);
        $this->assertCount(1, $row['receipts']);
        $this->assertSame($expo->job_number, $row['project']['job_number']);
        $this->assertSame('50000.00', $row['gross']);
        $this->assertSame('50000.00', $row['balance']);
        $this->assertSame('awaiting_verification', $row['verification_state']);
        $this->assertSame('unpaid', $row['payment_state']);
        $this->assertSame(['id', 'name'], array_keys($row['prepared_by']));

        $detail = $this->show($bill);
        $this->assertSame($order->requisition->requisition_number, $detail['procurement']['requisition']['number']);
        $this->assertSame($order->po_number, $detail['procurement']['purchase_order']['number']);
        $this->assertCount(1, $detail['procurement']['receipts']);
        $this->assertSame(['id', 'name'], array_keys($detail['procurement']['receipts'][0]['received_by']));
        $this->assertNull($detail['service_confirmation']);
        // W2-1: an unset senior threshold is reported as unset, never defaulted.
        $this->assertNull($detail['procurement']['purchase_order']['senior_approval_threshold']);
        $this->assertFalse($detail['procurement']['purchase_order']['senior_approval_required']);
        // The backend's match, not a recalculation.
        $this->assertTrue($detail['match']['eligible_for_verification']);
        $this->assertSame('50000.00', $detail['match']['billing_position']['this_bill']);
        $this->assertSame('10.000000', $detail['match']['lines'][0]['accepted']);
        $this->assertNotEmpty($detail['match']['checks']);
        $json = json_encode($detail);
        foreach (['password', 'email', 'salary', 'file_path'] as $leak) {
            $this->assertStringNotContainsString("\"{$leak}\"", $json);
        }
    }

    // ── Return for Correction ────────────────────────────────────────────

    public function test_prepare_return_correct_resubmit_then_independent_verify(): void
    {
        $bill = $this->bill($this->deliveredOrder(), 50000);

        // Without the permission, and by the preparer, a return is refused.
        $this->actingAs($this->reader, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/return-for-correction", ['reason' => 'Wrong invoice number'])->assertForbidden();
        $this->preparer->givePermissionTo(Permissions::FINANCE_PAYABLES_VERIFY);
        $this->actingAs($this->preparer, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/return-for-correction", ['reason' => 'Wrong invoice number'])->assertStatus(422);
        $this->preparer->revokePermissionTo(Permissions::FINANCE_PAYABLES_VERIFY);

        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/return-for-correction", ['reason' => 'Invoice number does not match the PDF'])->assertOk();

        $returned = $this->show($bill);
        $this->assertSame('returned_for_correction', $returned['verification_state']);
        $this->assertSame('Invoice number does not match the PDF', $returned['controls']['return_reason']);
        $this->assertSame($this->verifier->id, $returned['controls']['returned_by']['id']);
        $this->assertNotNull($returned['controls']['returned_at']);
        // Blocked: cannot be verified or paid while with its preparer.
        $this->verify($bill)->assertStatus(422);
        $this->assertFalse($returned['match']['can_pay']);
        $this->assertSame(1, app(FinanceWorkQueueService::class)->countsForUser($this->preparer)['by_type']['supplier_invoice_correction'] ?? 0);
        $this->assertArrayNotHasKey('supplier_invoice', app(FinanceWorkQueueService::class)->countsForUser($this->verifier)['by_type']);

        // Only its preparer corrects it, with a note; nobody else.
        $correction = ['bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 50000,
            'supplier_invoice_number' => 'SINV-CORRECTED-1', 'correction_note' => 'Invoice number re-keyed from the PDF'];
        $this->actingAs($this->verifier, 'sanctum')->putJson("/api/procurement-stores/bills/{$bill->id}", $correction)->assertForbidden();
        $this->actingAs($this->preparer, 'sanctum')->putJson("/api/procurement-stores/bills/{$bill->id}", $correction)->assertOk();

        $resubmitted = $this->show($bill);
        $this->assertSame('resubmitted', $resubmitted['verification_state']);
        $this->assertSame('SINV-CORRECTED-1', $resubmitted['supplier_invoice_number']);
        $this->assertTrue($this->show($bill, $this->verifier)['actions']['verify']['allowed']);

        $this->verify($bill)->assertOk();
        $verified = $this->show($bill);
        $this->assertSame('verified', $verified['verification_state']);
        $events = collect($verified['audit'])->pluck('event')->all();
        $this->assertSame(['Supplier Bill Returned', 'Supplier Bill Corrected', 'Supplier Bill Verified'], $events);
        $this->assertSame('Invoice number re-keyed from the PDF', $verified['audit'][1]['reason']);

        // A verified bill is not returned.
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/procurement-stores/bills/{$bill->id}/return-for-correction", ['reason' => 'Second thoughts'])->assertStatus(422);
    }

    public function test_a_correction_cannot_breach_the_staged_billing_cap(): void
    {
        $order = $this->deliveredOrder();
        $first = $this->bill($order, 30000);
        $second = $this->bill($order, 20000);
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/procurement-stores/bills/{$second->id}/return-for-correction", ['reason' => 'Amount wrong'])->assertOk();

        $this->actingAs($this->preparer, 'sanctum')->putJson("/api/procurement-stores/bills/{$second->id}", [
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 25000,
            'supplier_invoice_number' => $second->supplier_invoice_number, 'correction_note' => 'Raise to 25,000',
        ])->assertStatus(422);

        $this->assertSame('20000.00', (string) $second->fresh()->amount);
        $position = $this->show($first)['match']['billing_position'];
        $this->assertSame('50000.00', $position['order_value']);
        $this->assertSame('20000.00', $position['previously_billed']);
        $this->assertSame('0.00', $position['remaining_billable']);
    }

    public function test_a_duplicate_bill_is_refused_and_an_override_is_recorded(): void
    {
        $order = $this->deliveredOrder();
        $this->bill($order, 20000, ['supplier_invoice_number' => 'INV-777']);

        $refused = $this->recordBill($order, 20000, ['supplier_invoice_number' => 'INV-777'])->assertStatus(422);
        $this->assertSame('DUPLICATE_BILL', $refused->json('code'));
        $this->assertFalse($refused->json('duplicate.can_override'));
        $this->recordBill($order, 20000, ['supplier_invoice_number' => 'INV-777', 'duplicate_override_reason' => 'Supplier re-used the number'])->assertStatus(422);

        $this->preparer->givePermissionTo(Permissions::PROCUREMENT_BILLS_OVERRIDE_DUPLICATE);
        $override = $this->bill($order, 20000, ['supplier_invoice_number' => 'INV-777', 'duplicate_override_reason' => 'Supplier re-used the number']);
        $controls = $this->show($override)['controls']['duplicate_override'];
        $this->assertSame('Supplier re-used the number', $controls['reason']);
        $this->assertSame($this->preparer->id, $controls['by']['id']);
        $this->assertNotNull($controls['at']);
    }

    // ── Payments ─────────────────────────────────────────────────────────

    public function test_partial_then_full_payment_settles_the_bill_and_adds_no_project_cost(): void
    {
        $bill = $this->bill($this->deliveredOrder($this->enquiry(), $this->expenseCode()));
        $this->verify($bill)->assertOk();
        $costLines = CostLine::count();

        $this->pay($bill, 20000)->assertSuccessful();
        $partial = $this->show($bill);
        $this->assertSame('partially_paid', $partial['payment_state']);
        $this->assertSame('20000.00', $partial['paid']);
        $this->assertSame('30000.00', $partial['balance']);
        $this->assertCount(1, $partial['payments']);
        $this->assertSame($this->bank->id, $partial['payments'][0]['source']['id']);

        $this->pay($bill, 30001)->assertStatus(422);
        $this->pay($bill, 30000)->assertSuccessful();
        $paid = $this->show($bill);
        $this->assertSame('paid', $paid['payment_state']);
        $this->assertSame('0.00', $paid['balance']);
        $this->assertFalse($paid['project_cost']['payments_add_cost']);

        $this->assertSame($costLines, CostLine::count(), 'A supplier payment created a project cost line.');
    }

    public function test_an_unverified_bill_cannot_be_paid_and_a_duplicate_payment_reference_is_refused(): void
    {
        $bill = $this->bill($this->deliveredOrder(), 50000);
        $this->pay($bill, 1000)->assertStatus(422);
        $this->assertFalse($this->show($bill, $this->payer)['actions']['pay']['allowed']);

        $this->verify($bill)->assertOk();
        $this->assertTrue($this->show($bill, $this->payer)['actions']['pay']['allowed']);
        $this->pay($bill, 1000, ['reference_number' => 'FT-DUP-1'])->assertSuccessful();
        $duplicate = $this->pay($bill, 1000, ['reference_number' => 'FT-DUP-1'])->assertStatus(422);
        $this->assertSame('DUPLICATE_PAYMENT', $duplicate->json('code'));
        $this->assertSame(1, BillPayment::where('bill_id', $bill->id)->count());

        $inactive = PaymentSource::query()->where('id', '!=', $this->bank->id)->first();
        $inactive?->update(['is_active' => false]);
        if ($inactive) {
            $this->pay($bill, 1000, ['payment_source_id' => $inactive->id])->assertStatus(422);
        }
    }

    public function test_the_payments_register_filters_by_supplier_and_shows_the_bill(): void
    {
        $bill = $this->bill($this->deliveredOrder());
        $this->verify($bill)->assertOk();
        $this->pay($bill, 5000, ['reference_number' => 'FT-REG-1'])->assertSuccessful();

        $rows = $this->actingAs($this->reader, 'sanctum')->getJson("/api/finance/payables/payments?supplier_id={$this->supplier->id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($bill->bill_number, $rows[0]['bill']['number']);
        $this->assertSame('FT-REG-1', $rows[0]['reference']);
        $this->assertSame(['id', 'name'], array_keys($rows[0]['recorded_by']));
        $this->assertSame([], $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/payments?search=NOPE')->json('data'));
    }

    // ── WHT ──────────────────────────────────────────────────────────────

    public function test_withholding_reduces_the_payable_is_owed_on_the_mapped_account_and_reaches_the_wht_return(): void
    {
        $category = WhtCategory::where('residency', 'resident')->firstOrFail();
        $this->supplier->update(['wht_category_id' => $category->id, 'kra_pin' => 'P051234567M', 'residency' => 'resident']);
        $bill = $this->bill($this->deliveredOrder());
        $wht = (string) $bill->fresh()->wht_amount;
        $this->assertSame(1, bccomp($wht, '0', 2));

        $detail = $this->show($bill);
        $this->assertSame(bcsub('50000.00', $wht, 2), $detail['tax']['payable']);
        $this->assertSame(bcsub('50000.00', $wht, 2), $detail['balance']);
        $this->assertSame(ChartAccountMap::local(FinanceAccountFunctions::WHT_PAYABLE), $detail['tax']['wht_liability_account']['code']);

        $this->verify($bill)->assertOk();
        $summary = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/wht')->assertOk()->json('summary');
        $this->assertSame(number_format((float) $wht, 2, '.', ''), $summary['withheld_on_verified_bills']);
        $this->assertSame(number_format((float) $wht, 2, '.', ''), $summary['ledger_balance']);

        $return = app(TaxScheduleService::class)->whtSchedule(2026, 9);
        $row = collect($return['rows'])->firstWhere('payee_name', $this->supplier->supplier_name);
        $this->assertNotNull($row, 'Supplier-bill WHT is missing from the WHT return.');
        $this->assertSame(number_format((float) $wht, 2, '.', ''), $row['wht_amount']);
        $this->assertContains($bill->bill_number, $row['refs']);
    }

    // ── Costing: W2 agrees with W6 ───────────────────────────────────────

    public function test_po_commits_grn_accrues_bill_adds_no_second_cost_and_payment_adds_none(): void
    {
        $enquiry = $this->enquiry();
        $order = $this->deliveredOrder($enquiry, $this->expenseCode());
        $producer = app(ProcurementCostProducer::class);
        $nature = fn (string $n) => CostLine::where('project_enquiry_id', $enquiry->id)->where('nature', $n)->count();

        $producer->postPurchaseOrder($order->id);
        $this->assertSame(1, $nature(CostLine::NATURE_COMMITTED));

        $producer->postGoodsReceipt($order->goodsReceiptNotes()->first()->id);
        $this->assertSame(1, $nature(CostLine::NATURE_ACCRUED));
        $accrual = CostLine::where('project_enquiry_id', $enquiry->id)->where('nature', CostLine::NATURE_ACCRUED)->firstOrFail();
        $this->assertSame('50000.00', (string) $accrual->amount);
        $before = CostLine::count();

        $bill = $this->bill($order);
        $this->verify($bill)->assertOk();
        $this->assertSame($before, CostLine::count(), 'Verifying a PO-backed bill created a second project cost.');
        $this->assertSame($bill->id, (int) $accrual->fresh()->settled_by_bill_id);
        $cost = $this->show($bill)['project_cost'];
        $this->assertSame('receipt_accrual_settled_by_this_bill', $cost['lines'][0]['relation']);

        $this->pay($bill, 50000)->assertSuccessful();
        $this->assertSame($before, CostLine::count(), 'Paying a supplier bill created a project cost.');
    }

    public function test_a_verified_direct_bill_becomes_the_projects_actual_cost_once(): void
    {
        $enquiry = $this->enquiry();
        $code = $this->expenseCode('Subcontracted services');
        $response = $this->actingAs($this->preparer, 'sanctum')->postJson('/api/procurement-stores/bills', [
            'supplier_id' => $this->supplier->id, 'expense_code_id' => $code->id, 'project_enquiry_id' => $enquiry->id,
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 12000, 'supplier_invoice_number' => 'DIRECT-'.uniqid(),
        ])->assertSuccessful();
        $bill = Bill::findOrFail($response->json('data.id'));
        $this->assertSame(0, CostLine::where('source_type', Bill::class)->count());

        $this->verify($bill)->assertOk();
        $lines = CostLine::where('source_type', Bill::class)->where('source_id', $bill->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(CostLine::NATURE_ACTUAL, $lines[0]->nature);
        $this->assertSame((int) $enquiry->id, (int) $lines[0]->project_enquiry_id);
        $this->assertSame('12000.00', (string) $lines[0]->amount);
        // Analytical only: the bill's own journal recognised the expense.
        $this->assertSame(1, \App\Modules\Finance\Models\JournalEntry::where('source_type', Bill::class)->where('source_id', $bill->id)->count());

        $before = CostLine::count();
        $this->pay($bill, 12000)->assertSuccessful();
        $this->assertSame($before, CostLine::count());
        $this->assertSame('recognised_by_this_bill', $this->show($bill)['project_cost']['lines'][0]['relation']);
    }

    public function test_the_ap_position_reconciles_supplier_balances_to_the_bills(): void
    {
        $bill = $this->bill($this->deliveredOrder());
        $this->verify($bill)->assertOk();
        $this->pay($bill, 10000)->assertSuccessful();

        $position = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payables/position')->assertOk()->json('data');
        $supplier = collect($position['suppliers'])->firstWhere('supplier.id', $this->supplier->id);
        $this->assertSame('40000.00', $supplier['outstanding']);
        $this->assertSame('40000.00', $position['summary']['outstanding']);
        // The AP control account holds the posted invoice less the posted payment.
        $this->assertSame('40000.00', $position['ledger']['balance']);
    }
}
