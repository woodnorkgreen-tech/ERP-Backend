<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\ClientReceipt;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 59 (Stream B closure).
 *
 * 1. A draft credit note is a business document, not an accounting event: it
 *    changes no balance, ageing, allocation capacity, client position or
 *    billing headroom until it is issued — and once issued, every one of those
 *    agrees with Accounts Receivable in the ledger.
 * 2. GET api/finance/project-billing/{enquiry}: the Project billing controls
 *    that replaced EnquiryFinanceModal (commercial basis / quote waiver,
 *    deposit terms, production release, split receipts), with the actor,
 *    time and reason of each control and the backend's own action rules.
 */
class ProjectBillingClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;

    private User $checker;

    private User $cashier;

    private User $approver;

    private User $releaser;

    private User $overrider;

    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD, Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_REVERSE, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS, Permissions::FINANCE_RECEIVABLES_CORRECT,
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK, Permissions::FINANCE_RECEIVABLES_RELEASE, Permissions::FINANCE_RECEIVABLES_OVERRIDE,
            Permissions::FINANCE_REPORTS_VIEW,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->preparer = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS, Permissions::FINANCE_RECEIVABLES_RECORD);
        $this->checker = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS, Permissions::FINANCE_REPORTS_VIEW);
        $this->cashier = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD,
            Permissions::FINANCE_RECEIVABLES_VERIFY, Permissions::FINANCE_RECEIVABLES_REVERSE);
        $this->approver = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_REVERSE,
            Permissions::FINANCE_RECEIVABLES_VERIFY, Permissions::FINANCE_RECEIVABLES_RECORD);
        $this->releaser = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RELEASE);
        $this->overrider = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RELEASE, Permissions::FINANCE_RECEIVABLES_OVERRIDE);
        $this->bank = PaymentSource::where('type', 'bank')->whereNotNull('gl_account_id')->where('is_active', true)->firstOrFail();
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enquiry(?float $quote = 1000000, string $client = 'Acme Events'): ProjectEnquiry
    {
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create(['company_name' => $client, 'full_name' => $client])->id,
            'title' => "Stand for {$client}", 'description' => 'Report 59 test', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED, 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-59-'.uniqid(), 'job_number' => 'JOB-59-'.uniqid(), 'created_by' => $this->preparer->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
        ]);
        if ($quote !== null) {
            DB::table('quote_approvals')->insert([
                'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved', 'approved_by' => $this->preparer->id,
                'approval_date' => '2026-09-01', 'quote_amount' => $quote, 'quote_data' => json_encode(['grandTotal' => $quote]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $enquiry;
    }

    private function url(ProjectEnquiry $enquiry, string $path = ''): string
    {
        return "/api/projects/enquiries/{$enquiry->id}{$path}";
    }

    private function draftInvoice(ProjectEnquiry $enquiry, float $price): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->preparer, 'sanctum')->postJson($this->url($enquiry, '/invoices'), [
            'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
            'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $price]],
        ]);
    }

    private function issuedInvoice(ProjectEnquiry $enquiry, float $price): int
    {
        $id = $this->draftInvoice($enquiry, $price)->assertCreated()->json('data.id');
        $this->actingAs($this->checker, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$id}/check"))->assertOk();
        $this->actingAs($this->checker, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$id}/issue"))->assertOk();

        return $id;
    }

    private function draftCreditNote(ProjectEnquiry $enquiry, int $invoice, float $amount): int
    {
        return $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$invoice}/credit-notes"), [
            'invoice_date' => '2026-09-09', 'reason' => 'Part of the order cancelled',
            'lines' => [['description' => 'Reduction', 'quantity' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function verifiedReceipt(ProjectEnquiry $enquiry, float $amount, ?float $received = null, string $reference = ''): int
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, '/payments'), [
            'amount' => $amount, 'received_amount' => $received ?? $amount, 'payment_date' => '2026-09-08',
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id,
            'transaction_reference' => $reference ?: 'FT-'.uniqid(),
        ])->assertSuccessful();
        $id = (int) EnquiryPayment::where('project_enquiry_id', $enquiry->id)->latest('id')->value('id');
        $this->actingAs($this->approver, 'sanctum')->postJson($this->url($enquiry, "/payments/{$id}/verify"))->assertOk();

        return $id;
    }

    /**
     * Every reader of one invoice's position: the Finance detail and index
     * projections, the per-project list, receivables ageing, the client
     * position, and the Accounts Receivable ledger balance.
     *
     * @return array<string, float>
     */
    private function views(ProjectEnquiry $enquiry, int $invoice): array
    {
        $detail = $this->actingAs($this->checker, 'sanctum')->getJson("/api/finance/invoices/{$invoice}")->assertOk()->json('data');
        $index = collect($this->actingAs($this->checker, 'sanctum')->getJson("/api/finance/invoices?enquiry_id={$enquiry->id}")->assertOk()->json('data'))->firstWhere('id', $invoice);
        $project = collect($this->actingAs($this->checker, 'sanctum')->getJson($this->url($enquiry, '/invoices'))->assertOk()->json('data'))->firstWhere('id', $invoice);
        $ageing = collect($this->actingAs($this->checker, 'sanctum')->getJson('/api/finance/reports/receivables-ageing')->assertOk()->json('data.rows'))->firstWhere('invoice_id', $invoice);
        $position = $this->actingAs($this->checker, 'sanctum')->getJson($this->url($enquiry, '/financial-position'))->assertOk()->json('data');

        $ar = (int) ChartOfAccount::where('code', ChartAccountMap::local(FinanceAccountFunctions::ACCOUNTS_RECEIVABLE))->value('id');
        $lines = JournalLine::where('account_id', $ar)->get();
        $ledger = (float) $lines->where('entry_type', 'debit')->sum(fn ($l) => (float) $l->amount)
            - (float) $lines->where('entry_type', 'credit')->sum(fn ($l) => (float) $l->amount);

        return [
            'detail_net' => (float) $detail['net_total_amount'],
            'detail_balance' => (float) $detail['balance'],
            'pending_credit' => (float) $detail['pending_credit_amount'],
            'index_balance' => (float) $index['balance'],
            'project_balance' => (float) $project['balance'],
            'ageing_balance' => (float) ($ageing['balance'] ?? 0),
            'position_invoiced' => (float) $position['amount_invoiced'],
            'position_outstanding' => (float) $position['invoice_outstanding'],
            'position_pending_credit' => (float) $position['pending_credit_notes'],
            'ledger_receivable' => round($ledger, 2),
        ];
    }

    // ── Credit notes: draft ≠ accounting effect ────────────────────────

    public function test_a_draft_credit_note_changes_no_balance_ageing_capacity_or_ledger_figure(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);
        $note = $this->draftCreditNote($enquiry, $invoice, 40000);

        $views = $this->views($enquiry, $invoice);
        foreach (['detail_net', 'detail_balance', 'index_balance', 'project_balance', 'ageing_balance', 'position_invoiced', 'position_outstanding', 'ledger_receivable'] as $key) {
            $this->assertEqualsWithDelta(100000.0, $views[$key], 0.01, "{$key} moved on a DRAFT credit note");
        }
        // Visible as pending, not hidden.
        $this->assertEqualsWithDelta(40000.0, $views['pending_credit'], 0.01);
        $this->assertEqualsWithDelta(40000.0, $views['position_pending_credit'], 0.01);
        $this->assertSame('draft', ProjectInvoice::findOrFail($note)->status);

        // Allocation capacity is the full, un-credited balance.
        $receipt = $this->verifiedReceipt($enquiry, 100000);
        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$invoice}/allocate"), [
            'payment_id' => $receipt, 'amount' => 100000,
        ])->assertOk();
    }

    public function test_issuing_a_credit_note_rechecks_money_applied_since_it_was_drafted(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);
        $note = $this->draftCreditNote($enquiry, $invoice, 40000);
        $receipt = $this->verifiedReceipt($enquiry, 100000);
        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$invoice}/allocate"), [
            'payment_id' => $receipt, 'amount' => 100000,
        ])->assertOk();

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson($this->url($enquiry, "/invoices/{$invoice}/credit-notes/{$note}/issue"))->assertStatus(422);

        $this->assertStringContainsString('Reverse the excess allocation first', $response->json('message'));
        $this->assertSame('draft', ProjectInvoice::findOrFail($note)->status);
        $this->assertNull(ProjectInvoice::findOrFail($note)->journal_entry_id);
    }

    public function test_an_issued_credit_note_moves_every_view_and_the_ledger_together_and_a_void_restores_them(): void
    {
        $enquiry = $this->enquiry();
        $invoice = $this->issuedInvoice($enquiry, 100000);
        $note = $this->draftCreditNote($enquiry, $invoice, 40000);

        // Segregation is unchanged: the preparer of a credit note cannot issue it.
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson($this->url($enquiry, "/invoices/{$invoice}/credit-notes/{$note}/issue"))->assertStatus(422);
        $this->actingAs($this->approver, 'sanctum')
            ->postJson($this->url($enquiry, "/invoices/{$invoice}/credit-notes/{$note}/issue"))->assertOk();

        $views = $this->views($enquiry, $invoice);
        foreach (['detail_net', 'detail_balance', 'index_balance', 'project_balance', 'ageing_balance', 'position_invoiced', 'position_outstanding', 'ledger_receivable'] as $key) {
            $this->assertEqualsWithDelta(60000.0, $views[$key], 0.01, "{$key} disagrees after the credit note was issued");
        }
        $this->assertEqualsWithDelta(0.0, $views['pending_credit'], 0.01);

        // Capacity is the credited balance.
        $receipt = $this->verifiedReceipt($enquiry, 100000);
        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$invoice}/allocate"), [
            'payment_id' => $receipt, 'amount' => 70000,
        ])->assertStatus(422);

        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, "/invoices/{$note}/void"), [
            'reason' => 'Credit note raised in error',
        ])->assertOk();
        $views = $this->views($enquiry, $invoice);
        foreach (['detail_net', 'detail_balance', 'index_balance', 'project_balance', 'ageing_balance', 'position_invoiced', 'ledger_receivable'] as $key) {
            $this->assertEqualsWithDelta(100000.0, $views[$key], 0.01, "{$key} not restored by voiding the credit note");
        }
    }

    public function test_a_draft_credit_note_frees_no_billing_headroom_until_issued(): void
    {
        $enquiry = $this->enquiry(100000);
        $invoice = $this->issuedInvoice($enquiry, 100000);
        $note = $this->draftCreditNote($enquiry, $invoice, 40000);

        $this->draftInvoice($enquiry, 30000)->assertStatus(422);
        $this->assertEqualsWithDelta(0.0, (float) $this->actingAs($this->checker, 'sanctum')
            ->getJson($this->url($enquiry, '/financial-position'))->json('data.remaining_to_invoice'), 0.01);

        $this->actingAs($this->approver, 'sanctum')
            ->postJson($this->url($enquiry, "/invoices/{$invoice}/credit-notes/{$note}/issue"))->assertOk();
        $this->draftInvoice($enquiry, 30000)->assertCreated();

        // The new draft uses headroom but is not billed or earned yet.
        $position = $this->actingAs($this->checker, 'sanctum')->getJson($this->url($enquiry, '/financial-position'))->json('data');
        $this->assertEqualsWithDelta(60000.0, (float) $position['amount_invoiced'], 0.01);
        $this->assertEqualsWithDelta(30000.0, (float) $position['draft_invoiced'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $position['remaining_to_invoice'], 0.01);
    }

    // ── Project billing projection ─────────────────────────────────────

    private function billing(User $user, ProjectEnquiry $enquiry): array
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/finance/project-billing/{$enquiry->id}")->assertOk()->json('data');
    }

    public function test_project_billing_is_closed_to_anyone_without_receivables_access(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum')
            ->getJson("/api/finance/project-billing/{$enquiry->id}")->assertForbidden();
    }

    public function test_an_approved_quote_needs_no_exception_and_cannot_be_overridden_by_a_waiver(): void
    {
        $enquiry = $this->enquiry(500000);
        $data = $this->billing($this->preparer, $enquiry);

        $this->assertTrue($data['commercial_basis']['has_approved_quote']);
        $this->assertFalse($data['commercial_basis']['exception_required']);
        $this->assertSame('500000.00', $data['commercial_basis']['amount']);
        $this->assertFalse($data['actions']['set_commercial_basis']['allowed']);
        $this->assertSame('This project already has an approved quote.', $data['actions']['set_commercial_basis']['reason']);

        // The backend refuses it regardless of what a screen offers.
        $this->actingAs($this->preparer, 'sanctum')->postJson($this->url($enquiry, '/quote-waiver'), [
            'billing_amount' => 1, 'reason' => 'Trying to replace the approved quote',
        ])->assertStatus(422);
    }

    public function test_the_quote_waiver_is_the_controlled_exception_with_actor_time_and_reason(): void
    {
        $enquiry = $this->enquiry(null);
        $before = $this->billing($this->preparer, $enquiry);
        $this->assertTrue($before['commercial_basis']['exception_required']);
        $this->assertFalse($before['commercial_basis']['established']);
        $this->assertNull($before['commercial_basis']['waiver']);
        $this->assertTrue($before['actions']['set_commercial_basis']['allowed']);
        $this->assertFalse($before['actions']['record_receipt']['allowed']);
        $this->assertFalse($before['actions']['release']['allowed']);

        // Without the billing-basis permission: not offered, and refused.
        $this->assertFalse($this->billing($this->cashier, $enquiry)['actions']['set_commercial_basis']['allowed']);
        $this->actingAs($this->cashier, 'sanctum')->postJson($this->url($enquiry, '/quote-waiver'), [
            'billing_amount' => 250000, 'reason' => 'Client agreed by phone, LPO to follow',
        ])->assertForbidden();

        $this->actingAs($this->preparer, 'sanctum')->postJson($this->url($enquiry, '/quote-waiver'), [
            'billing_amount' => 250000, 'reason' => 'Client agreed by phone, LPO to follow', 'mobilization_threshold_percentage' => 50,
        ])->assertOk();

        $after = $this->billing($this->preparer, $enquiry);
        $this->assertTrue($after['commercial_basis']['waived']);
        $this->assertTrue($after['commercial_basis']['established']);
        $this->assertSame('250000.00', $after['commercial_basis']['amount']);
        $this->assertSame('Client agreed by phone, LPO to follow', $after['commercial_basis']['waiver']['reason']);
        $this->assertSame($this->preparer->id, $after['commercial_basis']['waiver']['by']['id']);
        $this->assertNotNull($after['commercial_basis']['waiver']['at']);
        $this->assertSame(50.0, (float) $after['deposit']['threshold_percentage']);
        $this->assertTrue($after['actions']['record_receipt']['allowed']);
        $this->assertSame('Client agreed by phone, LPO to follow', collect($after['history'])->firstWhere('event', 'financial')['reason']);
        // Only id and name about any person.
        $this->assertSame(['id', 'name'], array_keys($after['commercial_basis']['waiver']['by']));
    }

    public function test_deposit_terms_changes_are_recorded_with_who_why_and_from_to(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->cashier, 'sanctum')->putJson($this->url($enquiry, '/receivables-terms'), [
            'mobilization_threshold_percentage' => 40, 'reason' => 'Contract says 40%, signed by MD',
        ])->assertForbidden();
        $this->actingAs($this->checker, 'sanctum')->putJson($this->url($enquiry, '/receivables-terms'), [
            'mobilization_threshold_percentage' => 40, 'reason' => 'Contract says 40%, signed by MD',
        ])->assertOk();

        $deposit = $this->billing($this->preparer, $enquiry)['deposit'];
        $this->assertSame(40.0, (float) $deposit['threshold_percentage']);
        $this->assertSame('400000.00', $deposit['threshold_amount']);
        $this->assertSame($this->checker->id, $deposit['last_change']['by']['id']);
        $this->assertSame('Contract says 40%, signed by MD', $deposit['last_change']['reason']);
        $this->assertEquals(70, $deposit['last_change']['from']);
        $this->assertEquals(40, $deposit['last_change']['to']);
    }

    public function test_early_production_release_needs_the_override_permission_and_a_reason(): void
    {
        $enquiry = $this->enquiry();
        $this->verifiedReceipt($enquiry, 100000);   // 10% of 1,000,000 against a 70% target

        $asReleaser = $this->billing($this->releaser, $enquiry);
        $this->assertFalse($asReleaser['deposit']['is_threshold_met']);
        $this->assertFalse($asReleaser['actions']['release']['allowed']);
        $this->assertStringContainsString('override permission', $asReleaser['actions']['release']['reason']);
        $this->actingAs($this->releaser, 'sanctum')->postJson($this->url($enquiry, '/release'), ['notes' => 'Client is good for it'])->assertForbidden();

        $this->assertTrue($this->billing($this->overrider, $enquiry)['actions']['release']['allowed']);
        $this->actingAs($this->overrider, 'sanctum')->postJson($this->url($enquiry, '/release'), ['notes' => ''])->assertStatus(422);
        $this->actingAs($this->overrider, 'sanctum')->postJson($this->url($enquiry, '/release'), ['notes' => 'MD approved start, deposit on Friday'])->assertOk();

        $production = $this->billing($this->preparer, $enquiry)['production'];
        $this->assertTrue($production['released']);
        $this->assertTrue($production['early']);
        $this->assertSame($this->overrider->id, $production['released_by']['id']);
        $this->assertSame('MD approved start, deposit on Friday', $production['reason']);
        $this->assertNotNull($production['released_at']);
        $this->assertTrue($production['post_release_breach']);
        $this->assertFalse($this->billing($this->overrider, $enquiry)['actions']['release']['allowed']);
    }

    public function test_a_receipt_split_across_projects_shows_every_share_and_what_is_left(): void
    {
        $first = $this->enquiry(1000000, 'Acme Events');
        $second = $this->enquiry(1000000, 'Acme Events');
        $share = $this->verifiedReceipt($first, 60000, 100000, 'FT-SPLIT-1');
        $receipt = (int) EnquiryPayment::findOrFail($share)->client_receipt_id;

        $split = collect($this->billing($this->preparer, $first)['split_receipts'])->firstWhere('id', $receipt);
        $this->assertSame('100000.00', $split['received_amount']);
        $this->assertSame('40000.00', $split['unallocated_amount']);
        $this->assertCount(1, $split['shares']);

        // The remainder goes to the other project and enters verification there.
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/receivables/receipts/{$receipt}/allocations", [
            'enquiry_id' => $second->id, 'amount' => 50000,
        ])->assertStatus(422);
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/receivables/receipts/{$receipt}/allocations", [
            'enquiry_id' => $second->id, 'amount' => 40000,
        ])->assertCreated();

        foreach ([$first, $second] as $project) {
            $split = collect($this->billing($this->preparer, $project)['split_receipts'])->firstWhere('id', $receipt);
            $this->assertSame('0.00', $split['unallocated_amount']);
            $this->assertEqualsCanonicalizing([$first->id, $second->id], collect($split['shares'])->pluck('project.id')->all());
            $this->assertSame('Acme Events', $split['shares'][0]['client']['name']);
        }
        $this->assertSame('pending', collect($split['shares'])->firstWhere('project.id', $second->id)['status']);
        $this->assertSame(0, ClientReceipt::whereKey($receipt)->where('received_amount', '!=', 100000)->count());
    }
}
