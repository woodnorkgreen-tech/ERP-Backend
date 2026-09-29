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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 58 (Stream B): the W1 Finance workspace projections
 * (GET api/finance/invoices, invoices/{id}, receipts) and the W1 lifecycle
 * driven through the existing per-project routes they describe.
 */
class ReceivablesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;

    private User $checker;

    private User $cashier;

    private User $verifier;

    private User $officer;

    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        $all = [
            Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD, Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_REVERSE, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK, Permissions::FINANCE_RECEIVABLES_CORRECT, Permissions::FINANCE_REPORTS_VIEW,
        ];
        foreach ($all as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->preparer = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK, Permissions::FINANCE_RECEIVABLES_RECORD);
        $this->checker = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS, Permissions::FINANCE_REPORTS_VIEW);
        $this->cashier = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_VERIFY, Permissions::FINANCE_RECEIVABLES_REVERSE);
        $this->verifier = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_VERIFY);
        $this->officer = User::factory()->create(['is_active' => true]);
        $this->bank = PaymentSource::where('type', 'bank')->whereNotNull('gl_account_id')->where('is_active', true)->firstOrFail();
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enquiry(string $client = 'Acme Events', ?int $officer = null, float $quote = 1000000): ProjectEnquiry
    {
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create(['company_name' => $client, 'full_name' => $client])->id,
            'title' => "Stand for {$client}", 'description' => 'W1 workspace test', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED, 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-W1-'.uniqid(), 'job_number' => 'JOB-W1-'.uniqid(), 'created_by' => $this->preparer->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
            'project_officer_id' => $officer ?? $this->officer->id,
        ]);
        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved', 'approved_by' => $this->preparer->id,
            'approval_date' => '2026-09-01', 'quote_amount' => $quote, 'quote_data' => json_encode(['grandTotal' => $quote]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $enquiry;
    }

    private function draft(ProjectEnquiry $enquiry, float $price = 100000, string $date = '2026-09-08', string $due = '2026-10-08'): int
    {
        return $this->actingAs($this->preparer, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
            'invoice_date' => $date, 'due_date' => $due,
            'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $price]],
        ])->assertCreated()->json('data.id');
    }

    private function issued(ProjectEnquiry $enquiry, float $price = 100000, string $date = '2026-09-08', string $due = '2026-10-08'): int
    {
        $id = $this->draft($enquiry, $price, $date, $due);
        $this->actingAs($this->checker, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$id}/check")->assertOk();
        $this->actingAs($this->checker, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$id}/issue")->assertOk();

        return $id;
    }

    private function receipt(ProjectEnquiry $enquiry, float $amount, ?User $recorder = null): int
    {
        $this->actingAs($recorder ?? $this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
            'amount' => $amount, 'received_amount' => $amount, 'payment_date' => '2026-09-08',
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id, 'transaction_reference' => 'FT-'.uniqid(),
        ])->assertSuccessful();

        return (int) EnquiryPayment::where('project_enquiry_id', $enquiry->id)->latest('id')->value('id');
    }

    private function show(User $user, int $invoice): array
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/finance/invoices/{$invoice}")->assertOk()->json('data');
    }

    // ── Access ─────────────────────────────────────────────────────────

    public function test_the_workspace_is_closed_to_anyone_without_receivables_read(): void
    {
        $id = $this->draft($this->enquiry());
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')->getJson('/api/finance/invoices')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/finance/invoices/{$id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/finance/receipts')->assertForbidden();
        $this->actingAs($this->verifier, 'sanctum')->getJson('/api/finance/invoices')->assertOk();
    }

    // ── Index ──────────────────────────────────────────────────────────

    public function test_the_index_filters_on_the_server_and_paginates(): void
    {
        $otherOfficer = User::factory()->create(['is_active' => true]);
        $acme = $this->enquiry('Acme Events');
        $zulu = $this->enquiry('Zulu Media', $otherOfficer->id);
        $this->draft($acme);
        $issuedAcme = $this->issued($acme, 50000, '2026-08-01', '2026-08-15');
        $this->issued($zulu, 70000);
        foreach (range(1, 10) as $n) {
            $this->draft($zulu, 1000 + $n);
        }

        $get = fn (string $query) => $this->actingAs($this->checker, 'sanctum')->getJson("/api/finance/invoices?{$query}")->assertOk();

        $get('per_page=10')->assertJsonPath('meta.total', 13)->assertJsonCount(10, 'data');
        $get('per_page=10&page=2')->assertJsonCount(3, 'data');
        $get("client_id={$acme->client_id}")->assertJsonPath('meta.total', 2);
        $get("enquiry_id={$zulu->id}")->assertJsonPath('meta.total', 11);
        $get("project_officer_id={$otherOfficer->id}")->assertJsonPath('meta.total', 11);
        $get('state=issued')->assertJsonPath('meta.total', 2);
        $get('state=draft')->assertJsonPath('meta.total', 11);
        $get('overdue=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $issuedAcme)->assertJsonPath('data.0.days_overdue', 24);
        $get('invoice_date_to=2026-08-31')->assertJsonPath('meta.total', 1);
        $get('due_date_from=2026-10-01')->assertJsonPath('meta.total', 12);
        $get('search=Zulu')->assertJsonPath('meta.total', 11);
        $number = ProjectInvoice::find($issuedAcme)->invoice_number;
        $get('search='.urlencode($number))->assertJsonPath('meta.total', 1);
    }

    public function test_rows_carry_backend_amounts_and_names_only(): void
    {
        $enquiry = $this->enquiry();
        $id = $this->issued($enquiry, 100000);
        $receipt = $this->receipt($enquiry, 40000);
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$receipt}/verify")->assertOk();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$id}/allocate", ['payment_id' => $receipt, 'amount' => 40000])->assertOk();

        $row = $this->actingAs($this->checker, 'sanctum')->getJson("/api/finance/invoices?enquiry_id={$enquiry->id}")->assertOk()->json('data.0');
        $this->assertSame(['100000.00', '40000.00', '60000.00'], [$row['net_total_amount'], $row['paid_amount'], $row['balance']]);
        $this->assertSame(['issued', 'partially_paid', 'partially_paid'], [$row['document_state'], $row['payment_state'], $row['review_state']]);
        $this->assertSame(['id', 'name'], array_keys($row['prepared_by']));
        $this->assertSame(['id', 'name'], array_keys($row['project_officer']));
        $json = json_encode($this->show($this->checker, $id));
        foreach (['salary', 'password', 'email', 'employee_id', 'kra_pin'] as $field) {
            $this->assertStringNotContainsString("\"{$field}\"", $json, "{$field} must never be serialised");
        }

        // The same balance the receivables ageing report holds.
        $ageing = $this->actingAs($this->checker, 'sanctum')->getJson('/api/finance/reports/receivables-ageing')->assertOk()->json('data.rows');
        $this->assertSame('60000.00', collect($ageing)->firstWhere('invoice_id', $id)['balance']);
    }

    // ── Invoice maker/checker ──────────────────────────────────────────

    public function test_the_invoice_moves_preparer_checker_preparer_checker_issuer(): void
    {
        $enquiry = $this->enquiry();
        $id = $this->draft($enquiry);
        $base = "/api/projects/enquiries/{$enquiry->id}/invoices/{$id}";

        // The preparer is never offered Check, even holding the permission; another holder is.
        $this->assertFalse($this->show($this->preparer, $id)['actions']['check']['allowed']);
        $this->assertSame('You prepared this invoice, so someone else has to check it.', $this->show($this->preparer, $id)['actions']['check']['reason']);
        $this->assertTrue($this->show($this->checker, $id)['actions']['check']['allowed']);
        $this->assertSame('check', $this->show($this->checker, $id)['next_action']);
        $this->actingAs($this->preparer, 'sanctum')->postJson("{$base}/check")->assertStatus(422);

        // Returned: back with the preparer; nobody may check it until it is resubmitted.
        $this->actingAs($this->checker, 'sanctum')->postJson("{$base}/return-for-correction", ['reason' => 'Wrong line description'])->assertOk();
        $checkerView = $this->show($this->checker, $id);
        $this->assertSame('returned_for_correction', $checkerView['document_state']);
        $this->assertFalse($checkerView['actions']['check']['allowed']);
        $this->actingAs($this->checker, 'sanctum')->postJson("{$base}/check")->assertStatus(422)
            ->assertJsonPath('message', 'This invoice was returned to its preparer for correction. It can be checked once they resubmit it.');
        $preparerView = $this->show($this->preparer, $id);
        $this->assertTrue($preparerView['actions']['edit']['allowed']);
        $this->assertSame('edit', $preparerView['next_action']);
        $this->assertSame('Wrong line description', $preparerView['return_reason']);

        // Resubmitted by correcting it: back to independent checking.
        $this->actingAs($this->preparer, 'sanctum')->putJson($base, ['lines' => [['description' => 'Stand build and branding', 'quantity' => 1, 'unit_price' => 100000]]])->assertOk();
        $this->assertSame('awaiting_review', $this->show($this->checker, $id)['document_state']);
        $this->assertTrue($this->show($this->checker, $id)['actions']['check']['allowed']);
        $this->assertFalse($this->show($this->checker, $id)['actions']['issue']['allowed']);

        // Checked: issue becomes available (the checker may also be the issuer).
        $this->actingAs($this->checker, 'sanctum')->postJson("{$base}/check")->assertOk();
        $checked = $this->show($this->checker, $id);
        $this->assertSame('checked', $checked['document_state']);
        $this->assertTrue($checked['actions']['issue']['allowed']);
        $this->actingAs($this->checker, 'sanctum')->postJson("{$base}/issue")->assertOk();
        $issued = $this->show($this->checker, $id);
        $this->assertSame(['issued', 'unpaid'], [$issued['document_state'], $issued['payment_state']]);
        $this->assertSame($this->checker->id, $issued['issued_by']['id']);
        $this->assertNotEmpty(collect($issued['audit'])->where('event', 'invoice_returned_for_correction'));
    }

    // ── Receipts ───────────────────────────────────────────────────────

    public function test_a_receipt_is_verified_independently_then_applied(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issued($enquiry, 100000);
        $receiptId = $this->receipt($enquiry, 150000);
        $row = fn (User $user) => $this->actingAs($user, 'sanctum')->getJson("/api/finance/receipts?receipt_id={$receiptId}")->assertOk()->json('data.0');

        // Recorded: pending, not yet client money, not verifiable by its recorder.
        $pending = $row($this->cashier);
        $this->assertSame(['pending', '0.00', '0.00'], [$pending['status'], $pending['applied_amount'], $pending['unapplied_amount']]);
        $this->assertFalse($pending['actions']['verify']['allowed']);
        $this->assertSame('This receipt was recorded by you, so someone else has to confirm it.', $pending['actions']['verify']['reason']);
        $this->assertTrue($row($this->verifier)['actions']['verify']['allowed']);
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$receiptId}/verify")->assertStatus(422);
        $this->assertFalse($this->show($this->cashier, $invoice)['actions']['allocate']['allowed']);

        // Verified: the money is held for the client (deposit), not yet applied.
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$receiptId}/verify")->assertOk();
        $verified = $row($this->cashier);
        $this->assertSame(['verified', '150000.00'], [$verified['status'], $verified['unapplied_amount']]);
        $this->assertSame($this->verifier->id, $verified['verified_by']['id']);
        $this->assertTrue($verified['actions']['reverse']['allowed']);
        $detail = $this->show($this->cashier, $invoice);
        $this->assertTrue($detail['actions']['allocate']['allowed']);
        $this->assertSame([['id' => $receiptId, 'available' => '150000.00']], collect($detail['applicable_receipts'])->map(fn ($r) => ['id' => $r['id'], 'available' => $r['available']])->all());

        // Applied: the invoice settles; the remainder stays held for the client.
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice}/allocate", ['payment_id' => $receiptId, 'amount' => 100000])->assertOk();
        $applied = $row($this->cashier);
        $this->assertSame(['100000.00', '50000.00'], [$applied['applied_amount'], $applied['unapplied_amount']]);
        $this->assertSame(ProjectInvoice::find($invoice)->invoice_number, $applied['allocations'][0]['invoice_number']);
        $this->assertFalse($applied['actions']['reverse']['allowed']);
        $paid = $this->show($this->cashier, $invoice);
        $this->assertSame(['paid', '0.00'], [$paid['payment_state'], $paid['balance']]);
        $this->assertSame(0, $paid['days_overdue']);
        $this->assertFalse($paid['actions']['void']['allowed']);

        $this->actingAs($this->cashier, 'sanctum')->getJson('/api/finance/receipts?application=partly_applied')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->cashier, 'sanctum')->getJson('/api/finance/receipts')->assertOk()
            ->assertJsonPath('summary.unapplied_client_money', '50000.00')->assertJsonPath('summary.pending_verification', 0);
    }

    public function test_an_unapplied_receipt_can_be_reversed_and_then_offers_nothing(): void
    {
        $enquiry = $this->enquiry();
        $receiptId = $this->receipt($enquiry, 20000);
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$receiptId}/verify")->assertOk();
        $this->actingAs($this->cashier, 'sanctum')->deleteJson("/api/projects/enquiries/{$enquiry->id}/payments/{$receiptId}", ['reason' => 'Bounced transfer'])->assertOk();

        $row = $this->actingAs($this->cashier, 'sanctum')->getJson('/api/finance/receipts?status=reversed')->assertOk()->json('data.0');
        $this->assertSame(['reversed', 'Bounced transfer', '0.00'], [$row['status'], $row['reversal_reason'], $row['unapplied_amount']]);
        $this->assertFalse($row['actions']['verify']['allowed']);
        $this->assertFalse($row['actions']['reverse']['allowed']);
    }

    public function test_a_receipt_into_an_unlinked_mobile_money_account_is_refused(): void
    {
        $mpesa = PaymentSource::where('type', 'mobile_money')->firstOrFail();
        $mpesa->update(['is_active' => false, 'gl_account_id' => null]);
        $enquiry = $this->enquiry();

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
            'amount' => 500, 'received_amount' => 500, 'payment_date' => '2026-09-08', 'payment_method' => 'mpesa',
            'payment_source_id' => $mpesa->id, 'transaction_reference' => 'MP-1',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_source_id');
    }

    public function test_voiding_and_credit_notes_are_described_by_the_invoice(): void
    {
        $enquiry = $this->enquiry();
        $draft = $this->draft($enquiry);
        $this->assertTrue($this->show($this->cashier, $draft)['actions']['void']['allowed']);
        $this->assertFalse($this->show($this->cashier, $draft)['actions']['credit_note']['allowed']);

        $issued = $this->issued($enquiry, 80000);
        $this->assertTrue($this->show($this->cashier, $issued)['actions']['credit_note']['allowed']);
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$issued}/credit-notes", [
            'invoice_date' => '2026-09-08', 'reason' => 'Graphics not delivered',
            'lines' => [['description' => 'Graphics', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertSuccessful();

        $detail = $this->show($this->cashier, $issued);
        $this->assertCount(1, $detail['credit_notes']);
        $note = $detail['credit_notes'][0];
        $this->assertTrue($note['is_credit_note']);
        // The person who raised the credit note does not issue it.
        $this->assertFalse($note['actions']['issue']['allowed']);
        $this->assertSame('You raised this credit note, so someone else has to issue it.', $note['actions']['issue']['reason']);
        // Report 58 §37 pinned the old behaviour here (a DRAFT credit note
        // reduced the balance before the ledger moved). Report 59 corrected it:
        // a draft is pending, shown separately, and changes no balance until
        // it is issued — see ProjectBillingClosureTest for every other reader.
        $this->assertSame('80000.00', $detail['balance']);
        $this->assertSame('10000.00', $detail['pending_credit_amount']);
    }
}
