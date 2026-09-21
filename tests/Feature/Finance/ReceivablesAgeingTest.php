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
 * Which issued invoices are still owed, and for how long.
 *
 * No AR ageing report existed anywhere in the codebase before this. These
 * pin the bucket boundaries, that only a verified/unreversed payment can
 * shrink a balance (the same regression ProjectInvoicesBalanceTest pins on
 * the plain invoice list), and that a fully-settled or never-issued invoice
 * does not appear at all.
 */
class ReceivablesAgeingTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $verifier;
    private User $reverser;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_REPORTS_VIEW,
            Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_REVERSE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo([
            Permissions::FINANCE_REPORTS_VIEW,
            Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo(Permissions::FINANCE_RECEIVABLES_VERIFY);

        $this->reverser = User::factory()->create(['is_active' => true]);
        $this->reverser->givePermissionTo(Permissions::FINANCE_RECEIVABLES_REVERSE);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function enquiry(float $agreedPrice = 5000000): ProjectEnquiry
    {
        $client = Client::factory()->create(['email' => 'age-' . uniqid() . '@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Receivables ageing test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-AGE-' . uniqid(),
            'created_by' => $this->accountant->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved', 'approved_by' => $this->accountant->id,
            'approval_date' => '2026-09-01', 'quote_amount' => $agreedPrice,
            'quote_data' => json_encode(['grandTotal' => $agreedPrice]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $enquiry;
    }

    private function draftInvoice(ProjectEnquiry $enquiry, float $unitPrice): ProjectInvoice
    {
        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $unitPrice, 'vat_treatment_id' => $vat->id]],
            ])->assertCreated();

        return ProjectInvoice::findOrFail($created->json('data.id'));
    }

    private function issuedInvoice(ProjectEnquiry $enquiry, float $unitPrice): ProjectInvoice
    {
        $invoice = $this->draftInvoice($enquiry, $unitPrice);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        return $invoice->fresh();
    }

    private function dueOn(ProjectInvoice $invoice, string $date): ProjectInvoice
    {
        $invoice->forceFill(['due_date' => $date])->save();

        return $invoice->fresh();
    }

    private function verifiedPayment(ProjectEnquiry $enquiry, float $amount, string $ref): EnquiryPayment
    {
        $source = PaymentSource::whereNotNull('gl_account_id')->firstOrFail();

        $recorded = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
                'amount' => $amount, 'received_amount' => $amount,
                'payment_date' => '2026-09-05', 'payment_method' => 'bank_transfer',
                'payment_source_id' => $source->id, 'transaction_reference' => $ref,
            ]);
        $this->assertContains($recorded->status(), [200, 201]);

        $payment = EnquiryPayment::where('project_enquiry_id', $enquiry->id)
            ->where('transaction_reference', $ref)->firstOrFail();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$payment->id}/verify")
            ->assertOk();

        return $payment->fresh();
    }

    private function ageing(array $query = []): array
    {
        return $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/receivables-ageing?' . http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_ageing_is_not_readable_without_the_reports_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/reports/receivables-ageing')
            ->assertForbidden();
    }

    public function test_an_empty_book_returns_zero_buckets_not_an_error(): void
    {
        $data = $this->ageing();

        $this->assertSame(0, $data['totals']['count']);
        $this->assertSame('0.00', $data['totals']['value']);
        $this->assertCount(5, $data['buckets']);
        foreach ($data['buckets'] as $bucket) {
            $this->assertSame(0, $bucket['count']);
        }
    }

    public function test_draft_and_void_invoices_are_excluded(): void
    {
        $enquiry = $this->enquiry();
        $this->draftInvoice($enquiry, 100000);

        $voided = $this->issuedInvoice($this->enquiry(), 100000);
        $this->actingAs($this->reverser, 'sanctum')
            ->postJson("/api/projects/enquiries/{$voided->project_enquiry_id}/invoices/{$voided->id}/void", [
                'reason' => 'Raised in error',
            ])->assertOk();

        $data = $this->ageing();

        $this->assertSame(0, $data['totals']['count']);
    }

    public function test_an_invoice_is_bucketed_by_days_overdue(): void
    {
        // "Today" is fixed at 2026-09-08 for the whole test via travelTo.
        $current = $this->dueOn($this->issuedInvoice($this->enquiry(), 10000), '2026-09-08');
        $bucket1 = $this->dueOn($this->issuedInvoice($this->enquiry(), 20000), '2026-08-24'); // 15 days
        $bucket2 = $this->dueOn($this->issuedInvoice($this->enquiry(), 30000), '2026-07-25'); // 45 days
        $bucket3 = $this->dueOn($this->issuedInvoice($this->enquiry(), 40000), '2026-06-25'); // 75 days
        $bucket4 = $this->dueOn($this->issuedInvoice($this->enquiry(), 50000), '2026-05-01'); // 130 days

        $data = $this->ageing();

        $byId = collect($data['buckets'])->keyBy('id');
        $this->assertSame(1, $byId['current']['count']);
        $this->assertSame(1, $byId['1_30']['count']);
        $this->assertSame(1, $byId['31_60']['count']);
        $this->assertSame(1, $byId['61_90']['count']);
        $this->assertSame(1, $byId['90_plus']['count']);
        $this->assertSame(5, $data['totals']['count']);

        $rowsById = collect($data['rows'])->keyBy('invoice_id');
        $this->assertSame('current', $rowsById[$current->id]['bucket']);
        $this->assertSame('1_30', $rowsById[$bucket1->id]['bucket']);
        $this->assertSame('31_60', $rowsById[$bucket2->id]['bucket']);
        $this->assertSame('61_90', $rowsById[$bucket3->id]['bucket']);
        $this->assertSame('90_plus', $rowsById[$bucket4->id]['bucket']);
        $this->assertSame(15, $rowsById[$bucket1->id]['days_overdue']);

        // The `bucket` filter narrows the row list without a second request.
        $filtered = $this->ageing(['bucket' => '31_60']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame($bucket2->id, $filtered['rows'][0]['invoice_id']);
        $this->assertTrue(collect($filtered['buckets'])->keyBy('id')['31_60']['active']);
    }

    public function test_a_verified_unreversed_payment_reduces_and_can_clear_the_balance(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->dueOn($this->issuedInvoice($enquiry, 100000), '2026-08-01');

        $payment = $this->verifiedPayment($enquiry, (float) $invoice->total_amount, 'AGE-FULL-001');
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => $invoice->total_amount,
            ])->assertOk();

        $data = $this->ageing();

        // Fully settled, so it must not appear as an outstanding row at all.
        $this->assertSame(0, $data['totals']['count']);
    }

    public function test_a_reversed_payment_reappears_as_outstanding(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->dueOn($this->issuedInvoice($enquiry, 100000), '2026-08-01');

        $payment = $this->verifiedPayment($enquiry, (float) $invoice->total_amount, 'AGE-REV-001');
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/allocate", [
                'payment_id' => $payment->id, 'amount' => $invoice->total_amount,
            ])->assertOk();

        $this->assertSame(0, $this->ageing()['totals']['count']);

        // The receipt turns out to be bad and is reversed after the fact —
        // the allocation row is untouched, only the payment's status changes.
        $payment->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversal_reason' => 'Bounced'])->save();

        $data = $this->ageing();
        $this->assertSame(1, $data['totals']['count']);
        $this->assertSame((float) $invoice->total_amount, (float) $data['totals']['value']);
    }
}
