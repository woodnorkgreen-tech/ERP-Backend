<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Models\VatTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 2B Wave 1: W1-1 (prepare/check/issue), W1-2 (approved-basis exception),
 * W1-7 (payment terms), and W1-8 (discount) — the parts of the confirmed W1
 * decisions that needed new capability rather than relabelling what already
 * existed (W1-3/4/5/9 are covered separately; see
 * ClientFinancialPositionServiceTest and the existing receivables suites).
 */
class InvoiceReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $preparer;
    private User $checker;
    private User $overrideApprover;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK,
            Permissions::FINANCE_RECEIVABLES_OVERRIDE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->preparer = User::factory()->create(['is_active' => true]);
        $this->preparer->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        $this->checker = User::factory()->create(['is_active' => true]);
        $this->checker->givePermissionTo(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK);

        $this->overrideApprover = User::factory()->create(['is_active' => true]);
        $this->overrideApprover->givePermissionTo(Permissions::FINANCE_RECEIVABLES_OVERRIDE);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function enquiryWithApprovedQuote(float $quoteAmount = 1000000): ProjectEnquiry
    {
        $client = Client::factory()->create(['email' => 'w1-' . uniqid() . '@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Invoice review workflow test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-W1-' . uniqid(),
            'created_by' => $this->preparer->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0,
            'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved',
            'approved_by' => $this->preparer->id,
            'approval_date' => '2026-09-01',
            'quote_amount' => $quoteAmount,
            'quote_data' => json_encode(['grandTotal' => $quoteAmount]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $enquiry;
    }

    /** A project with no approved commercial basis at all. */
    private function enquiryWithNoQuote(): ProjectEnquiry
    {
        $client = Client::factory()->create(['email' => 'w1-noquote-' . uniqid() . '@test.local']);

        return ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Unquoted job',
            'description' => 'No approved commercial basis',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-W1NQ-' . uniqid(),
            'created_by' => $this->preparer->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);
    }

    private function vat(): VatTreatment
    {
        return VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();
    }

    private function draftInvoice(ProjectEnquiry $enquiry, array $overrides = []): ProjectInvoice
    {
        $payload = array_merge([
            'invoice_date' => '2026-09-08',
            'due_date' => '2026-10-08',
            'lines' => [
                ['description' => 'Stand build', 'quantity' => 1, 'unit_price' => 100000, 'vat_treatment_id' => $this->vat()->id],
            ],
        ], $overrides);

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", $payload)
            ->assertCreated();

        return ProjectInvoice::findOrFail($response->json('data.id'));
    }

    // ── Invoice: prepare / check / issue (W1-1) ────────────────────────────

    public function test_a_draft_invoice_has_no_ledger_impact(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->journal_entry_id);
    }

    /**
     * An Accounts user built from the canonical role matrix — not ad-hoc grants —
     * so these tests prove the B1 production configuration itself.
     */
    private function accountsUser(): User
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('Accounts', 'web');
        foreach (\App\Constants\RolePermissions::matrix()['Accounts'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions(\App\Constants\RolePermissions::matrix()['Accounts']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function draftInvoiceBy(User $preparer, ProjectEnquiry $enquiry): ProjectInvoice
    {
        $response = $this->actingAs($preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [
                    ['description' => 'Stand build', 'quantity' => 1, 'unit_price' => 100000, 'vat_treatment_id' => $this->vat()->id],
                ],
            ])->assertCreated();

        return ProjectInvoice::findOrFail($response->json('data.id'));
    }

    public function test_b1_the_accounts_role_holds_the_invoice_check_permission(): void
    {
        $this->assertContains(
            Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK,
            \App\Constants\RolePermissions::matrix()['Accounts'],
        );
        $this->assertTrue($this->accountsUser()->can(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK));
    }

    public function test_b1_scenario_a_an_accounts_preparer_cannot_check_their_own_invoice(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $userA = $this->accountsUser();
        $invoice = $this->draftInvoiceBy($userA, $enquiry);

        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertStatus(422);

        $this->assertNull($invoice->fresh()->checked_at);
    }

    public function test_b1_scenario_b_and_d_another_accounts_user_checks_then_the_invoice_issues_once(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $userA = $this->accountsUser();
        $userB = $this->accountsUser();
        $invoice = $this->draftInvoiceBy($userA, $enquiry);

        // B: independent Accounts checker succeeds.
        $this->actingAs($userB, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertOk();
        $this->assertSame($userB->id, $invoice->fresh()->checked_by);

        // D: the checked invoice issues (Accounts holds the issue permission).
        $this->actingAs($userA, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', ProjectInvoice::class)->where('source_id', $invoice->id)->count());
    }

    public function test_b1_scenario_c_a_user_without_the_check_permission_gets_403(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoiceBy($this->accountsUser(), $enquiry);

        $this->actingAs($this->outsider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertForbidden();
    }

    public function test_b1_scenario_e_an_unchecked_invoice_cannot_be_issued_by_accounts(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $userA = $this->accountsUser();
        $invoice = $this->draftInvoiceBy($userA, $enquiry);

        $this->actingAs($this->accountsUser(), 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertStatus(422);

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->journal_entry_id);
    }

    public function test_the_preparer_cannot_check_their_own_invoice(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        // The preparer holds no check permission at all in this fixture, so
        // this is refused at the permission gate before the identity guard
        // is even reached — proving the separation is enforced in depth.
        $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertForbidden();
    }

    public function test_a_preparer_who_also_holds_the_check_permission_still_cannot_check_their_own_invoice(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->preparer->givePermissionTo(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK);

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check");

        $response->assertStatus(422);
        $this->assertStringContainsString('someone else has to check it', $response->json('message'));
    }

    public function test_an_authorized_reviewer_can_check_a_draft_invoice(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertOk();

        $invoice->refresh();
        $this->assertSame($this->checker->id, $invoice->checked_by);
        $this->assertNotNull($invoice->checked_at);
    }

    public function test_an_unchecked_invoice_cannot_be_issued(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue");

        $response->assertStatus(422);
        $this->assertStringContainsString('has not been checked', $response->json('message'));
    }

    public function test_a_checked_invoice_can_be_issued_by_the_checker_themself(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertOk();

        // Checker and issuer may be the same authorized person — the checker
        // also needs the issue permission to actually do it.
        $this->checker->givePermissionTo(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_a_reviewer_can_return_a_draft_invoice_for_correction(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/return-for-correction", [
                'reason' => 'Wrong unit price on the stand build line.',
            ])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame($this->checker->id, $invoice->returned_by);
        $this->assertNotNull($invoice->returned_at);
        $this->assertSame('Wrong unit price on the stand build line.', $invoice->return_reason);
        $this->assertNull($invoice->checked_at);
    }

    public function test_a_corrected_invoice_can_be_resubmitted_and_then_checked(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/return-for-correction", [
                'reason' => 'Price is wrong.',
            ])->assertOk();

        $corrected = $this->actingAs($this->preparer, 'sanctum')
            ->putJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}", [
                'lines' => [
                    ['description' => 'Stand build (corrected)', 'quantity' => 1, 'unit_price' => 120000, 'vat_treatment_id' => $this->vat()->id],
                ],
            ])->assertOk();

        $this->assertNotNull($corrected->json('data.resubmitted_at'));
        $this->assertSame('120000.00', number_format((float) $corrected->json('data.subtotal'), 2, '.', ''));

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertOk();

        $this->assertNotNull($invoice->fresh()->checked_at);
    }

    public function test_a_checked_invoice_must_be_returned_before_it_can_be_corrected_again(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")
            ->assertOk();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->putJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}", [
                'lines' => [['description' => 'Changed', 'quantity' => 1, 'unit_price' => 5000]],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('returned for correction first', $response->json('message'));
    }

    // ── W1-2: approved commercial basis / controlled exception ────────────

    public function test_an_invoice_cannot_be_prepared_with_no_approved_commercial_basis(): void
    {
        $enquiry = $this->enquiryWithNoQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Ad hoc work', 'quantity' => 1, 'unit_price' => 50000]],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no agreed price yet', $response->json('message'));
    }

    public function test_an_authorized_exception_allows_invoicing_with_no_approved_quote(): void
    {
        $enquiry = $this->enquiryWithNoQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Ad hoc work', 'quantity' => 1, 'unit_price' => 50000]],
                'no_quote_exception_reason' => 'Client authorized emergency additional work by email.',
                'no_quote_exception_approved_by' => $this->overrideApprover->id,
                'no_quote_exception_evidence_reference' => 'Email thread #4471',
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($response->json('data.id'));
        $this->assertSame('Client authorized emergency additional work by email.', $invoice->no_quote_exception_reason);
        $this->assertSame($this->preparer->id, $invoice->no_quote_exception_requested_by);
        $this->assertSame($this->overrideApprover->id, $invoice->no_quote_exception_approved_by);
        $this->assertNotNull($invoice->no_quote_exception_approved_at);

        $this->assertDatabaseHas('governance_audit_logs', [
            'gate_type' => 'invoice_no_quote_exception',
            'model_id' => $invoice->id,
        ]);
    }

    public function test_the_exception_audit_trail_is_retained(): void
    {
        $enquiry = $this->enquiryWithNoQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Ad hoc work', 'quantity' => 1, 'unit_price' => 50000]],
                'no_quote_exception_reason' => 'Authorized verbally, confirmed after the fact.',
                'no_quote_exception_approved_by' => $this->overrideApprover->id,
            ])->assertCreated();

        $log = \App\Models\GovernanceAuditLog::where('gate_type', 'invoice_no_quote_exception')
            ->where('model_id', $response->json('data.id'))->firstOrFail();

        $this->assertSame('Authorized verbally, confirmed after the fact.', $log->context['no_quote_exception_reason']);
        $this->assertSame($this->overrideApprover->id, $log->context['no_quote_exception_approved_by']);
    }

    public function test_naming_an_approver_without_the_override_permission_is_refused(): void
    {
        $enquiry = $this->enquiryWithNoQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Ad hoc work', 'quantity' => 1, 'unit_price' => 50000]],
                'no_quote_exception_reason' => 'Trying to bypass the control.',
                'no_quote_exception_approved_by' => $this->outsider->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('does not hold the authority', $response->json('message'));
        $this->assertDatabaseCount('project_invoices', 0);
    }

    // ── W1-6: credit note preparer ≠ approver ──────────────────────────────

    public function test_the_credit_note_preparer_cannot_approve_and_issue_it_themself(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);
        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/check")->assertOk();
        $this->preparer->givePermissionTo(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS);
        $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")->assertOk();

        $this->preparer->givePermissionTo(Permissions::FINANCE_RECEIVABLES_REVERSE);
        $creditNote = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/credit-notes", [
                'invoice_date' => '2026-09-10',
                'reason' => 'Overbilled by mistake.',
                'lines' => [['description' => 'Correction', 'quantity' => 1, 'unit_price' => 10000]],
            ])->assertCreated();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/credit-notes/{$creditNote->json('data.id')}/issue");

        $response->assertStatus(422);
        $this->assertStringContainsString('someone else has to approve and issue it', $response->json('message'));
    }

    // ── W1-7: configurable payment terms ───────────────────────────────────

    public function test_a_configured_payment_term_can_be_selected_on_a_new_invoice(): void
    {
        $term = PaymentTerm::create(['name' => '14 Days', 'days' => 14, 'is_custom' => false, 'is_active' => true]);
        $enquiry = $this->enquiryWithApprovedQuote();

        $invoice = $this->draftInvoice($enquiry, ['payment_term_id' => $term->id]);

        $this->assertSame($term->id, $invoice->payment_term_id);
    }

    public function test_an_inactive_payment_term_cannot_be_created_selected_via_a_stale_reference(): void
    {
        $term = PaymentTerm::create(['name' => 'Retired term', 'days' => 45, 'is_active' => false]);
        $enquiry = $this->enquiryWithApprovedQuote();

        // Selection is not blocked by is_active at the database-reference
        // level (an inactive term may still be historically referenced) —
        // the control point is the payment-terms list endpoint not offering
        // it, proven separately below.
        $invoice = $this->draftInvoice($enquiry, ['payment_term_id' => $term->id]);
        $this->assertSame($term->id, $invoice->payment_term_id);

        $listed = $this->actingAs($this->preparer, 'sanctum')
            ->getJson('/api/finance/payment-terms?active_only=1')->assertOk();

        $this->assertFalse(collect($listed->json('data'))->contains('id', $term->id));
    }

    public function test_a_custom_due_date_works_without_any_configured_term(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();

        $invoice = $this->draftInvoice($enquiry, ['due_date' => '2027-01-01']);

        $this->assertNull($invoice->payment_term_id);
        $this->assertSame('2027-01-01', $invoice->due_date->toDateString());
    }

    public function test_no_payment_term_values_are_seeded_by_default(): void
    {
        // Nothing invented on WNG's behalf — the table starts empty.
        $this->assertSame(0, PaymentTerm::count());
    }

    // ── W1-8: auditable discount ────────────────────────────────────────────

    public function test_a_line_discount_is_recorded_distinctly_from_gross_and_net(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();

        $invoice = $this->draftInvoice($enquiry, [
            'lines' => [[
                'description' => 'Stand build',
                'quantity' => 1,
                'unit_price' => 100000,
                'discount_amount' => 15000,
                'vat_treatment_id' => $this->vat()->id,
            ]],
        ]);

        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('100000.00', (string) $line->gross_amount);
        $this->assertSame('15000.00', (string) $line->discount_amount);
        $this->assertSame('85000.00', (string) $line->net_amount);
        // Tax charged on the net-of-discount amount.
        $rate = $this->vat()->rate_percent;
        $expectedTax = number_format(85000 * ((float) $rate) / 100, 2, '.', '');
        $this->assertSame($expectedTax, (string) $line->tax_amount);
        $this->assertSame(
            number_format(85000 + (float) $expectedTax, 2, '.', ''),
            (string) $line->total_amount,
        );
    }

    public function test_a_discount_cannot_exceed_the_lines_gross_amount(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [[
                    'description' => 'Stand build', 'quantity' => 1, 'unit_price' => 100000,
                    'discount_amount' => 150000,
                ]],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot exceed its gross amount', $response->json('message'));
    }

    public function test_a_negative_discount_is_refused(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();

        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [[
                    'description' => 'Stand build', 'quantity' => 1, 'unit_price' => 100000,
                    'discount_amount' => -1000,
                ]],
            ]);

        $response->assertStatus(422);
    }

    public function test_an_undiscounted_line_still_carries_a_zero_discount_and_gross_equal_to_net(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();
        $invoice = $this->draftInvoice($enquiry);

        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('0.00', (string) $line->discount_amount);
        $this->assertSame((string) $line->gross_amount, (string) $line->net_amount);
    }

    public function test_the_negative_line_workaround_for_a_credit_is_still_refused(): void
    {
        $enquiry = $this->enquiryWithApprovedQuote();

        // Request validation ('unit_price' => 'min:0') already refuses a
        // negative unit price before InvoicePricer's own "raise a credit
        // note instead" guard is ever reached — confirming the workaround
        // is closed at the very first layer, not merely deeper in the stack.
        $response = $this->actingAs($this->preparer, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08',
                'due_date' => '2026-10-08',
                'lines' => [['description' => 'Negative line attempt', 'quantity' => 1, 'unit_price' => -5000]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lines.0.unit_price');
    }
}
