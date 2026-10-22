<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Events\GoodsReceiptRecorded;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\Services\FinanceEventPoster;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\GovernedPostingAccount;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\InventoryService;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 76A Part 2 — WNG decisions A–E, end to end.
 *
 *   A  goods receipt ↔ supplier invoice true-up, explicit price variance
 *   B  non-stock purchases never enter Inventory; the receipt is the cost
 *   C  purchased stock only through a GRN; other stock through a controlled adjustment
 *   D  company use, damage, waste and counts are never a project cost
 *   E  a company-paid cost needs payment authority as well as cost verification
 *
 * Journals are asserted by ACCOUNT, and every flow ends by checking that the
 * project carries exactly one economic cost.
 */
class Report76APart2Test extends TestCase
{
    use RefreshDatabase;

    use \Tests\Feature\Finance\Concerns\ProcurementAccountingFixtures;

    /* ── A. Stocked purchase, same price ─────────────────────────────────── */

    public function test_a_stocked_purchase_is_one_economic_cost_from_order_to_issue(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);

        // PO: a commitment, nothing in the ledger.
        $this->assertSame('100000.00', $this->projectLines(CostLine::NATURE_COMMITTED));
        $this->assertSame(0, JournalEntry::count());

        // GRN: Inventory and the receipt accrual. No VAT, no project actual.
        $line = $this->receive($item, 100, shelve: false);
        $this->assertSame('100000.00', $this->balance($this->inventory));
        $this->assertSame('-100000.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->inputVat));
        $this->assertSame('0.00', $this->projectLines(CostLine::NATURE_COMMITTED));
        $this->assertSame('100000.00', $this->projectLines(CostLine::NATURE_ACCRUED));
        $this->assertSame('0.00', $this->projectActual());
        $this->shelve($line);
        $this->assertSame(100.0, $this->stockOnHand($material));

        // Bill: clears the accrual, raises AP, and is where VAT comes from.
        $bill = $this->bill($item, 100000, 16000);
        $this->verify($bill)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-116000.00', $this->balance($this->payable));
        $this->assertSame('16000.00', $this->balance($this->inputVat));
        $this->assertSame('100000.00', $this->balance($this->inventory));
        $this->assertSame('0.00', $this->projectActual(), 'a bill is not a project cost');

        // Payment: settles AP only.
        $this->pay($bill);
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('0.00', $this->projectActual(), 'a payment is not a project cost');

        // Stores issue: the one project actual.
        app(InventoryService::class)->adjustStock($material->id, -40, 'check_out', ['project_id' => $this->projectId, 'recipient_name' => 'Site']);
        $this->assertSame('40000.00', $this->projectActual());
        $this->assertSame('60000.00', $this->balance($this->inventory));
        $this->assertSame(60.0, $this->stockOnHand($material));
        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_ACTUAL)->where('status', 'verified')->count());
        $this->assertSame(0, $this->unbalancedJournals());

        $position = app(PurchaseOrderWorkflow::class)->order(PurchaseOrder::findOrFail($item->purchase_order_id));
        $this->assertSame(['fully_received', 'fully_billed', 'fully_paid', false],
            [$position['receipt_status'], $position['billing_status'], $position['payment_status'], $position['has_exception']]);
    }

    /* ── B. Price variance ───────────────────────────────────────────────── */

    public function test_b_a_price_difference_on_stock_still_on_hand_revalues_the_stock(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $this->receive($item, 100);

        // The receipt value is worked out, never typed.
        $this->postJson('/api/procurement-stores/bills', [
            'purchase_order_id' => $item->purchase_order_id, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 121800, 'vat_amount' => 16800, 'supplier_invoice_number' => 'SINV-Y', 'receipt_value_covered' => 100000,
        ])->assertStatus(422);
        // A price difference on a line needs a reason.
        $this->billLines($item->purchase_order_id, [['purchase_order_item_id' => $item->id, 'quantity' => 100, 'unit_price_net' => 1050]], 16800)
            ->assertStatus(422)->assertJsonPath('error.lines.0', fn ($message) => str_contains($message, 'Say why'));

        $bill = $this->billed($this->billLines($item->purchase_order_id, [[
            'purchase_order_item_id' => $item->id, 'quantity' => 100, 'unit_price_net' => 1050, 'variance_reason' => 'Supplier price rose after the order',
        ]], 16800));
        $this->assertSame('100000.00', $bill->receiptValueCovered(), 'computed from the receipts the line is matched to');
        $this->assertSame('5000.00', $bill->priceVariance());

        // All 100 are still on the shelf, so no variance account is needed: the stock is worth what it cost.
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued), 'the accrual is cleared for exactly what was received');
        $this->assertSame('105000.00', $this->balance($this->inventory), 'stock on hand carries the invoiced cost');
        $this->assertSame('0.00', $this->balance($this->variance));
        $this->assertSame(1050.0, round((float) $material->fresh()->unit_cost, 4), 'and so does the average cost');
        $this->assertSame('16800.00', $this->balance($this->inputVat), 'VAT is the invoice\'s, not derived from the receipt');
        $this->assertSame('-121800.00', $this->balance($this->payable));
        $this->assertSame('0.00', $this->projectActual());
        $this->assertSame(0, CostLine::where('source_type', Bill::class)->count(), 'the bill created no cost line');
        $this->assertSame(0, $this->unbalancedJournals());

        $position = app(PurchaseOrderWorkflow::class)->order(PurchaseOrder::findOrFail($item->purchase_order_id));
        $this->assertTrue($position['has_exception']);
        $this->assertSame('price_variance', $position['exceptions'][0]['key']);
        $this->assertSame('5000.00', $position['price_variance']);
    }

    public function test_b_a_lower_invoice_reduces_stock_on_hand_and_clears_the_whole_accrual(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $this->receive($item, 100);

        $bill = $this->billed($this->billLines($item->purchase_order_id, [[
            'purchase_order_item_id' => $item->id, 'quantity' => 100, 'unit_price_net' => 950, 'variance_reason' => 'Volume discount on the invoice',
        ]], 15200));
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued), 'no residue left in the accrual');
        $this->assertSame('95000.00', $this->balance($this->inventory));
        $this->assertSame(950.0, round((float) $material->fresh()->unit_cost, 4));
        $this->assertSame('-110200.00', $this->balance($this->payable));
        $this->assertSame('15200.00', $this->balance($this->inputVat));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── C. Partial receipt and staged billing ───────────────────────────── */

    public function test_c_partial_receipts_and_staged_bills_reconcile_at_every_step(): void
    {
        $item = $this->order(100, 1000, $this->material('MDF 18mm')->id);
        $order = fn () => app(PurchaseOrderWorkflow::class)->order(PurchaseOrder::findOrFail($item->purchase_order_id));

        $this->receive($item, 40);
        $this->assertSame('-40000.00', $this->balance($this->accrued));
        $this->assertSame('60000.00', $this->projectLines(CostLine::NATURE_COMMITTED), 'the unreceived 60 stay committed');
        $this->assertSame('partially_received', $order()['receipt_status']);
        $this->assertSame('not_billed', $order()['billing_status']);

        // More than was received cannot be billed.
        $this->postJson('/api/procurement-stores/bills', [
            'purchase_order_id' => $item->purchase_order_id, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 50000, 'vat_amount' => 0, 'supplier_invoice_number' => 'SINV-OVER',
        ])->assertSuccessful();
        $over = Bill::latest('id')->first();
        $this->verify($over)->assertStatus(422);
        $over->delete();

        $first = $this->bill($item, 30000);
        $this->verify($first)->assertOk();
        $this->assertSame('-10000.00', $this->balance($this->accrued));
        $this->assertSame('-30000.00', $this->balance($this->payable));
        $this->assertSame('partially_billed', $order()['billing_status']);
        $this->assertSame('10000.00', $order()['received_not_invoiced']);
        // Not every receipt line is "settled" just because one part bill exists.
        $this->assertSame(0, CostLine::where('source_ref', 'accrual')->whereNotNull('settled_by_bill_id')->count());

        $second = $this->bill($item, 10000);
        $this->verify($second)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame(1, CostLine::where('source_ref', 'accrual')->whereNotNull('settled_by_bill_id')->count());

        $this->receive($item, 60);
        $this->assertSame('-60000.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->projectLines(CostLine::NATURE_COMMITTED));
        $this->assertSame('fully_received', $order()['receipt_status']);

        $third = $this->bill($item, 60000);
        $this->verify($third)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-100000.00', $this->balance($this->payable));
        $this->assertSame('100000.00', $this->balance($this->inventory));
        $this->assertSame('fully_billed', $order()['billing_status']);
        $this->assertSame('unpaid', $order()['payment_status']);
        $this->assertSame('0.00', $this->projectActual());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── D. Non-stock project purchase ───────────────────────────────────── */

    public function test_d_a_non_stock_project_purchase_is_costed_once_at_receipt_and_never_enters_inventory(): void
    {
        $wip = (int) $this->serviceCode->default_debit_account_id;
        $item = $this->order(1, 80000, null, $this->serviceCode);
        $this->assertSame('80000.00', $this->projectLines(CostLine::NATURE_COMMITTED));

        // The service confirmation is the cost.
        $this->receive($item, 1);
        $this->assertSame('80000.00', $this->projectActual());
        $this->assertSame('0.00', $this->projectLines(CostLine::NATURE_COMMITTED));
        $this->assertSame('0.00', $this->projectLines(CostLine::NATURE_ACCRUED));
        $this->assertSame('80000.00', $this->balance($wip));
        $this->assertSame('-80000.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->inventory), 'a service is never stock');

        // It can be invoiced without any Stores confirmation.
        $bill = $this->bill($item, 80000, 12800);
        $this->verify($bill)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-92800.00', $this->balance($this->payable));
        $this->assertSame('80000.00', $this->balance($wip), 'the bill added no second cost');
        $this->assertSame('80000.00', $this->projectActual());

        $this->pay($bill);
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('80000.00', $this->projectActual());
        $this->assertSame(1, CostLine::where('project_enquiry_id', $this->enquiryId)->where('nature', 'actual')->count());
        // …and it cannot be paid a second time through a payment voucher.
        $this->assertTrue(CostLine::where('source_ref', 'receipt-actual')->firstOrFail()->isGrnAccrual());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── E. Non-stock overhead purchase ──────────────────────────────────── */

    public function test_e_a_non_stock_overhead_purchase_is_an_expense_and_never_a_project_cost(): void
    {
        $expense = (int) $this->overheadCode->default_debit_account_id;
        $item = $this->order(1, 30000, null, $this->overheadCode, forProject: false);

        $this->receive($item, 1);
        $this->assertSame('30000.00', $this->balance($expense));
        $this->assertSame('-30000.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->inventory));
        $this->assertSame(0, CostLine::whereNotNull('project_enquiry_id')->count(), 'no project anywhere on it');
        $this->assertNotNull(CostLine::where('source_ref', 'receipt-actual')->firstOrFail()->cost_centre_id);

        $bill = $this->bill($item, 30000);
        $this->verify($bill)->assertOk();
        $this->pay($bill);
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('30000.00', $this->balance($expense));
        $this->assertSame('0.00', $this->projectActual());
    }

    public function test_d_a_non_stock_line_whose_category_has_no_account_is_not_posted_to_inventory_or_a_default(): void
    {
        $unmapped = ExpenseCode::create([
            'code' => 'T2-NOACC', 'accounting_class' => 'Direct project cost', 'expense_family' => 'Subcontractors',
            'expense_type' => 'Unmapped service', 'simple_meaning' => 'Unmapped service', 'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating', 'default_debit_account_id' => null, 'is_active' => true, 'is_procurable' => true,
        ]);
        $item = $this->order(1, 80000, null, $unmapped);
        $line = $this->receive($item, 1);
        $posting = FinanceEventPosting::where('posting_type', FinanceEventPoster::GRN_ACCRUAL)->where('subject_id', $line->goods_receipt_note_id)->firstOrFail();
        $this->assertSame(FinanceEventPosting::STATUS_FAILED, $posting->status);
        $this->assertStringContainsString('ACCOUNTING CONFIGURATION REQUIRED — EXPENSE CATEGORY ACCOUNT', $posting->last_error);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('0.00', $this->balance($this->inventory));
        $this->assertSame('80000.00', $this->projectLines(CostLine::NATURE_COMMITTED), 'still on order until it can be posted properly');

        // Finance maps the category and sends the posting again: posted once.
        $unmapped->update(['default_debit_account_id' => $this->serviceCode->default_debit_account_id, 'default_debit_gl' => $this->serviceCode->default_debit_gl]);
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertOk();
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertOk();
        $this->assertSame('80000.00', $this->projectActual());
        $this->assertSame(1, CostLine::where('source_ref', 'receipt-actual')->count());
    }

    /* ── F. Manual receipt of purchased stock ────────────────────────────── */

    public function test_f_purchased_stock_cannot_be_received_without_a_goods_receipt_note(): void
    {
        $material = $this->material('Screws 40mm', 12);

        $this->move('receive', ['material_id' => $material->id, 'quantity' => 50, 'receipt_unit_cost' => 12])
            ->assertStatus(422);
        $refusal = $this->move('receive', ['material_id' => $material->id, 'quantity' => 50, 'receipt_unit_cost' => 12])->json();
        $this->assertStringStartsWith('Purchased stock must be received through the approved GRN.', collect($refusal['errors'] ?? [])->flatten()->first());

        $this->assertSame(0.0, $this->stockOnHand($material));
        $this->assertSame(0, InventoryLog::count());
        $this->assertSame(0, GoodsReceiptNote::count(), 'no receipt is invented to make it fit');
        $this->assertSame(0, PurchaseOrder::count());

        // The older board-ingest route and the "type a new balance" route are shut too.
        $this->postJson('/api/procurement-stores/boards/ingest', ['library_material_id' => $material->id, 'quantity' => 2])
            ->assertStatus(422);
        $this->assertSame(0.0, $this->stockOnHand($material));
    }

    /* ── G. Exceptional (non-purchase) stock receipt ─────────────────────── */

    public function test_g_stock_that_was_not_bought_enters_through_a_controlled_adjustment_that_reaches_the_ledger(): void
    {
        $material = $this->material('Offcut boards');
        $line = ['material_id' => $material->id, 'quantity' => 10, 'receipt_unit_cost' => 250,
            'receipt_reason' => InventoryLog::REASON_DISCOVERED_STOCK, 'notes' => 'Found in the old Matasia store during clear-out'];

        // Needs the stock-adjustment reviewer, a written explanation and a value.
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->givePermissionTo(Permissions::STORES_MANAGE);
        $this->actingAs($clerk, 'sanctum');
        $this->move('receive', $line)->assertStatus(422);
        $this->actingAs($this->user, 'sanctum');
        $this->move('receive', ['notes' => 'x'] + $line)->assertStatus(422);
        $this->move('receive', ['receipt_unit_cost' => 0] + $line)->assertStatus(422);
        $this->assertSame(0, InventoryLog::count());

        $this->move('receive', $line)->assertSuccessful();

        $log = InventoryLog::firstOrFail();
        $this->assertSame(InventoryLog::REASON_DISCOVERED_STOCK, $log->movement_reason);
        $this->assertSame(10.0, $this->stockOnHand($material));
        // Subledger and ledger moved together, by the same amount.
        $this->assertSame('2500.00', $this->balance($this->inventory));
        $this->assertSame('-2500.00', $this->balance($this->adjustments));
        $this->assertNotNull(StoresFinancePosting::where('inventory_log_id', $log->id)->where('posting_type', InventoryLog::POSTING_EXCEPTIONAL_RECEIPT)->value('journal_entry_id'));
        $this->assertSame(0, CostLine::count(), 'never a project cost');
        $this->assertSame(0, GoodsReceiptNote::count());

        // Reversed symmetrically, once.
        $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Entered against the wrong material'])->assertCreated();
        $this->assertSame(0.0, $this->stockOnHand($material));
        $this->assertSame('0.00', $this->balance($this->inventory));
        $this->assertSame('0.00', $this->balance($this->adjustments));
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Entered against the wrong material'])->assertStatus(422);
        $this->assertSame(2, InventoryLog::count(), 'both movements stay on record');
    }

    /**
     * With no price entered, the accounts take the stock in at the catalogue
     * value — but that value is not money anyone paid, so it must not become
     * the material's average cost.
     */
    public function test_g_an_adjustment_valued_from_the_catalogue_reaches_the_ledger_without_becoming_a_purchase_price(): void
    {
        $material = $this->material('Found hinges');
        $material->update(['unit_cost' => 0, 'default_unit_cost' => 40]);

        $this->move('receive', ['material_id' => $material->id, 'quantity' => 5,
            'receipt_reason' => InventoryLog::REASON_DISCOVERED_STOCK, 'notes' => 'Found behind the paint rack'])->assertSuccessful();

        $this->assertSame('200.00', $this->balance($this->inventory));
        $this->assertSame('-200.00', $this->balance($this->adjustments));
        $this->assertSame(0.0, (float) $material->fresh()->unit_cost);
    }

    public function test_g_an_exceptional_receipt_is_refused_when_its_accounts_are_not_available(): void
    {
        ChartOfAccount::whereKey($this->adjustments)->update(['is_active' => false]);
        $material = $this->material('Offcut boards');

        $this->move('receive', ['material_id' => $material->id, 'quantity' => 10, 'receipt_unit_cost' => 250,
            'receipt_reason' => InventoryLog::REASON_STOCK_CORRECTION, 'notes' => 'Correcting an earlier miscount of this rack'])
            ->assertStatus(422);

        $this->assertSame(0.0, $this->stockOnHand($material), 'stock did not move without its ledger entry');
        $this->assertSame(0, InventoryLog::count());
        $this->assertSame(0, JournalEntry::count());
    }

    /* ── H, I, J. Company use, damage, waste ─────────────────────────────── */


    public function test_h_company_use_is_an_operating_expense_with_a_department_and_no_project(): void
    {
        $material = $this->stocked('Masking tape', 20, 100);
        $inventoryBefore = $this->balance($this->inventory);

        // Not for nobody: a project, or company use with a department and a reason.
        $this->move('issue', ['material_id' => $material->id, 'quantity' => 5, 'recipient_name' => 'Workshop'])
            ->assertStatus(422);
        $this->move('issue', ['material_id' => $material->id, 'quantity' => 5, 'recipient_name' => 'Workshop',
            'issue_purpose' => InventoryLog::REASON_INTERNAL_CONSUMPTION, 'notes' => 'Masking for the spray booth'])
            ->assertStatus(422);

        $issue = ['material_id' => $material->id, 'quantity' => 5, 'recipient_name' => 'Workshop', 'reference_no' => 'WNG-76A2-001',
            'issue_purpose' => InventoryLog::REASON_INTERNAL_CONSUMPTION, 'cost_centre_id' => $this->costCentre(), 'notes' => 'Masking for the spray booth'];

        // No approved account yet (Report 76A-3): the movement is refused whole —
        // the stock stays on the shelf and nothing is posted anywhere.
        $refused = $this->move('issue', $issue)->assertStatus(422);
        $this->assertStringContainsString('ACCOUNTING CONFIGURATION REQUIRED', collect($refused->json('errors'))->flatten()->first());
        $this->assertSame(20.0, $this->stockOnHand($material), 'stock did not leave without its ledger entry');
        $this->assertSame(0, InventoryLog::where('type', 'check_out')->count());
        $this->assertSame($inventoryBefore, $this->balance($this->inventory), 'not posted to a guessed account');

        $this->approveAccount(GovernedPostingAccount::STOCK_INTERNAL_CONSUMPTION, $this->internalUse);
        $this->move('issue', $issue)->assertSuccessful();
        $log = InventoryLog::where('type', 'check_out')->latest('id')->firstOrFail();
        $posting = StoresFinancePosting::where('inventory_log_id', $log->id)->firstOrFail();
        $this->assertSame('internal_consumption', $posting->posting_type);
        $this->assertSame(15.0, $this->stockOnHand($material));

        $this->assertSame('posted', $posting->fresh()->status);
        $this->assertSame('500.00', $this->balance($this->internalUse));
        $this->assertSame(bcsub($inventoryBefore, '500.00', 2), $this->balance($this->inventory));
        $this->assertSame('0.00', $this->balance($this->payable));
        // A job reference on the movement did not make it a project cost.
        $this->assertSame(0, CostLine::count());
        $this->assertSame('0.00', $this->projectActual());
        $this->assertNotNull(DB::table('journal_lines')->where('account_id', $this->internalUse)->value('cost_centre_id'));

        // Reversal: Inventory restored, expense backed out, AP untouched, once.
        $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Issued against the wrong department'])->assertCreated();
        $this->assertSame(20.0, $this->stockOnHand($material));
        $this->assertSame('0.00', $this->balance($this->internalUse));
        $this->assertSame($inventoryBefore, $this->balance($this->inventory));
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame(0, CostLine::count());
        $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Issued against the wrong department'])->assertStatus(422);
    }

    public function test_i_and_j_damage_and_waste_are_losses_on_their_own_accounts_and_never_a_project_cost(): void
    {
        $this->approveAccount(GovernedPostingAccount::STOCK_DAMAGE_LOSS, $this->damage);
        $this->approveAccount(GovernedPostingAccount::STOCK_WASTE_LOSS, $this->waste);
        $material = $this->stocked('Acrylic sheet', 10, 2000);
        $inventoryBefore = $this->balance($this->inventory);

        $this->move('damage', ['material_id' => $material->id, 'quantity' => 2, 'notes' => 'Cracked in the rack', 'project_id' => $this->projectId])->assertSuccessful();
        $damage = InventoryLog::where('type', 'defective')->latest('id')->firstOrFail();
        $this->move('damage', ['material_id' => $material->id, 'quantity' => 1, 'notes' => 'Trimmings from cutting', 'write_off_reason' => InventoryLog::REASON_WASTE])->assertSuccessful();
        $waste = InventoryLog::where('type', 'defective')->latest('id')->firstOrFail();

        $this->assertSame([InventoryLog::REASON_DAMAGE, InventoryLog::REASON_WASTE], [$damage->movement_reason, $waste->movement_reason]);
        $this->assertNull($damage->project_id, 'a write-off carries no project');
        $this->assertSame('4000.00', $this->balance($this->damage));
        $this->assertSame('2000.00', $this->balance($this->waste));
        $this->assertSame(bcsub($inventoryBefore, '6000.00', 2), $this->balance($this->inventory));
        $this->assertSame(7.0, $this->stockOnHand($material));
        $this->assertSame(0, CostLine::count());
        $this->assertSame('0.00', $this->projectActual());
        $this->assertSame('0.00', $this->balance($this->payable));

        foreach ([[$damage, $this->damage], [$waste, $this->waste]] as [$log, $account]) {
            $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Written off in error, stock is sound'])->assertCreated();
            $this->assertSame('0.00', $this->balance($account));
            $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Written off in error, stock is sound'])->assertStatus(422);
        }
        $this->assertSame($inventoryBefore, $this->balance($this->inventory));
        $this->assertSame(10.0, $this->stockOnHand($material));
        $this->assertSame(0, CostLine::count());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /**
     * A board has its own lifecycle and its own scrap action, which wrote the
     * stock movement itself and gave it no value — so the write-off reached the
     * shelf count and never the ledger.
     */
    public function test_i_a_board_scrapped_in_stores_takes_its_value_out_of_inventory_and_is_never_a_project_cost(): void
    {
        $this->approveAccount(GovernedPostingAccount::STOCK_DAMAGE_LOSS, $this->damage);
        $workstation = DB::table('workstations')->insertGetId(['name' => 'Board Shop', 'code' => 'W-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstation, 'material_name' => 'MDF 18mm Sheet', 'material_code' => 'M-'.uniqid(), 'category' => 'Boards',
            'material_type' => 'reusable', 'tracking_mode' => 'dimension_piece', 'issue_disposition' => 'recoverable_remainder',
            'unit_of_measure' => 'sheet', 'unit_cost' => 3000, 'item_status' => 'Active', 'is_active' => true,
        ]);
        $this->move('receive', ['material_id' => $material->id, 'quantity' => 2, 'receipt_unit_cost' => 3000,
            'receipt_reason' => InventoryLog::REASON_OPENING_BALANCE, 'notes' => 'Opening boards counted on the rack'])->assertSuccessful();
        $this->assertSame('6000.00', $this->balance($this->inventory));

        $board = \App\Modules\ProcurementStores\Models\Board::where('library_material_id', $material->id)->firstOrFail();
        $this->postJson("/api/procurement-stores/boards/{$board->id}/transition", [
            'status' => 'Scrapped', 'notes' => 'Water damage in the rack', 'scrap_reason_code' => 'water_damage',
        ])->assertSuccessful();

        $log = InventoryLog::where('type', 'defective')->latest('id')->firstOrFail();
        $this->assertSame(InventoryLog::REASON_DAMAGE, $log->movement_reason);
        $this->assertSame('posted', StoresFinancePosting::where('inventory_log_id', $log->id)->value('status'));
        $this->assertSame(1.0, $this->stockOnHand($material));
        $this->assertSame('3000.00', $this->balance($this->inventory), 'ledger Inventory fell with the shelf');
        $this->assertSame('3000.00', $this->balance($this->damage));
        $this->assertSame(0, CostLine::count());
        $this->assertSame('0.00', $this->balance($this->payable));

        // The board stays scrapped: its write-off is not undone as a stock movement.
        $this->postJson("/api/procurement-stores/inventory-logs/{$log->id}/reverse", ['reason' => 'Board was sound after all'])->assertStatus(422);
        $this->assertSame(1.0, $this->stockOnHand($material));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── K. Count adjustment ─────────────────────────────────────────────── */


    public function test_k_a_count_adjustment_moves_inventory_in_the_ledger_and_is_corrected_by_another_count(): void
    {
        $material = $this->stocked('Cable ties', 100, 10);
        $material->update(['unit_cost' => 10]);
        $inventoryBefore = $this->balance($this->inventory);
        $adjustmentsBefore = $this->balance($this->adjustments);

        // Typing a new balance is no longer a way to change stock.
        $this->postJson('/api/procurement-stores/update-settings', ['material_id' => $material->id, 'stock_quantity' => 90,
            'stock_adjustment_reason' => 'Looked short on the shelf'])->assertStatus(422);
        $this->assertSame(100.0, $this->stockOnHand($material));

        // Decrease: 100 → 94.
        $this->recount($material, 94, 'Six missing at count');
        $this->assertSame(94.0, $this->stockOnHand($material));
        $this->assertSame(bcsub($inventoryBefore, '60.00', 2), $this->balance($this->inventory));
        $this->assertSame(bcadd($adjustmentsBefore, '60.00', 2), $this->balance($this->adjustments));

        // A line of an approved count is not reversed on its own…
        $adjustment = InventoryLog::where('type', 'adjustment')->latest('id')->firstOrFail();
        $this->postJson("/api/procurement-stores/inventory-logs/{$adjustment->id}/reverse", ['reason' => 'The six were found'])
            ->assertStatus(422);
        // …it is corrected by a new count, which posts the opposite entry. Increase: 94 → 100.
        $this->recount($material, 100, 'The six were found behind the rack');
        $this->assertSame(100.0, $this->stockOnHand($material));
        $this->assertSame($inventoryBefore, $this->balance($this->inventory));
        $this->assertSame($adjustmentsBefore, $this->balance($this->adjustments));
        $this->assertSame(2, InventoryLog::where('type', 'adjustment')->count(), 'the original count result is kept');
        $this->assertSame(0, CostLine::count());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── L. Receipt reversal ─────────────────────────────────────────────── */

    public function test_l_a_goods_receipt_is_reversed_not_deleted_and_only_while_nothing_rests_on_it(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $line = $this->receive($item, 40);
        $reverse = fn (GoodsReceiptNoteItem $target) => $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$target->id}/reverse", ['reason' => 'Delivery belonged to another order']);

        // Stock is on the shelf: reverse that first.
        $reverse($line)->assertStatus(422);
        $this->postJson("/api/procurement-stores/inventory-logs/{$line->fresh()->inventory_log_id}/reverse", ['reason' => 'Delivery belonged to another order'])->assertCreated();
        $this->assertSame('40000.00', $this->balance($this->inventory), 'the accrual still stands until the receipt itself is reversed');

        $reverse($line->fresh())->assertOk();
        $this->assertSame('0.00', $this->balance($this->inventory));
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('100000.00', $this->projectLines(CostLine::NATURE_COMMITTED), 'all 100 are on order again');
        $this->assertSame('0.00', $this->projectLines(CostLine::NATURE_ACCRUED));
        $this->assertNotNull($line->fresh()->reversed_at);
        $this->assertSame(1, GoodsReceiptNoteItem::count(), 'the receipt is kept');
        $journals = JournalEntry::count();
        $reverse($line->fresh())->assertOk();
        $this->assertSame($journals, JournalEntry::count(), 'one reversal only');

        // The order can be received again, and a billed receipt cannot be reversed.
        $second = $this->receive($item, 100);
        $this->verify($this->bill($item, 100000))->assertOk();
        $reverse($second)->assertStatus(422);
        $this->assertNull($second->fresh()->reversed_at);
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_l_a_non_stock_receipt_reversal_backs_out_the_project_cost(): void
    {
        $wip = (int) $this->serviceCode->default_debit_account_id;
        $item = $this->order(1, 80000, null, $this->serviceCode);
        $line = $this->receive($item, 1);
        $this->assertSame('80000.00', $this->projectActual());

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/reverse", ['reason' => 'Service was not actually delivered'])->assertOk();

        $this->assertSame('0.00', $this->projectActual());
        $this->assertSame('0.00', $this->balance($wip));
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('80000.00', $this->projectLines(CostLine::NATURE_COMMITTED));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── M. Company-paid authority ───────────────────────────────────────── */

    public function test_m_a_company_paid_cost_needs_payment_authority_as_well_as_cost_verification(): void
    {
        PettyCashTopUp::create(['amount' => 500000, 'payment_method' => 'cash', 'date_topped_up' => now()->subMonth()->toDateString(), 'created_by' => $this->user->id]);
        PettyCashBalance::current()->update(['current_balance' => 500000]);
        $float = PaymentSource::where('code', 'PC-MAIN')->firstOrFail();

        $capture = fn (string $mode) => CostLine::findOrFail($this->postJson('/api/costs', [
            'expense_code' => $this->overheadCode->code, 'amount' => 1200, 'payee_name' => 'Kiosk', 'description' => 'Airtime',
            'funding_mode' => $mode, 'payment_source_id' => $mode === 'company_paid' ? $float->id : null,
        ])->assertCreated()->json('data.id'));

        $verifierOnly = User::factory()->create(['is_active' => true]);
        $verifierOnly->givePermissionTo([Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_COSTS_READ]);
        $payerOnly = User::factory()->create(['is_active' => true]);
        $payerOnly->givePermissionTo([Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_COSTS_READ]);
        $both = User::factory()->create(['is_active' => true]);
        $both->givePermissionTo([Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_PETTY_CASH_CREATE]);

        $companyPaid = $capture('company_paid');
        $outOfPocket = $capture('out_of_pocket');

        // Cost verification alone cannot move company money…
        $this->actingAs($verifierOnly, 'sanctum');
        $this->assertFalse(CostVerificationService::maySettleCompanyPaid($verifierOnly));
        $this->postJson("/api/costs/verification/{$companyPaid->id}/verify")->assertForbidden()
            ->assertJsonPath('message', CostVerificationService::SETTLEMENT_AUTHORITY_MESSAGE);
        $this->assertSame(CostLine::STATUS_SUBMITTED, $companyPaid->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertSame('500000.00', number_format((float) PettyCashBalance::current()->fresh()->current_balance, 2, '.', ''));
        // …but still verifies a cost that moves none.
        $this->postJson("/api/costs/verification/{$outOfPocket->id}/verify")->assertOk();
        $this->assertSame(0, Payment::count());

        // Payment authority alone cannot verify a cost.
        $this->actingAs($payerOnly, 'sanctum')->postJson("/api/costs/verification/{$companyPaid->id}/verify")->assertForbidden();
        $this->assertSame(0, Payment::count());

        // Both: verified, one payment, float moved.
        $this->actingAs($both, 'sanctum')->postJson("/api/costs/verification/{$companyPaid->id}/verify")->assertOk();
        $this->assertSame(CostLine::STATUS_VERIFIED, $companyPaid->fresh()->status);
        $this->assertSame(1, Payment::count());
        $this->assertSame('498800.00', number_format((float) PettyCashBalance::current()->fresh()->current_balance, 2, '.', ''));

        // Nobody was given anything by the code.
        $this->assertFalse($verifierOnly->fresh()->can(Permissions::FINANCE_PETTY_CASH_CREATE));
        $this->assertFalse($payerOnly->fresh()->can(Permissions::FINANCE_COSTS_VERIFY));
    }

    /* ── Governance ──────────────────────────────────────────────────────── */

    public function test_the_new_accounts_are_governed_items_with_no_suggestion_and_no_default(): void
    {
        $this->user->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_CONFIG_VIEW, 'web'));

        $approvedByFixture = [GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT, GovernedPostingAccount::STOCK_OPENING_BALANCE];
        foreach (array_diff(array_keys(GovernedPostingAccount::FUNCTIONS), $approvedByFixture) as $function) {
            $item = $this->getJson('/api/finance/governance/items/'.GovernedPostingAccount::item($function))->assertOk()->json('data');
            $this->assertSame('account', $item['type']);
            $this->assertNotSame('active', $item['state']);
            $this->assertNull($item['suggestion'] ?? null, 'no account is proposed on WNG\'s behalf');
            $this->assertNull(GovernedPostingAccount::code($function));
        }

        // Only an approved, ACTIVE version counts: a proposal or an approval waiting to be activated does not.
        FinanceConfigVersion::create([
            'item_key' => GovernedPostingAccount::item(GovernedPostingAccount::STOCK_DAMAGE_LOSS), 'domain' => 'policies', 'version' => 1,
            'value' => ['account_code' => 'T-DMG'], 'status' => FinanceConfigVersion::APPROVED, 'proposed_by' => $this->user->id,
            'in_force_from' => now()->toDateString(),
        ]);
        GovernanceRuntime::flush();
        $this->assertNull(GovernedPostingAccount::code(GovernedPostingAccount::STOCK_DAMAGE_LOSS));

        $this->approveAccount(GovernedPostingAccount::STOCK_WASTE_LOSS, $this->waste);
        $this->assertSame('T-WST', GovernedPostingAccount::code(GovernedPostingAccount::STOCK_WASTE_LOSS));
        // An approved account that has since been closed is not posted to.
        ChartOfAccount::whereKey($this->waste)->update(['is_active' => false]);
        $this->expectExceptionMessage('ACCOUNTING CONFIGURATION REQUIRED');
        GovernedPostingAccount::require(GovernedPostingAccount::STOCK_WASTE_LOSS, 'a test write-off');
    }
}
