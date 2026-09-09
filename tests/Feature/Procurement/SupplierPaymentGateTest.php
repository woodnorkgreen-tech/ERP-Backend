<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\HR\Models\Department;
use Illuminate\Support\Str;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Approving a purchase order authorises a purchase; it does not mean anything
 * arrived or that anyone owes the supplier yet. These tests pin the controls
 * that sit between approval and money leaving: the stage each order is at, and
 * the three-way match a supplier invoice must pass before it can be paid.
 */
class SupplierPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Verification is a ledger event, so this fixture needs a ledger.
         *
         * These tests used to pass without a chart because verifying an invoice
         * touched no accounts at all — which was the defect, not the setup. Now
         * that the sign-off moves the accrual onto Accounts Payable, a chart,
         * an open period and payment sources carrying GL accounts are part of
         * what the workflow assumes, exactly as the goods-receipt accrual
         * already assumed them.
         */
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);

        /*
         * Accounts pays suppliers, so the fixture's role carries the permission
         * the live one does. Settling an invoice on the spot is now gated on
         * being allowed to move money out of an account at all — the same right
         * a petty cash disbursement needs, because since the payment
         * architecture was unified there is one payments table behind both.
         */
        $accountsRole = Role::findOrCreate('Accounts', 'web');
        $accountsRole->givePermissionTo(
            Permission::findOrCreate('finance.petty_cash.create_disbursement', 'web'),
        );

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

        $this->orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id,
            'custom_description' => 'MDF 18mm sheet',
            'quantity' => 10,
            'unit_price' => 5000,
            'total' => 50000,
        ]);
    }

    /** Record a delivery. Stores confirmation is a separate, deliberate step. */
    private function deliver(float $received, bool $confirmed): GoodsReceiptNoteItem
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id,
            'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store',
            'quality_check' => 'pass',
            'store_status' => $confirmed ? 'confirmed' : 'pending_confirmation',
            'received_by' => $this->accounts->id,
        ]);

        return GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id,
            'purchase_order_item_id' => $this->orderItem->id,
            'ordered_quantity' => 10,
            'received_quantity' => $received,
            'condition' => 'good',
            'accepted' => true,
            'store_status' => $confirmed ? 'confirmed' : 'pending',
            'stock_status' => $confirmed ? 'posted' : 'awaiting_stores_details',
            'unit_price' => 5000,
        ]);
    }

    private function bill(float $amount, ?string $invoiceNumber = 'SINV-1001'): Bill
    {
        return Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => $this->order->id,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $amount,
            'status' => 'pending',
            'supplier_invoice_number' => $invoiceNumber,
            'user_id' => $this->accounts->id,
        ]);
    }

    /**
     * The account a supplier payment leaves.
     *
     * This used to build a `payment_methods` row, which had to carry a payment
     * source or the payment was unpostable — the method row existed only to
     * reach the account behind it. The account is now named directly.
     */
    private function payingAccount(): PaymentSource
    {
        return PaymentSource::firstOrCreate(
            ['code' => 'BANK-MAIN'],
            ['name' => 'Equity Bank – Operating Account', 'type' => 'bank', 'currency' => 'KES', 'is_active' => true],
        );
    }

    public function test_an_approved_order_awaits_delivery_not_payment(): void
    {
        $state = app(PurchaseOrderWorkflow::class)->order($this->order);

        $this->assertSame('delivery', $state['stage']);
        $this->assertSame('Procurement', $state['owner']);
        $this->assertFalse($state['receipt_started']);
    }

    public function test_a_delivery_awaiting_stores_confirmation_is_not_yet_accepted(): void
    {
        $this->deliver(received: 10, confirmed: false);

        $state = app(PurchaseOrderWorkflow::class)->order($this->order->fresh());

        $this->assertSame('stores', $state['stage']);
        $this->assertSame('Stores', $state['owner']);
        $this->assertSame('10.000000', $state['items'][0]['received']);
        $this->assertSame('0.000000', $state['items'][0]['accepted']);
        $this->assertSame('0.00', $state['accepted_value']);
    }

    public function test_stores_confirmation_moves_the_order_to_invoicing(): void
    {
        $this->deliver(received: 10, confirmed: true);

        $state = app(PurchaseOrderWorkflow::class)->order($this->order->fresh());

        $this->assertSame('invoice', $state['stage']);
        $this->assertTrue($state['receipt_complete']);
        $this->assertSame('50000.00', $state['accepted_value']);
    }

    public function test_an_invoice_cannot_be_verified_before_stores_accepts_the_goods(): void
    {
        $this->deliver(received: 10, confirmed: false);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422)
            ->assertJsonPath('error', 'This invoice does not yet pass the three-way match.');

        $this->assertNull($bill->fresh()->verified_at);
    }

    public function test_an_unverified_invoice_cannot_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $response = $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-0001',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must verify', $response->json('error'));
        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
    }

    public function test_a_verified_invoice_can_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify", [
            'verification_notes' => 'Checked against GRN and order.',
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-0001',
        ])->assertSuccessful();

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('three_way_match', $bill->fresh()->verification_basis);
        $billPayment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $financePayment = Payment::findOrFail($billPayment->disbursement_id);
        $this->assertSame($billPayment->payment_code, $financePayment->payment_no);
        $this->assertSame('bank_transfer', $financePayment->payment_method);
        $this->assertSame($this->payingAccount()->id, $financePayment->payment_source_id);
        $this->assertSame('FT-0001', $financePayment->external_reference);
    }

    public function test_an_invoice_above_the_value_accepted_into_stock_is_refused(): void
    {
        // Half the order arrived; the supplier invoiced for all of it.
        $this->deliver(received: 5, confirmed: true);
        $bill = $this->bill(50000);

        $response = $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422);

        $this->assertContains(
            'Invoice does not exceed the value accepted into stock',
            $response->json('blockers')
        );
    }

    public function test_a_part_delivery_may_be_part_invoiced(): void
    {
        $this->deliver(received: 5, confirmed: true);
        $bill = $this->bill(25000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.receipt_complete', false);
    }

    public function test_repricing_an_invoice_withdraws_its_verification(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        // The supplier reissues at a lower figure. The sign-off was a statement
        // about the old one, so it must not carry over.
        $bill->update(['amount' => 40000]);

        $this->assertNull($bill->fresh()->verified_at);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 40000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-0002',
        ])->assertStatus(422);
    }

    public function test_a_later_change_to_the_order_withdraws_a_standing_verification(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        // Re-pricing the order changes what was checked without touching the
        // invoice row, so only the fingerprint can catch it.
        $this->orderItem->update(['unit_price' => 4000, 'total' => 40000]);
        $this->order->update(['total_amount' => 40000]);

        $this->getJson("/api/procurement-stores/bills/{$bill->id}/verification")
            ->assertOk()
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.can_pay', false);
    }

    public function test_no_payment_path_can_bypass_the_gate(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        // Writing the payment row directly is the path a new caller would take
        // by accident. The model itself refuses it.
        $this->expectException(\RuntimeException::class);

        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'DIRECT',
            'user_id' => $this->accounts->id,
        ]);
    }

    public function test_invoices_raised_before_the_control_existed_stay_payable(): void
    {
        $bill = $this->bill(50000, invoiceNumber: null);
        $bill->forceFill([
            'verified_at' => now()->subMonth(),
            'verification_basis' => 'legacy',
        ])->saveQuietly();

        $state = app(PurchaseOrderWorkflow::class)->bill($bill->fresh());

        $this->assertTrue($state['can_pay']);
        $this->assertSame('legacy', $state['verification_basis']);
        $this->assertSame([], $state['blockers']);
    }

    public function test_only_accounts_may_verify_an_invoice_for_payment(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $storeman = User::create([
            'name' => 'Storeman',
            'email' => uniqid('stores_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $storeman->assignRole(Role::findOrCreate('Stores', 'web'));
        Sanctum::actingAs($storeman);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertStatus(403);
        $this->assertNull($bill->fresh()->verified_at);
    }

    public function test_the_order_workflow_endpoint_names_the_owner_of_the_next_move(): void
    {
        $this->deliver(received: 10, confirmed: false);

        $this->getJson("/api/procurement-stores/purchase-orders/{$this->order->id}/workflow")
            ->assertOk()
            ->assertJsonPath('data.stage', 'stores')
            ->assertJsonPath('data.owner', 'Stores')
            ->assertJsonPath('data.receipt_complete', false);
    }

    public function test_the_payables_list_says_which_invoices_can_actually_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $blocked = $this->bill(50000);

        $this->getJson('/api/procurement-stores/pending-bills?supplier_id='.$this->supplier->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $blocked->id)
            ->assertJsonPath('data.0.can_pay', false);

        $this->postJson("/api/procurement-stores/bills/{$blocked->id}/verify")->assertOk();

        $this->getJson('/api/procurement-stores/pending-bills?supplier_id='.$this->supplier->id)
            ->assertOk()
            ->assertJsonPath('data.0.can_pay', true);
    }

    public function test_a_batch_payment_is_refused_whole_when_one_invoice_is_blocked(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $verified = $this->bill(30000);
        $this->postJson("/api/procurement-stores/bills/{$verified->id}/verify")->assertOk();

        // A second order and invoice for the same supplier, never verified.
        $secondOrder = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store',
            'total_amount' => 10000,
            'status' => 'approved',
            'user_id' => $this->accounts->id,
        ]);
        $unverified = Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => $secondOrder->id,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 10000,
            'status' => 'pending',
            'user_id' => $this->accounts->id,
        ]);

        $response = $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$verified->id, $unverified->id],
            'amount_paid' => 40000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-BATCH',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('blocked'));

        // Neither invoice moved: a batch settles whole or not at all.
        $this->assertSame(0, BillPayment::whereIn('bill_id', [$verified->id, $unverified->id])->count());
        $this->assertSame('30000.00', (string) $verified->fresh()->balance);
    }

    public function test_a_payment_larger_than_the_balance_is_refused(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 60000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->payingAccount()->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-0003',
        ])->assertStatus(422);
    }

    /*
     * ── Where the money came from ────────────────────────────────────────
     *
     * Petty cash is a payment source, not a kind of expense, and at WNG it is
     * the source most money leaves through — including money that settles
     * supplier invoices. That payment used to write a `bill_payments` row and
     * nothing else: the invoice showed as paid, the float did not move, and the
     * payment appeared nowhere in the petty cash transaction list, which reads
     * `petty_cash_ledger_entries` directly.
     */

    public function test_paying_an_invoice_from_petty_cash_takes_it_out_of_the_float(): void
    {
        $bill = $this->payableBill();
        $this->topUpFloat('80000');
        $opening = (string) PettyCashBalance::current()->current_balance;

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-0001',
        ])->assertSuccessful();

        $payment = BillPayment::where('bill_id', $bill->id)->sole();

        $this->assertNotNull(
            $payment->disbursement_id,
            'An invoice paid from the tin is a petty cash disbursement like any other.'
        );
        $this->assertSame(
            0,
            bccomp(bcsub($opening, '50000', 2), (string) PettyCashBalance::current()->current_balance, 2),
            'The float must fall by the amount paid.'
        );

        // The petty cash transaction list reads the ledger, so this is also the
        // assertion that the payment shows up where a custodian looks for it.
        $this->assertSame(1, DB::table('petty_cash_ledger_entries')
            ->where('source_type', 'disbursement')
            ->where('source_id', $payment->disbursement_id)
            ->where('type', 'debit')
            ->count(), 'One cash movement, one ledger entry — never two.');
    }

    public function test_paying_an_invoice_from_the_bank_leaves_the_float_alone(): void
    {
        $bill = $this->payableBill();
        $this->topUpFloat('80000');
        $opening = (string) PettyCashBalance::current()->current_balance;

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('BANK-MAIN')->id,
            'payment_method' => 'bank_transfer',
            'reference_number' => 'FT-9001',
        ])->assertSuccessful();

        $payment = BillPayment::where('bill_id', $bill->id)->sole();

        $this->assertNotNull($payment->disbursement_id, 'Every supplier settlement must have one common Finance payment.');
        $this->assertSame('BANK-MAIN', Payment::findOrFail($payment->disbursement_id)->paymentSource->code);
        $this->assertSame(
            $this->source('BANK-MAIN')->id,
            $payment->payment_source_id,
            'The payment records where it was actually paid from, not where its method usually draws.'
        );
        $this->assertSame(
            0,
            bccomp($opening, (string) PettyCashBalance::current()->current_balance, 2),
            'Money that never came out of the tin must not be deducted from it.'
        );
        $this->assertSame(0, DB::table('petty_cash_ledger_entries')
            ->where('source_type', 'disbursement')
            ->where('source_id', $payment->disbursement_id)
            ->count(), 'A bank payment is visible in Finance without becoming a petty-cash movement.');
    }

    public function test_a_payment_the_float_cannot_cover_is_refused(): void
    {
        $bill = $this->payableBill();
        $this->topUpFloat('10000');
        $opening = (string) PettyCashBalance::current()->current_balance;

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-0002',
        ])->assertStatus(422);

        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count(),
            'A refused payment must not record the invoice as paid.');
        $this->assertSame(
            0,
            bccomp($opening, (string) PettyCashBalance::current()->current_balance, 2),
            'and must leave the float untouched.'
        );
    }

    /** A delivered, verified invoice the gate will let through. */
    private function payableBill(): Bill
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        return $bill->fresh();
    }

    private function source(string $code): PaymentSource
    {
        return PaymentSource::firstOrCreate(
            ['code' => $code],
            [
                'name' => $code === 'PC-MAIN' ? 'Main Petty Cash Float' : 'Bank – Main Account',
                'type' => $code === 'PC-MAIN' ? 'petty_cash' : 'bank',
                'currency' => 'KES',
                'is_active' => true,
            ],
        );
    }

    /**
     * A real top-up, not a hand-written balance row.
     *
     * The payment path allocates the disbursement against top-ups, so a float
     * conjured straight into `petty_cash_balances` would leave it nothing to
     * draw on and the allocation would fail for a reason unrelated to the test.
     */
    private function topUpFloat(string $amount): void
    {
        $topUp = PettyCashTopUp::create([
            'amount' => $amount,
            'date_topped_up' => now()->toDateString(),
            'description' => 'Opening float for test',
            'payment_method' => 'cash',
            'created_by' => $this->accounts->id,
        ]);

        app(LedgerService::class)->post(LedgerEntry::creditForTopUp($topUp));
    }

    public function test_a_purchase_requisition_says_which_float_settled_it(): void
    {
        $requisition = Requisition::create([
            'requisition_number' => 'PR-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'urgency' => 'normal',
            'total_amount' => 50000,
            'status' => 'approved',
            'user_id' => $this->accounts->id,
        ]);
        $this->order->update(['requisition_id' => $requisition->id]);

        $bill = $this->payableBill();
        $this->topUpFloat('80000');

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-0003',
        ])->assertSuccessful();

        // Four hops from the request to its money — requisition, order, invoice,
        // payment — which is why nothing ever showed it.
        $settlements = $requisition->fresh()->settlements();

        $this->assertCount(1, $settlements);
        $this->assertSame('petty_cash', $settlements->first()['type']);
        $this->assertSame('50000.00', $settlements->first()['amount']);
        $this->assertNotNull(
            $settlements->first()['disbursement_id'],
            'A request settled from the tin names the cash record that paid it.'
        );
    }

    /*
     * ── Drawing cash to pay an invoice ───────────────────────────────────
     *
     * The other direction: instead of recording a payment on the invoice, raise
     * a fund requisition against it, collect the cash, and let paying it out
     * settle the invoice. Every piece of this existed and had no way in — the
     * form reads `bill_id` off the query, PettyCashService runs the same
     * three-way match before the cash moves, and the disbursement creates the
     * BillPayment — but no screen linked to it, so the path had never run.
     */

    public function test_cash_drawn_against_an_invoice_settles_it(): void
    {
        $bill = $this->payableBill();
        $this->topUpFloat('80000');
        $opening = (string) PettyCashBalance::current()->current_balance;

        $requisition = $this->fundRequisitionFor($bill, '50000');

        $result = app(PettyCashService::class)->createDisbursement($this->payout($requisition, '50000'));

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $payment = BillPayment::where('bill_id', $bill->id)->sole();

        $this->assertSame(
            $result['data']->id,
            $payment->disbursement_id,
            'The invoice is settled by the very disbursement that paid the cash out.'
        );
        $this->assertSame('0.00', (string) $bill->fresh()->balance);

        // Debited once. The disbursement posts the cash entry; the BillPayment it
        // creates must not post a second one.
        $this->assertSame(
            0,
            bccomp(bcsub($opening, '50000', 2), (string) PettyCashBalance::current()->current_balance, 2),
            'The float falls by the amount once, not twice.'
        );
        $this->assertSame(1, DB::table('petty_cash_ledger_entries')
            ->where('source_type', 'disbursement')
            ->where('source_id', $result['data']->id)
            ->where('type', 'debit')
            ->count());
    }

    public function test_cash_cannot_be_drawn_against_an_unverified_invoice(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);          // deliberately not verified
        $this->topUpFloat('80000');
        $opening = (string) PettyCashBalance::current()->current_balance;

        $requisition = $this->fundRequisitionFor($bill, '50000');

        $result = app(PettyCashService::class)->createDisbursement($this->payout($requisition, '50000'));

        $this->assertFalse($result['success'], 'A disbursement is a supplier payment and answers to the same match.');
        $this->assertArrayHasKey('bill_id', $result['errors']);
        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
        $this->assertSame(
            0,
            bccomp($opening, (string) PettyCashBalance::current()->current_balance, 2),
            'Refused before the cash moves, not after.'
        );
    }

    /**
     * A fund requisition raised against an invoice.
     *
     * Raised by somebody other than the payer: paying out your own request is
     * refused, which is the separation of duties and not something this test
     * should be quietly working around.
     */
    private function fundRequisitionFor(Bill $bill, string $amount): PettyCashRequisition
    {
        $requester = User::create([
            'name' => 'Site Supervisor',
            'email' => uniqid('requester_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        return PettyCashRequisition::create([
            'requisition_number' => 'FR-'.uniqid(),
            'user_id' => $requester->id,
            'department_id' => Department::firstOrCreate(['name' => 'Procurement'])->id,
            'bill_id' => $bill->id,
            'category' => 'Supplier invoice',
            'purpose' => "Settle invoice {$bill->bill_number}",
            'total_amount' => $amount,
            'status' => 'approved',
            'requester_name' => 'Site Supervisor',
        ]);
    }

    /** @return array<string, mixed> */
    private function payout(PettyCashRequisition $requisition, string $amount): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'requisition_id' => $requisition->id,
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'expense_code_id' => $this->cashExpenseCode()->id,
            'payee_name' => 'Timber & Board Ltd',
            'account' => 'Supplier invoice payment',
            'classification' => 'operations',
            'amount' => $amount,
            'transaction_cost' => 0,
            'description' => $requisition->purpose,
            'date_disbursed' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'active',
            'created_by' => $this->accounts->id,
        ];
    }

    private function cashExpenseCode(): ExpenseCode
    {
        return ExpenseCode::firstOrCreate(
            ['code' => 'TST-SUP-001'],
            [
                'accounting_class' => 'Direct project cost',
                'expense_family' => 'Direct materials',
                'expense_type' => 'Boards and panels',
                'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
                'cash_flow_class' => 'operating',
                'is_active' => true,
            ],
        );
    }

    // ── What it costs to move the money ──────────────────────────────────────
    //
    // A bank or M-Pesa charge is a cost of running WNG's accounts, not of the
    // thing being bought. These pin where it lands and what it must never
    // touch: the supplier's balance, and any project.

    public function test_a_transaction_fee_leaves_the_float_without_crediting_the_supplier(): void
    {
        $this->topUpFloat('100000.00');
        $bill = $this->payableBill();

        $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$bill->id],
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-FEE-1',
            'transaction_cost' => 150,
        ])->assertSuccessful();

        $payment = Payment::where('payee_type', 'supplier')->latest('id')->first();
        $this->assertSame('50000.00', $payment->amount);
        $this->assertSame('150.00', $payment->transaction_cost);

        // The supplier is credited the invoice amount. The fee was ours.
        $this->assertSame('0.00', (string) $bill->fresh()->balance);
        $this->assertSame('50000.00', (string) BillPayment::where('bill_id', $bill->id)->sum('amount_paid'));

        // But the tin gave up both.
        $this->assertSame(
            '49850.00',
            number_format((float) PettyCashBalance::current()->current_balance, 2, '.', ''),
            'The float must be reduced by the payment and its fee.',
        );
    }

    public function test_a_transaction_fee_posts_to_bank_charges_and_never_to_a_project(): void
    {
        $this->topUpFloat('100000.00');
        $bill = $this->payableBill();

        $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$bill->id],
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-FEE-2',
            'transaction_cost' => 150,
        ])->assertSuccessful();

        $payment = Payment::where('payee_type', 'supplier')->latest('id')->first();
        app(\App\Modules\Finance\CostCollector\Services\PettyCashCostProducer::class)->postFor($payment);

        $entry = \App\Modules\Finance\Models\JournalEntry::where(
            'entry_no', 'JE-PFEE-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT),
        )->with('lines')->first();

        $this->assertNotNull($entry, 'A payment carrying a fee must post a fee journal.');
        $this->assertSame('150.00', (string) $entry->total_debit);

        $debit = $entry->lines->firstWhere('entry_type', 'debit');
        $this->assertSame(
            '7800',
            \App\Modules\Finance\Models\ChartOfAccount::find($debit->account_id)->code,
            'A transfer charge is an overhead, so it debits bank charges.',
        );

        foreach ($entry->lines as $line) {
            $this->assertNull($line->project_id, 'A transaction fee must not be charged to a project.');
            $this->assertNull($line->project_enquiry_id, 'A transaction fee must not be charged to a job.');
        }
    }

    public function test_a_fund_request_fee_is_an_overhead_not_a_job_cost(): void
    {
        $this->topUpFloat('100000.00');
        $bill = $this->payableBill();
        $requisition = $this->fundRequisitionFor($bill, '50000.00');

        $payout = $this->payout($requisition, '50000.00');
        $payout['transaction_cost'] = 200;
        $payout['job_number'] = 'JOB-FEE-1';

        $result = app(PettyCashService::class)->createDisbursement($payout);
        $this->assertTrue($result['success'], 'The payout should succeed.');

        $payment = $result['data'];
        app(\App\Modules\Finance\CostCollector\Services\PettyCashCostProducer::class)->postFor($payment);

        // The fee reaches the ledger even though the payment itself is a
        // supplier settlement the producer otherwise skips entirely.
        $entry = \App\Modules\Finance\Models\JournalEntry::where(
            'entry_no', 'JE-PFEE-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT),
        )->first();
        $this->assertNotNull($entry, 'A fund-request fee must post even on a supplier settlement.');
        $this->assertSame('200.00', (string) $entry->total_debit);

        // And it is not on the job. It used to be, under OE-FIN-001.
        $this->assertSame(
            0,
            \App\Modules\Finance\CostCollector\Models\CostLine::where('source_type', Payment::class)
                ->where('source_id', $payment->id)
                ->where('source_ref', 'transaction-fee')
                ->count(),
            'The fee must no longer be posted as a project cost line.',
        );
    }

    public function test_one_transfer_settling_several_invoices_is_one_payment_with_one_fee(): void
    {
        $this->topUpFloat('200000.00');

        // Two invoices against one delivery, splitting its accepted value —
        // rather than two deliveries, which would move the order underneath the
        // first invoice and withdraw its verification.
        $this->deliver(received: 10, confirmed: true);
        $first = $this->bill(25000, invoiceNumber: 'SINV-2001');
        $second = $this->bill(25000, invoiceNumber: 'SINV-2002');

        foreach ([$first, $second] as $invoice) {
            $this->postJson("/api/procurement-stores/bills/{$invoice->id}/verify")->assertOk();
        }

        $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$first->id, $second->id],
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-BATCH-1',
            'transaction_cost' => 300,
        ])->assertSuccessful();

        $payments = Payment::where('payee_type', 'supplier')->get();
        $this->assertCount(1, $payments, 'One movement of money is one payment document.');
        $this->assertSame('300.00', $payments->first()->transaction_cost, 'The fee is charged once, not per invoice.');

        $allocations = BillPayment::whereIn('bill_id', [$first->id, $second->id])->get();
        $this->assertCount(2, $allocations);
        $this->assertSame(
            [$payments->first()->id, $payments->first()->id],
            $allocations->pluck('disbursement_id')->all(),
            'Both invoice allocations hang off the one payment.',
        );
        $this->assertSame(50000.0, (float) $allocations->sum('amount_paid'));
    }

    public function test_a_legacy_invoice_cannot_be_settled_on_the_spot(): void
    {
        $this->topUpFloat('100000.00');
        $bill = $this->bill(50000, invoiceNumber: null);
        $bill->forceFill([
            'verified_at' => now()->subMonth(),
            'verification_basis' => 'legacy',
        ])->saveQuietly();

        $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$bill->id],
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-LEGACY-1',
        ])->assertStatus(403);

        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
    }

    public function test_paying_a_supplier_needs_the_right_to_move_money(): void
    {
        $this->topUpFloat('100000.00');
        $bill = $this->payableBill();

        $clerk = User::create([
            'name' => 'Procurement Clerk',
            'email' => uniqid('clerk_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $clerk->assignRole(Role::findOrCreate('Procurement', 'web'));
        Sanctum::actingAs($clerk);

        $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$bill->id],
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_source_id' => $this->source('PC-MAIN')->id,
            'payment_method' => 'cash',
            'reference_number' => 'PC-NOPERM-1',
        ])->assertStatus(403);

        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
    }
}
