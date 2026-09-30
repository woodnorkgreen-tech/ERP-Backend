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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * W1-3/W1-4 (explicit client/project financial position, replacing one
 * ambiguous "Client Outstanding" figure) and W1-5 (verification/allocation
 * carry distinct, separately-recorded actors and timestamps even when the
 * same person performs both) and W1-9 (unallocated client credit visibility
 * and age).
 */
class ClientFinancialPositionTest extends TestCase
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
            Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        $this->checker = User::factory()->create(['is_active' => true]);
        $this->checker->givePermissionTo(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK);

        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_RECORD,
        ]);
    }

    private function enquiry(float $quoteAmount): ProjectEnquiry
    {
        $client = Client::factory()->create(['email' => 'cfp-' . uniqid() . '@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Client financial position test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-CFP-' . uniqid(),
            'created_by' => $this->accountant->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved', 'approved_by' => $this->accountant->id,
            'approval_date' => '2026-09-01', 'quote_amount' => $quoteAmount,
            'quote_data' => json_encode(['grandTotal' => $quoteAmount]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $enquiry;
    }

    private function issuedInvoice(ProjectEnquiry $enquiry, float $unitPrice): ProjectInvoice
    {
        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $unitPrice, 'vat_treatment_id' => $vat->id]],
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")->assertOk();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")->assertOk();

        return $invoice->fresh();
    }

    private function recordAndVerifyPayment(ProjectEnquiry $enquiry, float $amount, string $ref, ?\Carbon\Carbon $paymentDate = null): EnquiryPayment
    {
        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                'amount' => $amount, 'received_amount' => $amount,
                'payment_date' => ($paymentDate ?? now())->toDateString(),
                'payment_method' => 'bank_transfer', 'payment_source_id' => $source->id,
                'transaction_reference' => $ref,
            ])->assertOk();

        $payment = EnquiryPayment::where('project_enquiry_id', $enquiry->id)
            ->where('transaction_reference', $ref)->firstOrFail();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")
            ->assertOk();

        return $payment->fresh();
    }

    public function test_the_financial_position_distinguishes_every_confirmed_figure(): void
    {
        $enquiry = $this->enquiry(1320000);
        $invoice = $this->issuedInvoice($enquiry, 1000000);
        $payment = $this->recordAndVerifyPayment($enquiry, 500000, 'CFP-REF-1');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => 500000,
            ])->assertOk();

        $data = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/projects/enquiries/{$enquiry->id}/financial-position")
            ->assertOk()->json('data');

        $this->assertEquals(1320000.0, $data['approved_quote_value']);
        $this->assertEquals(1160000.0, $data['amount_invoiced']);
        $this->assertEquals(160000.0, $data['remaining_to_invoice']);
        $this->assertEquals(500000.0, $data['cash_received']);
        $this->assertEquals(500000.0, $data['amount_allocated']);
        $this->assertEquals(660000.0, $data['invoice_outstanding']);
        $this->assertEquals(0.0, $data['unallocated_client_credit']);
        $this->assertNull($data['unallocated_client_credit_age_days']);
        $this->assertEquals(1000000.0, $data['project_revenue']);
        $this->assertFalse($data['project_margin']['fully_loaded_available']);
        $this->assertArrayHasKey('fully_loaded_note', $data['project_margin']);
    }

    public function test_an_unallocated_receipt_is_visible_as_client_credit_with_an_age(): void
    {
        $enquiry = $this->enquiry(1000000);
        $this->issuedInvoice($enquiry, 500000);
        $this->recordAndVerifyPayment($enquiry, 300000, 'CFP-UNALLOC-1', now()->subDays(12));

        $data = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/projects/enquiries/{$enquiry->id}/financial-position")
            ->assertOk()->json('data');

        $this->assertEquals(300000.0, $data['cash_received']);
        $this->assertEquals(0.0, $data['amount_allocated']);
        $this->assertEquals(300000.0, $data['unallocated_client_credit']);
        $this->assertEquals(12, $data['unallocated_client_credit_age_days']);

        // Project revenue reflects the issued invoice only — the unallocated
        // receipt itself is never automatically recognised as revenue,
        // refunded, or allocated to an unrelated invoice.
        $this->assertEquals(500000.0, $data['project_revenue']);
    }

    public function test_verification_and_allocation_are_recorded_as_separate_events_by_the_same_authorized_person(): void
    {
        $enquiry = $this->enquiry(1000000);
        $invoice = $this->issuedInvoice($enquiry, 500000);

        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                'amount' => 500000, 'received_amount' => 500000,
                'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer',
                'payment_source_id' => $source->id, 'transaction_reference' => 'CFP-SAME-1',
            ])->assertOk();

        $payment = EnquiryPayment::where('transaction_reference', 'CFP-SAME-1')->firstOrFail();

        // The accountant recorded the receipt. Separation of duties
        // (FinanceService::verifyPayment) refuses to let the same person who
        // recorded it also verify it, so verification+allocation is done by
        // a different, single authorized Finance person (the verifier) —
        // proving W1-5's confirmed rule that verify and allocate MAY be done
        // by the same person, while both events remain separately recorded.
        $verifiedAt = now()->addMinute();
        $this->travelTo($verifiedAt);
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")
            ->assertOk();

        $allocatedAt = now()->addMinute();
        $this->travelTo($allocatedAt);
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => 500000,
            ])->assertOk();

        $payment->refresh();
        $this->assertSame($this->verifier->id, $payment->verified_by);
        $this->assertTrue($payment->verified_at->equalTo($verifiedAt));

        $allocation = DB::table('project_invoice_allocations')
            ->where('project_invoice_id', $invoice->id)->where('enquiry_payment_id', $payment->id)
            ->first();
        $this->assertSame($this->verifier->id, $allocation->allocated_by);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($allocation->created_at)->equalTo($allocatedAt));

        // Two genuinely separate events, not one merged system event.
        $this->assertNotSame($payment->verified_at->toDateTimeString(), $allocation->created_at);
    }

    public function test_over_allocation_beyond_the_payment_or_invoice_balance_remains_blocked(): void
    {
        $enquiry = $this->enquiry(1000000);
        $invoice = $this->issuedInvoice($enquiry, 500000);
        $payment = $this->recordAndVerifyPayment($enquiry, 500000, 'CFP-OVER-1');

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => 600000,
            ]);

        $response->assertStatus(422);
    }
}
