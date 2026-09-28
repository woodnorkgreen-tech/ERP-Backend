<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Services\ClientFinancialPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 2B Wave 1, Section 20: post-implementation data-integrity checks.
 *
 * Each invariant here is cross-checked two ways — once by reading the
 * authoritative service response, once by independently re-deriving the
 * figure straight from the underlying ledger rows — so this catches a future
 * drift between the two, not just a restatement of the formula the service
 * already applies to itself.
 *
 *   1. Invoice Total = Gross − Discount + Tax
 *   2. Cash Received = Allocated Amount + Unallocated Client Credit
 *   3. Invoice Outstanding = Issued Invoice Amount − Valid Allocations/Credits
 */
class DataIntegrityInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $checker;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        $this->checker = User::factory()->create(['is_active' => true]);
        $this->checker->givePermissionTo(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK);

        // Separate from the accountant: FinanceService::verifyPayment() refuses
        // to let whoever recorded a receipt also verify it (pre-existing
        // separation of duties, unrelated to this test), so verification and
        // allocation need their own authorized person.
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_RECORD,
        ]);
    }

    public function test_invoice_total_equals_gross_minus_discount_plus_tax(): void
    {
        $client = Client::factory()->create(['email' => 'dii-' . uniqid() . '@test.local']);
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id, 'title' => 'Integrity check', 'description' => 'x',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM, 'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane', 'enquiry_number' => 'ENQ-DII-' . uniqid(),
            'created_by' => $this->accountant->id, 'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);
        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved',
            'approved_by' => $this->accountant->id, 'approval_date' => '2026-09-01',
            'quote_amount' => 1200000, 'quote_data' => json_encode(['grandTotal' => 1200000]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [[
                    'description' => 'Stand build', 'quantity' => 1,
                    'unit_price' => 1000000, 'discount_amount' => 100000,
                    'vat_treatment_id' => $vat->id,
                ]],
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));
        $line = $invoice->lines()->firstOrFail();

        // Independently re-derive from the persisted ledger rows — not from
        // re-running the pricer — so this would catch drift between what was
        // stored and what the header total actually reflects.
        $expectedTotal = bcadd(
            bcsub((string) $line->gross_amount, (string) $line->discount_amount, 2),
            (string) $line->tax_amount,
            2,
        );

        $this->assertSame('900000.00', bcsub((string) $line->gross_amount, (string) $line->discount_amount, 2));
        $this->assertEquals((float) $expectedTotal, (float) $invoice->total_amount);
        $this->assertEquals((float) $expectedTotal, (float) $line->total_amount);

        // Header aggregates must equal the sum of the (already-verified) lines.
        $this->assertEquals((float) $line->net_amount, (float) $invoice->subtotal);
        $this->assertEquals((float) $line->tax_amount, (float) $invoice->tax_amount);
    }

    public function test_cash_received_equals_allocated_plus_unallocated_client_credit(): void
    {
        $client = Client::factory()->create(['email' => 'dii-' . uniqid() . '@test.local']);
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id, 'title' => 'Integrity check', 'description' => 'x',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM, 'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane', 'enquiry_number' => 'ENQ-DII-' . uniqid(),
            'created_by' => $this->accountant->id, 'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);
        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved',
            'approved_by' => $this->accountant->id, 'approval_date' => '2026-09-01',
            'quote_amount' => 1000000, 'quote_data' => json_encode(['grandTotal' => 1000000]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();
        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => 500000, 'vat_treatment_id' => $vat->id]],
            ])->assertCreated();
        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));
        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")->assertOk();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")->assertOk();

        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();

        // Two receipts: one fully allocated, one left partly unallocated.
        foreach ([['DII-CASH-1', 400000], ['DII-CASH-2', 250000]] as [$ref, $amount]) {
            $this->actingAs($this->accountant, 'sanctum')
                ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                    'amount' => $amount, 'received_amount' => $amount,
                    'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer',
                    'payment_source_id' => $source->id, 'transaction_reference' => $ref,
                ])->assertOk();

            $payment = EnquiryPayment::where('transaction_reference', $ref)->firstOrFail();
            $this->actingAs($this->verifier, 'sanctum')
                ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")->assertOk();
        }

        $payment1 = EnquiryPayment::where('transaction_reference', 'DII-CASH-1')->firstOrFail();
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment1->id, 'amount' => 400000,
            ])->assertOk();
        // Second receipt (250000) deliberately left unallocated.

        $data = app(ClientFinancialPositionService::class)->forEnquiry($enquiry->fresh());

        // Independent re-derivation straight from the ledger tables.
        $cashReceivedRaw = (float) EnquiryPayment::where('project_enquiry_id', $enquiry->id)
            ->where('status', 'verified')->whereNull('reversed_at')->sum('amount');
        $allocatedRaw = (float) DB::table('project_invoice_allocations as a')
            ->join('project_invoices as pi', 'pi.id', '=', 'a.project_invoice_id')
            ->where('pi.project_enquiry_id', $enquiry->id)->sum('a.amount');
        $unallocatedRaw = $cashReceivedRaw - $allocatedRaw;

        $this->assertEquals(650000.0, $cashReceivedRaw);
        $this->assertEquals(400000.0, $allocatedRaw);
        $this->assertEquals(250000.0, $unallocatedRaw);

        $this->assertEquals($cashReceivedRaw, $data['cash_received']);
        $this->assertEquals($allocatedRaw, $data['amount_allocated']);
        $this->assertEquals($unallocatedRaw, $data['unallocated_client_credit']);
        $this->assertEquals($data['amount_allocated'] + $data['unallocated_client_credit'], $data['cash_received']);
    }

    public function test_invoice_outstanding_equals_issued_invoice_amount_minus_valid_allocations(): void
    {
        $client = Client::factory()->create(['email' => 'dii-' . uniqid() . '@test.local']);
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id, 'title' => 'Integrity check', 'description' => 'x',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM, 'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane', 'enquiry_number' => 'ENQ-DII-' . uniqid(),
            'created_by' => $this->accountant->id, 'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);
        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved',
            'approved_by' => $this->accountant->id, 'approval_date' => '2026-09-01',
            'quote_amount' => 2000000, 'quote_data' => json_encode(['grandTotal' => 2000000]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();
        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => 1000000, 'vat_treatment_id' => $vat->id]],
            ])->assertCreated();
        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));
        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")->assertOk();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")->assertOk();
        $invoice->refresh();

        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                'amount' => 300000, 'received_amount' => 300000,
                'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer',
                'payment_source_id' => $source->id, 'transaction_reference' => 'DII-OUT-1',
            ])->assertOk();
        $payment = EnquiryPayment::where('transaction_reference', 'DII-OUT-1')->firstOrFail();
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")->assertOk();
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => 300000,
            ])->assertOk();

        $data = app(ClientFinancialPositionService::class)->forEnquiry($enquiry->fresh());

        $issuedInvoiceAmountRaw = (float) ProjectInvoice::where('project_enquiry_id', $enquiry->id)
            ->where('status', '!=', 'void')->sum('total_amount');
        $validAllocationsRaw = (float) DB::table('project_invoice_allocations as a')
            ->join('project_invoices as pi', 'pi.id', '=', 'a.project_invoice_id')
            ->where('pi.project_enquiry_id', $enquiry->id)->where('pi.status', '!=', 'void')
            ->sum('a.amount');
        $outstandingRaw = $issuedInvoiceAmountRaw - $validAllocationsRaw;

        $this->assertEquals(1160000.0, $issuedInvoiceAmountRaw);
        $this->assertEquals(300000.0, $validAllocationsRaw);
        $this->assertEquals(860000.0, $outstandingRaw);

        $this->assertEquals($issuedInvoiceAmountRaw, $data['amount_invoiced']);
        $this->assertEquals($validAllocationsRaw, $data['amount_allocated']);
        $this->assertEquals($outstandingRaw, $data['invoice_outstanding']);
    }
}
