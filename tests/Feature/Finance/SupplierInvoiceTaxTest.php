<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Input VAT on the procurement rail.
 *
 * `bills` carried a single `amount`, the goods-receipt accrual passed no tax and
 * the stores issue passed no tax, so every purchase made through procurement
 * reached the ledger VAT-free. WNG is VAT-registered and files to KRA: that is
 * unclaimed input tax on every supplier invoice, not a reporting gap.
 *
 * The awkward part is that VAT lands in the middle of two things that already
 * worked — the three-way match and the payment balance — so most of these pin
 * that it did not disturb either.
 */
class SupplierInvoiceTaxTest extends TestCase
{
    use RefreshDatabase;

    private const ACCRUED = '2150';
    private const PAYABLE = '2100';
    private const VAT_INPUT = '1330';
    private const WHT_PAYABLE = '2120';

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceTaxSeeder::class);

        Role::findOrCreate('Accounts', 'web');
        $this->accounts = User::factory()->create(['is_active' => true]);
        $this->accounts->assignRole('Accounts');
        Sanctum::actingAs($this->accounts);

        /*
         * The standard-rated treatment is assigned to the supplier, not assumed
         * by the pricer. Nothing infers a VAT rate from an amount: a supplier
         * Finance has not classified is priced at no VAT, which is the honest
         * answer and the reason this fixture has to state it.
         */
        $standardRated = VatTreatment::where('code', 'STD16-REC')->firstOrFail();

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'C',
            'phone' => '0700000002', 'email' => uniqid() . '@t.local', 'address' => 'Industrial Area',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->accounts->id,
            'kra_pin' => 'P051234567M', 'vat_status' => 'registered', 'residency' => 'resident',
            'default_vat_treatment_id' => $standardRated->id,
        ]);

        // 100 sheets at 500 = 50,000 VAT-exclusive, which is how an order is priced.
        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Store', 'description' => 'Board stock',
            'total_amount' => 50000, 'status' => 'approved', 'user_id' => $this->accounts->id,
            'approved_at' => now(), 'approved_by' => $this->accounts->id,
        ]);

        $this->orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id, 'custom_description' => 'MDF 18mm',
            'quantity' => 100, 'unit_price' => 500, 'total' => 50000,
        ]);

        $this->deliverAndConfirm();
    }

    private function deliverAndConfirm(): void
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-' . uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id, 'batch_number' => 'B-' . uniqid(),
            'store_location' => 'Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->accounts->id,
        ]);

        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id,
            'purchase_order_item_id' => $this->orderItem->id,
            'ordered_quantity' => 100, 'received_quantity' => 100,
            'condition' => 'good', 'accepted' => true,
            'store_status' => 'confirmed', 'stock_status' => 'posted',
            'unit_price' => 500,
        ]);
    }

    /** Record an invoice through the endpoint, so it is priced the way production prices it. */
    private function recordBill(array $overrides = [])
    {
        return $this->postJson('/api/procurement-stores/bills', array_merge([
            'purchase_order_id' => $this->order->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 58000,               // 50,000 net + 16% VAT
            'supplier_invoice_number' => 'SINV-' . uniqid(),
        ], $overrides));
    }

    private function movementOn(string $code): string
    {
        $accountId = ChartOfAccount::where('code', $code)->value('id');
        $debits = (string) JournalLine::where('account_id', $accountId)->where('entry_type', 'debit')->sum('amount');
        $credits = (string) JournalLine::where('account_id', $accountId)->where('entry_type', 'credit')->sum('amount');

        return bcsub($debits ?: '0', $credits ?: '0', 2);
    }

    private function verify(Bill $bill)
    {
        return $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify");
    }

    public function test_a_vat_bearing_invoice_is_split_out_of_its_gross(): void
    {
        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));

        // Extracted from the gross, not added to it: 58,000 is 50,000 + 8,000.
        $this->assertSame('50000.00', (string) $bill->net_amount);
        $this->assertSame('8000.00', (string) $bill->vat_amount);
        $this->assertNotNull($bill->vat_treatment_id);
        $this->assertSame('P051234567M', $bill->supplier_pin);
    }

    /**
     * The match compares net against what Stores accepted at order prices. On
     * the gross it would fail by exactly the VAT — reporting that an invoice
     * exceeded the delivery when it agreed with it precisely.
     */
    public function test_the_three_way_match_is_made_on_the_net_value(): void
    {
        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));

        $state = app(PurchaseOrderWorkflow::class)->bill($bill->fresh());

        $this->assertSame('50000.00', $state['bill_amount']);
        $this->assertSame('58000.00', $state['bill_gross']);
        $this->assertTrue($state['eligible_for_verification'], implode(' ', $state['blockers']));
    }

    public function test_verifying_posts_the_input_vat_to_its_own_account(): void
    {
        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));

        $this->verify($bill)->assertOk();

        $this->assertSame('50000.00', $this->movementOn(self::ACCRUED));    // net clears the accrual
        $this->assertSame('8000.00', $this->movementOn(self::VAT_INPUT));   // VAT is claimable
        $this->assertSame('-58000.00', $this->movementOn(self::PAYABLE));   // the supplier is owed the gross
    }

    /** Exempt and out-of-scope tax is part of the cost, not a claim. */
    public function test_non_recoverable_vat_is_not_claimed(): void
    {
        $treatment = VatTreatment::create([
            'code' => 'NR-TEST', 'name' => 'Non-recoverable', 'rate_percent' => 16,
            'is_recoverable' => false, 'requires_etims' => false,
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);
        $this->supplier->update(['default_vat_treatment_id' => $treatment->id]);

        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();

        $this->assertSame('0.00', $this->movementOn(self::VAT_INPUT));
        // The whole invoice clears the accrual, because all of it is cost.
        $this->assertSame('58000.00', $this->movementOn(self::ACCRUED));
    }

    public function test_withholding_is_retained_and_owed_to_kra(): void
    {
        $category = WhtCategory::where('residency', 'resident')->firstOrFail();
        $this->supplier->update(['wht_category_id' => $category->id]);

        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $rate = (float) $category->rate_percent;
        $expectedWht = number_format(50000 * $rate / 100, 2, '.', '');

        $this->assertSame($expectedWht, (string) $bill->wht_amount);

        $this->verify($bill->fresh())->assertOk();

        // Withheld on the fee, never on the VAT.
        $this->assertSame('-' . $expectedWht, $this->movementOn(self::WHT_PAYABLE));
        // The supplier is owed the gross less what was retained.
        $this->assertSame('-' . number_format(58000 - (float) $expectedWht, 2, '.', ''),
            $this->movementOn(self::PAYABLE));
    }

    /** Withholding is owed to KRA, so it is never part of what a supplier can be paid. */
    public function test_withholding_reduces_what_the_supplier_can_be_paid(): void
    {
        $category = WhtCategory::where('residency', 'resident')->firstOrFail();
        $this->supplier->update(['wht_category_id' => $category->id]);

        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'))->fresh();
        $expectedPayable = number_format(58000 - (float) $bill->wht_amount, 2, '.', '');

        $this->assertSame($expectedPayable, $bill->payableAmount());
        $this->assertSame($expectedPayable, (string) $bill->balance);
    }

    public function test_a_stated_vat_beats_the_derived_one(): void
    {
        // A rounded figure on the document is the claimable one.
        $bill = Bill::findOrFail($this->recordBill(['vat_amount' => 7999.50])->assertSuccessful()->json('data.id'));

        $this->assertSame('7999.50', (string) $bill->vat_amount);
        $this->assertSame('50000.50', (string) $bill->net_amount);
    }

    public function test_an_unregistered_supplier_is_charged_no_vat(): void
    {
        $this->supplier->update(['vat_status' => 'not_registered']);

        $bill = Bill::findOrFail($this->recordBill(['amount' => 50000])->assertSuccessful()->json('data.id'));

        $this->assertSame('0.00', (string) $bill->vat_amount);
        $this->assertSame('50000.00', (string) $bill->net_amount);
    }

    /**
     * Re-pricing the tax after sign-off changes what leaves the bank, so it must
     * withdraw the verification exactly as re-pricing the invoice does.
     */
    public function test_changing_the_tax_after_verification_withdraws_it(): void
    {
        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();
        $this->assertTrue(app(PurchaseOrderWorkflow::class)->bill($bill->fresh())['verified']);

        $bill->fresh()->forceFill(['vat_amount' => 4000])->save();

        $this->assertFalse(app(PurchaseOrderWorkflow::class)->bill($bill->fresh())['verified']);
    }

    /** Every entry the rail writes still balances once tax is in play. */
    public function test_the_entry_balances_with_vat_and_withholding(): void
    {
        $category = WhtCategory::where('residency', 'resident')->firstOrFail();
        $this->supplier->update(['wht_category_id' => $category->id]);

        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();

        $entry = \App\Modules\Finance\Models\JournalEntry::with('lines')
            ->where('source_type', Bill::class)->where('source_id', $bill->id)->firstOrFail();

        $debits = $entry->lines->where('entry_type', 'debit')->sum('amount');
        $credits = $entry->lines->where('entry_type', 'credit')->sum('amount');

        $this->assertEquals($debits, $credits);
        $this->assertEquals($entry->total_debit, $debits);
        $this->assertSame(4, $entry->lines->count());
    }

    /** Paying the withheld invoice clears the payable exactly. */
    public function test_paying_the_net_of_withholding_settles_the_payable(): void
    {
        $category = WhtCategory::where('residency', 'resident')->firstOrFail();
        $this->supplier->update(['wht_category_id' => $category->id]);

        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();
        $bill->refresh();

        $source = PaymentSource::where('code', 'BANK-MAIN')->firstOrFail();
        $method = PaymentMethod::updateOrCreate(
            ['method_name' => 'Bank Transfer'],
            ['payment_source_id' => $source->id, 'is_active' => true],
        );

        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => $bill->payableAmount(),
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $method->id,
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-VAT-1',
            'user_id' => $this->accounts->id,
        ]);

        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('paid', $bill->fresh()->status);
    }

    /**
     * The claim has to reach the working paper, not just the ledger.
     *
     * The VAT schedule read `cost_lines` only, and the procurement rail has no
     * priced cost line — the accrual carries no tax. So input VAT recognised at
     * 1330 was still absent from the return that gets filed, which is the half
     * of the job that actually recovers the money.
     */
    public function test_a_verified_invoice_reaches_the_vat_return(): void
    {
        $bill = Bill::findOrFail($this->recordBill(['etims_invoice_no' => 'ETIMS-0001'])
            ->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();

        $schedule = app(\App\Modules\Finance\Services\TaxScheduleService::class)
            ->vatInputSchedule(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        $row = collect($schedule['rows'])->firstWhere('ref', $bill->bill_number);

        $this->assertNotNull($row, 'The supplier invoice must appear on the VAT input schedule.');
        $this->assertSame('supplier_invoice', $row['source']);
        $this->assertNull($row['cost_line_id']);
        $this->assertSame('8000.00', $row['vat_amount']);
        $this->assertTrue($row['is_supported']);
        $this->assertSame('8000.00', $schedule['totals']['claimable_vat']);
    }

    /** Without an eTIMS number the claim is not defensible, and must say so. */
    public function test_an_invoice_with_no_etims_number_is_flagged_as_at_risk(): void
    {
        $bill = Bill::findOrFail($this->recordBill()->assertSuccessful()->json('data.id'));
        $this->verify($bill->fresh())->assertOk();

        $gap = app(\App\Modules\Finance\Services\TaxScheduleService::class)->etimsGap();
        $row = collect($gap['rows'])->firstWhere('ref', $bill->bill_number);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_supported']);
        $this->assertContains('etims_invoice_no', $row['missing']);
    }

    /** An unverified invoice has posted nothing, so it has raised no claim. */
    public function test_an_unverified_invoice_is_not_claimed(): void
    {
        $bill = Bill::findOrFail($this->recordBill(['etims_invoice_no' => 'ETIMS-0002'])
            ->assertSuccessful()->json('data.id'));

        $schedule = app(\App\Modules\Finance\Services\TaxScheduleService::class)
            ->vatInputSchedule(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        $this->assertNull(collect($schedule['rows'])->firstWhere('ref', $bill->bill_number));
    }

    /** Invoices predating tax capture must post and match exactly as before. */
    public function test_an_invoice_with_no_vat_behaves_as_it_always_did(): void
    {
        $bill = Bill::findOrFail($this->recordBill(['amount' => 50000, 'vat_amount' => 0])
            ->assertSuccessful()->json('data.id'));

        $this->verify($bill->fresh())->assertOk();

        $this->assertSame('50000.00', (string) $bill->fresh()->net_amount);
        $this->assertSame('50000.00', $this->movementOn(self::ACCRUED));
        $this->assertSame('-50000.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('0.00', $this->movementOn(self::VAT_INPUT));
    }
}
