<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Support\GovernedPostingAccount;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillLine;
use App\Modules\ProcurementStores\Models\BillLineReceiptAllocation;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\ReceiptAccrualCloseout;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Models\SupplierCreditNote;
use App\Modules\ProcurementStores\Services\InventoryService;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 76A-3 — final accounting integrity acceptance.
 *
 * Every flow asserts the ACCOUNTS and the amounts on them, the stock quantity
 * and its value, the project's cost and the supplier balance — and ends by
 * checking that no journal is unbalanced.
 */
class Report76A3AcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Finance\Concerns\ProcurementAccountingFixtures {
        setUp as fixturesSetUp;
    }

    protected function setUp(): void
    {
        $this->fixturesSetUp();
        $this->user->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_JOURNALS_REVERSE, 'web'));
    }

    private function line(int $itemId, float $quantity, float $price, array $more = []): array
    {
        return $more + ['purchase_order_item_id' => $itemId, 'quantity' => $quantity, 'unit_price_net' => $price];
    }

    private function partPay(Bill $bill, float $amount): void
    {
        $bank = PaymentSource::where('type', 'bank')->where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => $amount, 'payment_date' => now()->toDateString(), 'payment_source_id' => $bank->id,
            'payment_method' => 'bank_transfer', 'reference_number' => 'TRX-'.uniqid(),
        ])->assertSuccessful();
    }

    private function issue(int $materialId, float $quantity): void
    {
        app(InventoryService::class)->adjustStock($materialId, -$quantity, 'check_out', ['project_id' => $this->projectId, 'recipient_name' => 'Site']);
    }

    private function position(int $orderId): array
    {
        return app(PurchaseOrderWorkflow::class)->order(PurchaseOrder::findOrFail($orderId));
    }

    private function stockValue(int $materialId): string
    {
        $row = DB::table('stocks')->join('library_materials', 'library_materials.id', '=', 'stocks.material_id')
            ->where('stocks.material_id', $materialId)->selectRaw('stocks.quantity_on_hand * library_materials.unit_cost v')->first();

        return number_format((float) ($row->v ?? 0), 2, '.', '');
    }

    /* ── A. Stocked: partial GRNs → bill lines → partial payments → issue ── */

    public function test_a_partial_receipts_are_billed_by_line_against_the_receipts_they_cover(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $first = $this->receive($item, 40);
        $second = $this->receive($item, 60);
        $this->assertSame('-100000.00', $this->balance($this->accrued));

        // 70 billed: the server takes the 40 of the first receipt and 30 of the second.
        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 70, 1000)], 11200));
        $allocations = BillLineReceiptAllocation::orderBy('id')->get();
        $this->assertSame([[$first->id, 40.0, 40000.0], [$second->id, 30.0, 30000.0]],
            $allocations->map(fn ($a) => [$a->goods_receipt_note_item_id, (float) $a->quantity, (float) $a->receipt_value])->all());
        $this->assertSame('70000.00', $bill->receiptValueCovered());
        $this->verify($bill)->assertOk();

        $this->assertSame('-30000.00', $this->balance($this->accrued), '30 received and not yet invoiced');
        $this->assertSame('11200.00', $this->balance($this->inputVat));
        $this->assertSame('-81200.00', $this->balance($this->payable));
        $position = $this->position($item->purchase_order_id);
        $this->assertSame(['fully_received', 'partially_billed', 'unpaid'], [$position['receipt_status'], $position['billing_status'], $position['payment_status']]);
        $this->assertSame('30000.00', $position['received_not_invoiced']);
        // One part bill does not mark every receipt settled.
        $this->assertSame(0, CostLine::where('source_ref', 'accrual')->whereNotNull('settled_by_bill_id')->count());

        // Partial payments settle AP only.
        $this->partPay($bill, 50000);
        $this->assertSame('-31200.00', $this->balance($this->payable));
        $this->assertSame('partially_paid', $this->position($item->purchase_order_id)['payment_status']);
        $this->assertSame('0.00', $this->projectActual());

        // The remaining 30, naming the receipt.
        $rest = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 30, 1000, [
            'allocations' => [['goods_receipt_note_item_id' => $second->id, 'quantity' => 30]],
        ])], 4800));
        $this->verify($rest)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('fully_billed', $this->position($item->purchase_order_id)['billing_status']);

        $this->partPay($bill->fresh(), 31200);
        $this->pay($rest->fresh());
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('100000.00', $this->balance($this->inventory));

        $this->issue($material->id, 25);
        $this->assertSame('25000.00', $this->projectActual(), 'the Stores issue is the one project cost');
        $this->assertSame('75000.00', $this->balance($this->inventory));
        $this->assertSame('75000.00', $this->stockValue($material->id), 'subledger value = ledger Inventory');
        $this->assertSame(0, CostLine::where('source_type', Bill::class)->count());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── B. Price change before and after stock consumption ───────────────── */

    public function test_b_a_price_increase_after_part_of_the_stock_was_issued_splits_between_stock_and_variance(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $this->receive($item, 100);
        $this->issue($material->id, 40);
        $this->assertSame('40000.00', $this->projectActual());

        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 100, 1050, ['variance_reason' => 'Supplier price rose after the order'])], 16800));

        // 60 of the 100 are still on hand: 3,000 belongs in stock. The other
        // 2,000 is on goods already issued, and has nowhere approved to go.
        $refused = $this->verify($bill)->assertStatus(422);
        $this->assertContains('PURCHASE PRICE VARIANCE ACCOUNT REQUIRED', $refused->json('blockers'));
        $this->assertSame('-100000.00', $this->balance($this->accrued), 'nothing was posted anywhere instead');
        $this->assertSame(1000.0, round((float) $material->fresh()->unit_cost, 4));

        $this->approveAccount(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, $this->variance);
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('63000.00', $this->balance($this->inventory), '60,000 on hand + 3,000 of the increase');
        $this->assertSame('2000.00', $this->balance($this->variance), 'the increase on the 40 already issued');
        $this->assertSame(1050.0, round((float) $material->fresh()->unit_cost, 4));
        $this->assertSame('63000.00', $this->stockValue($material->id));
        $this->assertSame('-121800.00', $this->balance($this->payable));
        $this->assertSame('40000.00', $this->projectActual(), 'cost already on the job is not reopened or doubled');
        $line = BillLine::firstOrFail();
        $this->assertSame(['3000.00', '2000.00'], [(string) $line->posted_to_inventory, (string) $line->posted_to_variance]);

        // What is issued from here carries the true cost.
        $this->issue($material->id, 10);
        $this->assertSame('50500.00', $this->projectActual());
        $this->assertSame('52500.00', $this->balance($this->inventory));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_b_a_price_decrease_after_everything_was_issued_is_all_variance(): void
    {
        $this->approveAccount(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, $this->variance);
        $material = $this->material('MDF 18mm');
        $item = $this->order(10, 1000, $material->id);
        $this->receive($item, 10);
        $this->issue($material->id, 10);

        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 10, 900, ['variance_reason' => 'Discount agreed on delivery'])]));
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('0.00', $this->balance($this->inventory), 'no stock left to revalue');
        $this->assertSame('-1000.00', $this->balance($this->variance));
        $this->assertSame('-9000.00', $this->balance($this->payable));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── C. VAT: recoverable, not recoverable, and the tax document ───────── */

    public function test_c_vat_that_cannot_be_recovered_is_cost_of_the_stock_not_a_variance(): void
    {
        $nonRecoverable = VatTreatment::where('is_recoverable', false)->where('is_active', true)->where('rate_percent', '>', 0)->first()
            ?? VatTreatment::create(['code' => 'T-NREC', 'name' => 'VAT not recoverable', 'rate_percent' => 16, 'is_recoverable' => false,
                'requires_etims' => false, 'is_active' => true, 'effective_from' => now()->subYear()->toDateString()]);
        $this->supplier->update(['default_vat_treatment_id' => $nonRecoverable->id]);
        $material = $this->material('Cable');
        $item = $this->order(10, 1000, $material->id);
        $this->receive($item, 10);

        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 10, 1000)], 1600));
        $this->assertSame($nonRecoverable->id, (int) $bill->vat_treatment_id);
        // Same price as the order: nothing to explain, and no variance account needed.
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->inputVat), 'not claimed');
        $this->assertSame('11600.00', $this->balance($this->inventory), 'the stock cost what was paid for it');
        $this->assertSame(1160.0, round((float) $material->fresh()->unit_cost, 4));
        $this->assertSame('0.00', $this->balance($this->variance));
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-11600.00', $this->balance($this->payable));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_c_a_tax_inclusive_quote_is_normalised_to_a_net_order_and_the_quote_is_kept(): void
    {
        $treatment = VatTreatment::findOrFail($this->supplier->default_vat_treatment_id);
        $treatment->update(['rate_percent' => 16]);
        $material = $this->material('MDF 18mm');
        $order = fn (array $item, ?int $supplierId = null) => $this->postJson('/api/procurement-stores/purchase-orders', [
            'date' => now()->toDateString(), 'supplier_id' => $supplierId ?? $this->supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'items' => [$item + ['material_id' => $material->id, 'quantity' => 10]],
        ]);

        // Quoted 1,160 a unit, tax included: the order is held at 1,000 net.
        $id = $order(['unit_price' => 1160, 'price_includes_tax' => true])->assertSuccessful()->json('data.id');
        $saved = PurchaseOrder::with('items')->findOrFail($id);
        $line = $saved->items->first();
        $this->assertSame('net', $saved->price_basis);
        $this->assertSame('10000.00', (string) $saved->total_amount, 'the order value is net of VAT');
        $this->assertSame(['1000.00', '1160.0000', true, '10000.00', '1600.00', '11600.00'], [
            (string) $line->unit_price, (string) $line->quoted_unit_price, $line->quoted_price_includes_tax,
            (string) $line->net_amount, (string) $line->tax_amount, (string) $line->gross_amount,
        ]);
        $this->assertSame($treatment->id, (int) $line->vat_treatment_id);
        $this->assertSame(16.0, (float) $line->tax_rate_percent);

        // A net quote is kept as the net price, with the tax it will carry worked out beside it.
        $net = PurchaseOrder::with('items')->findOrFail($order(['unit_price' => 1000])->assertSuccessful()->json('data.id'))->items->first();
        $this->assertSame(['1000.00', false, '10000.00', '1600.00', '11600.00'],
            [(string) $net->unit_price, $net->quoted_price_includes_tax, (string) $net->net_amount, (string) $net->tax_amount, (string) $net->gross_amount]);

        // Tax included, and nothing says at what rate: refused. No rate is assumed.
        $this->supplier->update(['default_vat_treatment_id' => null]);
        $order(['unit_price' => 1160, 'price_includes_tax' => true])->assertStatus(422);

        // An order from before the rule is not reinterpreted.
        $legacy = $this->order(10, 1160, $material->id);
        $this->assertNull(PurchaseOrder::findOrFail($legacy->purchase_order_id)->price_basis);
        $this->assertNull($legacy->fresh()->quoted_unit_price);
        $this->assertSame('1160.00', (string) $legacy->fresh()->unit_price);
    }

    /* ── D. Non-stock purchases, billed by line ───────────────────────────── */

    public function test_d_a_service_is_one_project_cost_at_confirmation_and_its_price_difference_is_a_governed_variance(): void
    {
        $wip = (int) $this->serviceCode->default_debit_account_id;
        $item = $this->order(1, 80000, null, $this->serviceCode);
        $this->receive($item, 1);
        $this->assertSame('80000.00', $this->projectActual());
        $this->assertSame('0.00', $this->balance($this->inventory), 'a service is never stock');

        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 1, 82000, ['variance_reason' => 'Extra call-out agreed on site'])], 13120));
        // Nothing on a shelf to revalue: the difference needs the approved account.
        $refused = $this->verify($bill)->assertStatus(422);
        $this->assertContains('PURCHASE PRICE VARIANCE ACCOUNT REQUIRED', $refused->json('blockers'));
        $this->approveAccount(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, $this->variance);
        $this->verify($bill)->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('80000.00', $this->balance($wip));
        $this->assertSame('2000.00', $this->balance($this->variance));
        $this->assertSame('-95120.00', $this->balance($this->payable));
        $this->assertSame('80000.00', $this->projectActual(), 'the bill adds no second project cost');
        $this->pay($bill->fresh());
        $this->assertSame('80000.00', $this->projectActual(), 'nor does the payment');
        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_ACTUAL)->where('status', 'verified')->count());

        // Overhead: an expense at confirmation, never a project cost, never stock.
        $overhead = $this->order(1, 12000, null, $this->overheadCode, forProject: false);
        $this->receive($overhead, 1);
        $this->verify($this->billed($this->billLines($overhead->purchase_order_id, [$this->line($overhead->id, 1, 12000)], 1920)))->assertOk();
        $this->assertSame('12000.00', $this->balance((int) $this->overheadCode->default_debit_account_id));
        $this->assertSame('80000.00', $this->projectActual());
        $this->assertSame('0.00', $this->balance($this->inventory));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_c_input_vat_without_its_tax_document_is_stated_on_the_invoice_and_left_to_the_claim_gate(): void
    {
        $treatment = VatTreatment::findOrFail($this->supplier->default_vat_treatment_id);
        $treatment->update(['requires_etims' => true]);
        $item = $this->order(10, 1000, $this->material('Cable')->id);
        $this->receive($item, 10);

        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 10, 1000)], 1600, ['etims_invoice_no' => null]));
        $state = app(PurchaseOrderWorkflow::class)->bill($bill);
        $this->assertSame([true, true, false], [$state['tax_document']['claims_input_vat'], $state['tax_document']['requires_etims'], $state['tax_document']['supported']]);
        $this->assertStringContainsString('at risk', $state['tax_document']['message']);

        // WNG's existing rule stands: verified and posted, and the VAT return is where the claim is held.
        $this->verify($bill)->assertOk();
        $this->assertSame('1600.00', $this->balance($this->inputVat));

        $bill->forceFill(['etims_invoice_no' => 'KRA-0001'])->save();
        $this->assertTrue(app(PurchaseOrderWorkflow::class)->bill($bill->fresh())['tax_document']['supported']);
    }

    /* ── E. Mismatch, duplicate allocation, overbill, rounding ────────────── */

    public function test_e_lines_must_agree_with_the_invoice_and_a_receipt_is_billed_once(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $other = $this->order(5, 200, $this->material('Screws')->id);
        $receipt = $this->receive($item, 40);
        $post = fn (array $payload) => $this->postJson('/api/procurement-stores/bills', $payload + [
            'purchase_order_id' => $item->purchase_order_id, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'vat_amount' => 0, 'wht_amount' => 0, 'supplier_invoice_number' => 'SINV-'.uniqid(),
        ]);

        // Lines that do not add up to the invoice.
        $post(['amount' => 30000, 'lines' => [$this->line($item->id, 30, 999)]])->assertStatus(422);
        // A line of another order.
        $post(['amount' => 1000, 'lines' => [$this->line($other->id, 5, 200)]])->assertStatus(422);
        // The same order line twice.
        $post(['amount' => 30000, 'lines' => [$this->line($item->id, 10, 1000), $this->line($item->id, 20, 1000)]])->assertStatus(422);
        // More named on a receipt than it has.
        $post(['amount' => 50000, 'lines' => [$this->line($item->id, 50, 1000, ['allocations' => [['goods_receipt_note_item_id' => $receipt->id, 'quantity' => 50]]])]])
            ->assertStatus(422);
        // VAT that does not agree with the rate on the lines.
        $post(['amount' => 35000, 'vat_amount' => 5000, 'lines' => [$this->line($item->id, 30, 1000)]])->assertStatus(422);
        $this->assertSame(0, Bill::count(), 'a refused invoice leaves nothing behind');
        $this->assertSame(0, BillLine::count());

        // 30 of the 40 billed and verified.
        $first = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 30, 1000)]));
        $this->verify($first)->assertOk();

        // 20 more: only 10 are received and unbilled. It can be recorded — the
        // invoice may be ahead of the goods — but it does not pass the match.
        $ahead = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 20, 1000)]));
        $this->assertSame('unmatched', BillLine::where('bill_id', $ahead->id)->value('status'));
        $refused = $this->verify($ahead)->assertStatus(422);
        $this->assertContains('Every invoice line is matched to accepted receipts', $refused->json('blockers'));
        $this->assertSame('-10000.00', $this->balance($this->accrued));

        // The same receipt quantity cannot be named by two invoices.
        $post(['amount' => 20000, 'lines' => [$this->line($item->id, 20, 1000, ['allocations' => [['goods_receipt_note_item_id' => $receipt->id, 'quantity' => 20]]])]])
            ->assertStatus(422);

        // When the goods arrive, verification matches the waiting line and passes.
        $this->receive($item, 60);
        $this->verify($ahead->fresh())->assertOk();
        $this->assertSame('matched', BillLine::where('bill_id', $ahead->id)->value('status'));
        $this->assertSame('-50000.00', $this->balance($this->accrued));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_e_rounding_never_strands_cents_in_the_accrual(): void
    {
        // 3 units at 33.33 accrue 99.99; billed 1 + 2.
        $material = $this->material('Hinges');
        $item = $this->order(3, 33.33, $material->id);
        $this->receive($item, 3);
        $this->assertSame('-99.99', $this->balance($this->accrued));

        $this->verify($this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 1, 33.33)])))->assertOk();
        $this->verify($this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 2, 33.33)])))->assertOk();

        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-99.99', $this->balance($this->payable));
        $this->assertSame(0, $this->unbalancedJournals());

        // VAT on three lines adds up to the document's VAT exactly.
        $a = $this->order(1, 10.01, $this->material('A')->id);
        $order = PurchaseOrder::findOrFail($a->purchase_order_id);
        $b = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'requisition_item_id' => $a->requisition_item_id,
            'material_id' => $this->material('B')->id, 'quantity' => 1, 'unit_price' => 10.01, 'total' => 10.01]);
        $c = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'requisition_item_id' => $a->requisition_item_id,
            'material_id' => $this->material('C')->id, 'quantity' => 1, 'unit_price' => 10.01, 'total' => 10.01]);
        $order->update(['total_amount' => 30.03]);
        $bill = $this->billed($this->billLines($order->id, [$this->line($a->id, 1, 10.01), $this->line($b->id, 1, 10.01), $this->line($c->id, 1, 10.01)], 4.80));
        $this->assertSame('4.80', number_format((float) BillLine::where('bill_id', $bill->id)->sum('vat_amount'), 2, '.', ''));
        $this->assertSame(['1.60', '1.60', '1.60'], BillLine::where('bill_id', $bill->id)->orderBy('line_no')->pluck('vat_amount')->map(fn ($v) => (string) $v)->all());
    }

    /* ── F. Supplier credits and bill reversal ────────────────────────────── */

    public function test_f_a_price_credit_reduces_the_liability_and_the_stock_value_and_moves_no_stock(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $this->receive($item, 100);
        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 100, 1000)], 16000));
        $this->verify($bill)->assertOk();
        $line = BillLine::firstOrFail();
        $credit = fn (array $lines, array $more = []) => $this->postJson("/api/procurement-stores/bills/{$bill->id}/credit-notes", $more + [
            'supplier_reference' => 'CN-'.uniqid(), 'credit_date' => now()->toDateString(), 'reason' => 'Rebate agreed after delivery', 'lines' => $lines,
        ]);

        // Not more than the line, and not a second time under the same number.
        $credit([['bill_line_id' => $line->id, 'net_amount' => 100001]])->assertStatus(422);

        $credit([['bill_line_id' => $line->id, 'net_amount' => 5000]], ['supplier_reference' => 'CN-1', 'vat_amount' => 800])->assertCreated();
        $credit([['bill_line_id' => $line->id, 'net_amount' => 5000]], ['supplier_reference' => 'CN-1'])->assertStatus(422);

        $this->assertSame(100.0, $this->stockOnHand($material), 'a credit is not a stock return');
        $this->assertSame('95000.00', $this->balance($this->inventory), 'the stock on hand cost less');
        $this->assertSame(950.0, round((float) $material->fresh()->unit_cost, 4));
        $this->assertSame('15200.00', $this->balance($this->inputVat), 'input VAT given back');
        $this->assertSame('-110200.00', $this->balance($this->payable));
        $this->assertSame('0.00', $this->balance($this->accrued), 'a price credit un-bills nothing');
        $this->assertSame('110200.00', number_format((float) $bill->fresh()->balance, 2, '.', ''));
        $this->assertSame('fully_billed', $this->position($item->purchase_order_id)['billing_status']);
        // Still verified and payable after the credit.
        $this->assertTrue(app(PurchaseOrderWorkflow::class)->bill($bill->fresh())['can_pay']);

        // Pay what is left; then nothing more can be credited away.
        $this->pay($bill->fresh());
        $this->assertSame('0.00', $this->balance($this->payable));
        $credit([['bill_line_id' => $line->id, 'net_amount' => 1000]])->assertStatus(422);
        $this->assertSame(0, InventoryLog::where('type', '!=', 'check_in')->count());
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_f_a_quantity_credit_puts_the_receipt_back_to_received_not_invoiced(): void
    {
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $receipt = $this->receive($item, 100);
        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 100, 1000)], 16000));
        $this->verify($bill)->assertOk();
        $line = BillLine::firstOrFail();

        // The supplier over-invoiced by 10 units and credits them.
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/credit-notes", [
            'supplier_reference' => 'CN-QTY', 'credit_date' => now()->toDateString(), 'reason' => 'Ten units invoiced twice by the supplier',
            'lines' => [['bill_line_id' => $line->id, 'quantity' => 10, 'net_amount' => 10000]],
        ])->assertCreated();

        $this->assertSame('-10000.00', $this->balance($this->accrued), 'those ten are received and awaiting an invoice again');
        $this->assertSame('100000.00', $this->balance($this->inventory), 'stock is untouched');
        $this->assertSame(100.0, $this->stockOnHand($material));
        $this->assertSame('-104400.00', $this->balance($this->payable));
        $this->assertSame('14400.00', $this->balance($this->inputVat));
        $position = $this->position($item->purchase_order_id);
        $this->assertSame('partially_billed', $position['billing_status']);
        $this->assertSame('10000.00', $position['received_not_invoiced']);
        // More than is still billed on the line cannot be credited.
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/credit-notes", [
            'supplier_reference' => 'CN-QTY2', 'credit_date' => now()->toDateString(), 'reason' => 'Trying to credit more than is billed',
            'lines' => [['bill_line_id' => $line->id, 'quantity' => 95, 'net_amount' => 95000]],
        ])->assertStatus(422);

        // They can be invoiced again, against the same receipt…
        $again = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 10, 1000)], 1600));
        $this->verify($again)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));

        // …or, had the supplier never re-invoiced, closed out by Finance. Nothing is left to close here.
        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$receipt->id}/close-out-accrual", ['reason' => 'Supplier confirmed no further invoice'])
            ->assertStatus(422);
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_f_a_posted_bill_is_reversed_once_and_only_while_unpaid(): void
    {
        $this->approveAccount(GovernedPostingAccount::PURCHASE_PRICE_VARIANCE, $this->variance);
        $material = $this->material('MDF 18mm');
        $item = $this->order(100, 1000, $material->id);
        $this->receive($item, 100);
        $bill = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 100, 1050, ['variance_reason' => 'Supplier price rose after the order'])], 16800));
        $this->verify($bill)->assertOk();
        $this->assertSame('105000.00', $this->balance($this->inventory));
        $reverse = fn () => $this->postJson("/api/procurement-stores/bills/{$bill->id}/reverse", ['reason' => 'Entered against the wrong order']);

        // Needs journal-reversal authority as well as payables verification.
        $verifier = User::factory()->create(['is_active' => true]);
        $verifier->givePermissionTo(Permissions::FINANCE_PAYABLES_VERIFY);
        $this->actingAs($verifier, 'sanctum');
        $reverse()->assertForbidden();
        $this->actingAs($this->user, 'sanctum');

        // A paid bill is not reversed underneath its payment.
        $this->partPay($bill, 1000);
        $reverse()->assertStatus(422);
        $payment = Payment::latest('id')->firstOrFail();
        $this->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'Paid against the wrong invoice'])->assertSuccessful();

        $journals = JournalEntry::count();
        $reverse()->assertOk();
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('0.00', $this->balance($this->inputVat));
        $this->assertSame('-100000.00', $this->balance($this->accrued), 'the receipt is received-not-invoiced again');
        $this->assertSame('100000.00', $this->balance($this->inventory), 'the revaluation is taken back');
        $this->assertSame(1000.0, round((float) $material->fresh()->unit_cost, 4));
        $this->assertSame('0.00', $this->balance($this->variance));
        $this->assertNotNull($bill->fresh()->reversed_at);
        $this->assertSame('0.00', number_format((float) $bill->fresh()->balance, 2, '.', ''));
        $this->assertSame(1, SupplierCreditNote::where('kind', 'reversal')->count());
        $this->assertNotNull(JournalEntry::where('entry_no', 'JE-BILL-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT))->first(), 'the original entry is kept');
        $this->assertSame('not_billed', $this->position($item->purchase_order_id)['billing_status']);

        // Once.
        $after = JournalEntry::count();
        $this->assertSame($journals + 1, $after);
        $reverse()->assertOk();
        $this->assertSame($after, JournalEntry::count());
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/credit-notes", ['supplier_reference' => 'X', 'credit_date' => now()->toDateString(),
            'reason' => 'Crediting a reversed invoice', 'lines' => [['bill_line_id' => BillLine::value('id'), 'net_amount' => 1]]])->assertStatus(422);

        // The receipt can be invoiced correctly now.
        $correct = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 100, 1000)], 16000));
        $this->verify($correct)->assertOk();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-116000.00', $this->balance($this->payable));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_f_reversing_a_direct_bill_takes_back_its_expense_and_its_project_cost(): void
    {
        $wip = (int) $this->serviceCode->default_debit_account_id;
        $bill = $this->billed($this->postJson('/api/procurement-stores/bills', [
            'supplier_id' => $this->supplier->id, 'expense_code_id' => $this->serviceCode->id, 'project_enquiry_id' => $this->enquiryId,
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 23200, 'vat_amount' => 3200,
            'wht_amount' => 0, 'supplier_invoice_number' => 'DIRECT-1', 'etims_invoice_no' => 'ETIMS-D1',
        ]));
        $this->verify($bill)->assertOk();
        $this->assertSame('20000.00', $this->balance($wip));
        $this->assertSame('-23200.00', $this->balance($this->payable));
        $this->assertSame('20000.00', $this->projectActual());

        // No lines to credit against: a direct bill is reversed whole.
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/credit-notes", ['supplier_reference' => 'CN-D', 'credit_date' => now()->toDateString(),
            'reason' => 'Trying to part-credit a direct bill', 'lines' => [['bill_line_id' => 1, 'net_amount' => 100]]])->assertStatus(422);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/reverse", ['reason' => 'Recorded against the wrong supplier'])->assertOk();
        $this->assertSame('0.00', $this->balance($wip));
        $this->assertSame('0.00', $this->balance($this->payable));
        $this->assertSame('0.00', $this->balance($this->inputVat));
        $this->assertSame('0.00', $this->projectActual(), 'the project no longer carries the cost');
        $this->assertSame('0.00', number_format((float) $bill->fresh()->balance, 2, '.', ''));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    public function test_f_received_value_never_invoiced_is_closed_out_only_to_an_approved_account(): void
    {
        $item = $this->order(100, 1000, $this->material('MDF 18mm')->id);
        $receipt = $this->receive($item, 100);
        $this->verify($this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 97, 1000)])))->assertOk();
        $this->assertSame('-3000.00', $this->balance($this->accrued));
        $close = fn () => $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$receipt->id}/close-out-accrual", ['reason' => 'Supplier confirmed three units were free of charge']);

        $refused = $close()->assertStatus(422);
        $this->assertStringContainsString('ACCOUNTING CONFIGURATION REQUIRED', collect($refused->json('errors'))->flatten()->first());
        $this->assertSame('-3000.00', $this->balance($this->accrued));

        $closeoutAccount = $this->expenseAccount('T-GRNI', 'Receipts never invoiced');
        $this->approveAccount(GovernedPostingAccount::RECEIPT_ACCRUAL_CLOSEOUT, $closeoutAccount);
        $close()->assertCreated();
        $this->assertSame('0.00', $this->balance($this->accrued));
        $this->assertSame('-3000.00', $this->balance($closeoutAccount));
        $this->assertSame('100000.00', $this->balance($this->inventory));
        $this->assertSame(1, ReceiptAccrualCloseout::count());
        $close()->assertCreated();
        $this->assertSame(1, ReceiptAccrualCloseout::count(), 'once');
        // Nothing is left to invoice on that receipt.
        $late = $this->billed($this->billLines($item->purchase_order_id, [$this->line($item->id, 3, 1000)]));
        $this->verify($late)->assertStatus(422);
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── G/H. Stock and ledger move together, or neither moves ────────────── */

    public function test_h_a_closed_period_refuses_the_stock_movement_with_its_entry(): void
    {
        $this->approveAccount(GovernedPostingAccount::STOCK_DAMAGE_LOSS, $this->damage);
        $material = $this->stocked('Acrylic sheet', 10, 2000);
        $inventory = $this->balance($this->inventory);
        AccountingPeriod::forDate(now())->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        $refused = $this->move('damage', ['material_id' => $material->id, 'quantity' => 2, 'notes' => 'Cracked in the rack'])->assertStatus(422);
        $this->assertNotEmpty($refused->json('errors'));
        $this->assertSame(10.0, $this->stockOnHand($material), 'the shelf did not move without the ledger');
        $this->assertSame(0, InventoryLog::where('type', 'defective')->count());
        $this->assertSame(0, StoresFinancePosting::whereIn('posting_type', array_values(InventoryLog::NON_PROJECT_POSTINGS))->count());
        $this->assertSame($inventory, $this->balance($this->inventory));

        AccountingPeriod::forDate(now())->update(['status' => AccountingPeriod::STATUS_OPEN]);
        $this->move('damage', ['material_id' => $material->id, 'quantity' => 2, 'notes' => 'Cracked in the rack'])->assertSuccessful();
        $this->assertSame(8.0, $this->stockOnHand($material));
        $this->assertSame('4000.00', $this->balance($this->damage));
        $this->assertSame('posted', StoresFinancePosting::latest('id')->value('status'));
    }

    public function test_h_damage_waste_and_counts_are_refused_without_an_approved_account(): void
    {
        $material = $this->stocked('Acrylic sheet', 10, 2000);
        $inventory = $this->balance($this->inventory);

        foreach ([[], ['write_off_reason' => InventoryLog::REASON_WASTE]] as $more) {
            $refused = $this->move('damage', $more + ['material_id' => $material->id, 'quantity' => 1, 'notes' => 'Cracked in the rack'])->assertStatus(422);
            $this->assertStringContainsString('ACCOUNTING CONFIGURATION REQUIRED', collect($refused->json('errors'))->flatten()->first());
        }
        $this->assertSame(10.0, $this->stockOnHand($material));
        $this->assertSame($inventory, $this->balance($this->inventory));

        // A count, and stock taken in without a purchase, no longer fall back to the reference chart.
        DB::table('finance_config_versions')->whereIn('item_key', [
            GovernedPostingAccount::item(GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT), GovernedPostingAccount::item(GovernedPostingAccount::STOCK_OPENING_BALANCE),
        ])->delete();
        \App\Modules\Finance\Governance\GovernanceRuntime::flush();
        $this->assertNotNull(ChartOfAccount::whereKey($this->adjustments)->where('is_active', true)->first(), 'the reference account exists — and is not used');

        $this->move('receive', ['material_id' => $material->id, 'quantity' => 5, 'receipt_unit_cost' => 2000,
            'receipt_reason' => InventoryLog::REASON_DISCOVERED_STOCK, 'notes' => 'Found in the old store during clear-out'])->assertStatus(422);
        $id = $this->postJson('/api/procurement-stores/stock-counts', ['counted_on' => now()->toDateString()])->assertSuccessful()->json('data.id');
        $countItem = DB::table('stock_count_items')->where('stock_count_id', $id)->where('material_id', $material->id)->first();
        $this->putJson("/api/procurement-stores/stock-counts/{$id}", ['items' => [['id' => $countItem->id, 'counted_quantity' => 8, 'variance_reason' => 'Two missing']]])->assertSuccessful();
        $this->postJson("/api/procurement-stores/stock-counts/{$id}/submit")->assertSuccessful();
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->givePermissionTo([Permissions::STORES_REVIEW, Permissions::STORES_MANAGE]);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/procurement-stores/stock-counts/{$id}/approve", [])->assertStatus(422);

        $this->assertSame(10.0, $this->stockOnHand($material), 'the count did not change the shelf without its entry');
        $this->assertSame($inventory, $this->balance($this->inventory));
        $this->assertSame(0, $this->unbalancedJournals());
    }

    /* ── K. Migration rollback and legacy bills ───────────────────────────── */

    public function test_k_a_bill_with_no_lines_is_still_read_and_verified_and_gets_its_lines_then(): void
    {
        $item = $this->order(100, 1000, $this->material('MDF 18mm')->id);
        $this->receive($item, 100);

        // The older request shape: one amount, no lines.
        $bill = $this->bill($item, 60000, 9600);
        $this->assertSame(0, $bill->lines()->count());
        $this->getJson("/api/procurement-stores/bills/{$bill->id}")->assertOk();
        $this->getJson("/api/procurement-stores/bills/{$bill->id}/matching")->assertOk()->assertJsonCount(0, 'data.lines');
        $this->verify($bill)->assertOk();

        $line = BillLine::where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame([BillLine::BASIS_DERIVED, 60.0, '60000.00', '0.00'],
            [$line->allocation_basis, (float) $line->quantity, (string) $line->receipt_value_covered, (string) $line->cost_difference]);
        $this->assertSame('-40000.00', $this->balance($this->accrued));
        $this->assertTrue(app(PurchaseOrderWorkflow::class)->bill($bill->fresh())['verified'], 'still verified after its lines were derived');

        // A bill verified before lines existed is read as it was recorded.
        $legacy = Bill::create(['bill_number' => 'BILL-LEGACY-1', 'purchase_order_id' => $item->purchase_order_id, 'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 5000, 'status' => 'pending',
            'user_id' => $this->user->id, 'supplier_invoice_number' => 'OLD-1']);
        $legacy->forceFill(['verified_at' => now(), 'verified_by' => $this->user->id, 'verification_basis' => 'legacy'])->save();
        $this->getJson("/api/procurement-stores/bills/{$legacy->id}")->assertOk();
        $this->assertTrue(app(PurchaseOrderWorkflow::class)->bill($legacy->fresh())['verified']);
        $this->assertSame('5000.00', $legacy->fresh()->receiptValueCovered());
    }
}
