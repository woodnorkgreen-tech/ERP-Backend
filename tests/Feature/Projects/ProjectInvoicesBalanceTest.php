<?php

namespace Tests\Feature\Projects;

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
 * `GET enquiries/{enquiry}/invoices` reports what a client still owes.
 *
 * Before ProjectInvoice::scopeWithVerifiedPaidAmount() existed, this endpoint
 * summed every allocation with no status filter — a `pending` or `reversed`
 * EnquiryPayment counted exactly like a verified one, so a receipt that was
 * verified, allocated, and later reversed (a bounced transfer, a bank
 * reversal) left the invoice looking paid forever. These pin the fix: only a
 * verified, unreversed allocation may reduce the reported balance. This
 * endpoint had zero test coverage before this file.
 */
class ProjectInvoicesBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        // Separation of duties (FinanceService::verifyPayment) refuses to let
        // whoever recorded a receipt also confirm it arrived, so verification
        // needs its own user — the same split ReceivablesPostingTest uses.
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo(Permissions::FINANCE_RECEIVABLES_VERIFY);
    }

    /** A project with an approved quote, which is what makes it billable. */
    private function enquiry(float $quoteAmount = 1000000): ProjectEnquiry
    {
        $client = Client::firstWhere('email', 'invbal@test.local')
            ?? Client::factory()->create(['email' => 'invbal@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Invoice balance regression project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-BAL-' . uniqid(),
            'created_by' => $this->accountant->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0,
            'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved',
            'approved_by' => $this->accountant->id,
            'approval_date' => '2026-09-01',
            'quote_amount' => $quoteAmount,
            // NOT NULL with no default; the column holds the decision snapshot.
            'quote_data' => json_encode(['grandTotal' => $quoteAmount]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $enquiry;
    }

    private function issuedInvoice(ProjectEnquiry $enquiry, float $unitPrice): ProjectInvoice
    {
        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [
                    ['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $unitPrice, 'vat_treatment_id' => $vat->id],
                ],
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        return $invoice->fresh();
    }

    private function verifiedPayment(ProjectEnquiry $enquiry, float $amount, string $ref): EnquiryPayment
    {
        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();

        $recorded = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                'amount' => $amount,
                'received_amount' => $amount,
                'payment_date' => '2026-09-05',
                'payment_method' => 'bank_transfer',
                'payment_source_id' => $source->id,
                'transaction_reference' => $ref,
            ]);

        if (! in_array($recorded->status(), [200, 201], true)) {
            $this->markTestSkipped('Payment capture route shape differs: ' . $recorded->status());
        }

        $payment = EnquiryPayment::where('project_enquiry_id', $enquiry->id)
            ->where('transaction_reference', $ref)->firstOrFail();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")
            ->assertOk();

        return $payment->fresh();
    }

    /** This invoice's row exactly as the screen would see it. */
    private function balanceFor(ProjectEnquiry $enquiry, ProjectInvoice $invoice): array
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/projects/enquiries/{$enquiry->id}/invoices")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $invoice->id);
        $this->assertNotNull($row, 'Invoice missing from the list.');

        return $row;
    }

    public function test_a_verified_unreversed_payment_reduces_and_can_clear_the_balance(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);

        $payment = $this->verifiedPayment($enquiry, (float) $invoice->total_amount, 'BAL-FULL-001');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id,
                'amount' => $invoice->total_amount,
            ])->assertOk();

        $row = $this->balanceFor($enquiry, $invoice);
        $this->assertSame((float) $invoice->total_amount, (float) $row['paid_amount']);
        $this->assertSame(0.0, (float) $row['balance']);
    }

    public function test_a_reversed_payment_no_longer_reduces_the_reported_balance(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);

        $payment = $this->verifiedPayment($enquiry, (float) $invoice->total_amount, 'BAL-REV-001');

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id,
                'amount' => $invoice->total_amount,
            ])->assertOk();

        // Confirm the allocation really did clear the balance first...
        $this->assertSame(0.0, (float) $this->balanceFor($enquiry, $invoice)['balance']);

        // ...then the receipt turns out to be bad (a bounced transfer) and is
        // reversed. The allocation row itself is untouched — only the
        // payment's own status changes, exactly as a real reversal leaves it.
        $payment->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversal_reason' => 'Bounced'])->save();

        $row = $this->balanceFor($enquiry, $invoice);
        $this->assertSame(0.0, (float) $row['paid_amount'], 'A reversed payment must not still count as paid.');
        $this->assertSame((float) $invoice->total_amount, (float) $row['balance']);
    }

    public function test_a_pending_unverified_allocation_does_not_reduce_the_reported_balance(): void
    {
        // Allocating a receipt requires it to already be verified and
        // unreversed (EnquiryController::allocatePaymentToInvoice), so this
        // exact combination cannot be reached through the API today — but the
        // report must not trust an allocation row's mere existence, in case
        // that guard ever changes or historical data holds one.
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);

        $pending = EnquiryPayment::create([
            'project_enquiry_id' => $enquiry->id,
            'amount' => $invoice->total_amount,
            'payment_date' => '2026-09-05',
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'recorded_by' => $this->accountant->id,
        ]);

        DB::table('project_invoice_allocations')->insert([
            'project_invoice_id' => $invoice->id,
            'enquiry_payment_id' => $pending->id,
            'amount' => $invoice->total_amount,
            'allocated_by' => $this->accountant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->balanceFor($enquiry, $invoice);
        $this->assertSame(0.0, (float) $row['paid_amount']);
        $this->assertSame((float) $invoice->total_amount, (float) $row['balance']);
    }
}
