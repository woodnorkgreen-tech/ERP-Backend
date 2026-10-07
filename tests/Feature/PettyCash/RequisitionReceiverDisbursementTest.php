<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Events\PettyCashRequisitionApproved;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\RequisitionPaymentAllocation;
use App\Modules\Finance\PettyCash\Services\PettyCashAdvancePoster;
use App\Modules\Finance\PettyCash\Services\RequisitionDisbursementService;
use App\Modules\Finance\Services\PaymentReversalService;
use App\Modules\Finance\Services\PaymentSettlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceiverRequisitionFixture;
use Tests\TestCase;

/**
 * Report 75R-A: one requisition, many lines, many receivers, as many Payments
 * as it takes — and one parent that always says exactly what its children paid.
 *
 * The genuine two-users-at-once race is in
 * {@see RequisitionReceiverDisbursementConcurrencyTest}; it needs committed data
 * and separate connections, which RefreshDatabase's transaction cannot give.
 */
class RequisitionReceiverDisbursementTest extends TestCase
{
    use RefreshDatabase;
    use ReceiverRequisitionFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFinanceReferenceData();
        $this->createReceiverRequisition();
        $this->actingAs($this->finance, 'sanctum');
    }

    private function refused(callable $attempt, string $field): string
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));

            return $e->errors()[$field][0];
        }
        $this->fail('The payment was accepted.');
    }

    private function url(string $suffix = '/disburse'): string
    {
        return '/api/finance/petty-cash/requisitions/'.$this->requisition->id.$suffix;
    }

    // ── Receivers and allocations ────────────────────────────────────────────

    public function test_lines_are_projected_to_three_distinct_receivers_that_reconcile_to_the_parent(): void
    {
        $controls = $this->controls();

        $this->assertSame(['employee', 'other', 'supplier'], array_column($controls['receivers'], 'receiver_type'));
        $this->assertSame(['20000.00', '30000.00', '25000.00'], array_column($controls['receivers'], 'approved'));
        $this->assertSame(['Site facilitation', 'Casual support'], array_column($this->receiver('Steve')['lines'], 'purpose'));
        $this->assertSame('75000.00', array_reduce($controls['receivers'], fn ($sum, $r) => bcadd($sum, $r['approved'], 2), '0.00'));
        $this->assertSame('75000.00', $controls['approved']);
        $this->assertSame('not_disbursed', $controls['disbursement_status']);
        $this->assertTrue($controls['can_process_payment']);
    }

    public function test_one_receiver_one_payment(): void
    {
        $lines = $this->lines();
        $payment = $this->payReceiver([$lines['Materials']], '25000');

        $this->assertSame($this->requisition->requisition_number.'-D01', $payment->requisition_child_reference);
        $this->assertStringStartsWith('PAY-', $payment->payment_no);
        $this->assertSame($this->requisition->id, $payment->requisition_id);
        $this->assertSame('supplier', $payment->payee_type);
        $this->assertSame('fully_disbursed', $this->receiver('Winnie')['payment_allocation_status']);
        $this->assertSame('partially_disbursed', $this->controls()['disbursement_status']);
    }

    public function test_three_receivers_three_payments_fully_disburse_the_parent(): void
    {
        $lines = $this->lines();
        $d1 = $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $d2 = $this->payReceiver([$lines['Transport']], '30000');
        $d3 = $this->payReceiver([$lines['Materials']], '25000');

        $number = $this->requisition->requisition_number;
        $this->assertSame(["$number-D01", "$number-D02", "$number-D03"],
            [$d1->requisition_child_reference, $d2->requisition_child_reference, $d3->requisition_child_reference]);

        $controls = $this->controls();
        $this->assertSame('75000.00', $controls['disbursed']);
        $this->assertSame('0.00', $controls['outstanding']);
        $this->assertSame('fully_disbursed', $controls['disbursement_status']);
        foreach ($controls['receivers'] as $receiver) {
            $this->assertSame('fully_disbursed', $receiver['payment_allocation_status']);
            $this->assertSame('0.00', $receiver['outstanding']);
            $this->assertFalse($receiver['can_pay']);
        }

        // The parent is retained everywhere: on every Payment and every allocation.
        $this->assertSame(3, Payment::where('requisition_id', $this->requisition->id)->count());
        $this->assertSame(4, RequisitionPaymentAllocation::where('requisition_id', $this->requisition->id)->count());
        $this->assertSame(0, RequisitionPaymentAllocation::whereNull('requisition_id')->count());
        $this->assertSame(3, Payment::whereNotNull('requisition_child_reference')->distinct()->count('requisition_child_reference'));
    }

    public function test_one_grouped_payment_keeps_each_line_purpose(): void
    {
        $lines = $this->lines();
        $payment = $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');

        $this->assertSame(1, Payment::count());
        $this->assertSame('20000.00', (string) $payment->amount);
        $this->assertEquals(
            [$lines['Site facilitation'] => '15000.00', $lines['Casual support'] => '5000.00'],
            $payment->requisitionAllocations->pluck('allocated_amount', 'requisition_item_id')->all(),
        );
        $row = collect($this->controls()['payments'])->firstWhere('id', $payment->id);
        $this->assertSame(['Site facilitation', 'Casual support'], collect($row['allocations'])->pluck('purpose')->all());
    }

    public function test_one_allocation_paid_in_two_instalments(): void
    {
        $transport = $this->lines()['Transport'];

        $this->payReceiver([$transport], '20000');
        $timothy = $this->receiver('Timothy');
        $this->assertSame('partially_disbursed', $timothy['payment_allocation_status']);
        $this->assertSame('20000.00', $timothy['paid']);
        $this->assertSame('10000.00', $timothy['outstanding']);
        $this->assertSame('partially_disbursed', $this->controls()['disbursement_status']);

        $this->payReceiver([$transport], '10000');
        $timothy = $this->receiver('Timothy');
        $this->assertSame('fully_disbursed', $timothy['payment_allocation_status']);
        $this->assertSame('0.00', $timothy['outstanding']);

        $payments = collect($this->controls()['payments']);
        $this->assertSame([1, 2], $payments->pluck('instalment')->all());
        $this->assertTrue($payments->every(fn ($p) => $p['is_instalment']));
        $this->assertSame(2, RequisitionPaymentAllocation::where('requisition_item_id', $transport)->count());
    }

    public function test_one_payment_cannot_mix_receivers_and_moves_no_cash(): void
    {
        $lines = $this->lines();
        $message = $this->refused(fn () => $this->payReceiver([$lines['Site facilitation'], $lines['Transport']], '10000'), 'item_ids');

        $this->assertStringContainsString('one receiver', $message);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('requisition_payment_allocations', 0);
        $this->assertSame(0, (int) $this->requisition->fresh()->disbursement_sequence);
    }

    public function test_the_same_typed_name_under_two_references_is_two_receivers(): void
    {
        $this->requisition->items()->where('description', 'Materials')
            ->update(['supplier_id' => null, 'payee_name' => 'Timothy Mwangi', 'other_recipient_reference' => 'ID-99887766']);

        $receivers = collect($this->controls()['receivers'])->where('receiver_type', 'other');
        $this->assertCount(2, $receivers);
        $this->assertSame(['30000.00', '25000.00'], $receivers->pluck('requested')->values()->all());
    }

    // ── Overpayment and idempotency ──────────────────────────────────────────

    public function test_payment_above_the_outstanding_allocation_is_refused(): void
    {
        $transport = $this->lines()['Transport'];
        $this->refused(fn () => $this->payReceiver([$transport], '30000.01'), 'amount');

        $this->payReceiver([$transport], '20000');
        $this->payReceiver([$transport], '10000');
        $message = $this->refused(fn () => $this->payReceiver([$transport], '10000'), 'amount');

        $this->assertStringContainsString('0.00 still outstanding', $message);
        $this->assertSame('30000.00', $this->receiver('Timothy')['paid']);
        $this->assertSame(2, Payment::count());
    }

    public function test_a_replayed_request_returns_the_same_payment_and_changed_instructions_are_refused(): void
    {
        $service = app(RequisitionDisbursementService::class);
        $instructions = $this->instructions([$this->lines()['Site facilitation']], '10000');

        $first = $service->pay($this->requisition->id, $this->finance, $instructions);
        $replay = $service->pay($this->requisition->id, $this->finance, $instructions);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('requisition_payment_allocations', 1);
        $this->assertSame(1, JournalEntry::where('source_type', Payment::class)->where('source_id', $first->id)->count());
        $this->assertSame(1, (int) $this->requisition->fresh()->disbursement_sequence);

        $instructions['amount'] = '5000';
        $this->refused(fn () => $service->pay($this->requisition->id, $this->finance, $instructions), 'idempotency_key');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_double_click_through_the_api_creates_one_payment(): void
    {
        $instructions = $this->instructions([$this->lines()['Materials']], '25000');

        $first = $this->postJson($this->url(), $instructions)->assertOk();
        $second = $this->postJson($this->url(), $instructions)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('25000.00', $second->json('controls.disbursed'));
    }

    public function test_the_payment_goes_through_the_existing_settlement_service(): void
    {
        $spy = $this->spy(PaymentSettlementService::class);
        $spy->shouldReceive('settle')->once()->andReturnUsing(fn (array $attributes) => Payment::create([...$attributes, 'status' => 'active']));

        $this->payReceiver([$this->lines()['Materials']], '25000');

        $spy->shouldHaveReceived('settle')->once();
    }

    // ── Control gates ────────────────────────────────────────────────────────

    public function test_payment_needs_payment_authority_which_verifying_creating_and_approving_do_not_give(): void
    {
        $instructions = $this->instructions([$this->lines()['Materials']], '25000');

        foreach ([$this->verifier, $this->creator, $this->approver] as $user) {
            $this->actingAs($user, 'sanctum')->postJson($this->url(), $instructions)->assertForbidden();
            try {
                app(RequisitionDisbursementService::class)->pay($this->requisition->id, $user, $instructions);
                $this->fail("{$user->name} was allowed to pay.");
            } catch (AuthorizationException) {
                // expected
            }
        }
        $this->assertDatabaseCount('payments', 0);

        $this->actingAs($this->finance, 'sanctum')->postJson($this->url(), $instructions)->assertOk();
    }

    public function test_the_creator_cannot_pay_their_own_requisition_even_with_payment_authority(): void
    {
        $this->creator->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);

        $this->expectException(AuthorizationException::class);
        $this->payReceiver([$this->lines()['Materials']], '25000', $this->creator->fresh());
    }

    public function test_a_material_edit_revokes_verification_and_blocks_payment(): void
    {
        $this->requisition->items()->where('description', 'Materials')->first()->update(['amount' => '26000.00']);

        $requisition = $this->requisition->fresh();
        $this->assertSame('re_verification_required', $requisition->verification_status);
        $this->assertSame('pending', $requisition->status, 'A material change also withdraws the approval.');

        $this->postJson($this->url(), $this->instructions([$this->lines()['Materials']], '25000'))->assertStatus(400);
        $this->refused(fn () => $this->payReceiver([$this->lines()['Materials']], '25000'), 'requisition_id');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_verification_is_still_required_at_the_moment_of_payment(): void
    {
        // Approved, but the verified fingerprint no longer matches what is on file.
        DB::table('petty_cash_requisition_items')->where('requisition_id', $this->requisition->id)
            ->where('description', 'Materials')->update(['remarks' => 'changed behind the model']);

        $this->refused(fn () => $this->payReceiver([$this->lines()['Materials']], '25000'), 'verification');
        $this->assertFalse($this->controls()['can_process_payment']);
    }

    public function test_approval_is_still_required(): void
    {
        $this->requisition->forceFill(['status' => 'pending', 'approved_at' => null, 'approved_by' => null])->saveQuietly();

        $this->refused(fn () => $this->payReceiver([$this->lines()['Materials']], '25000'), 'requisition_id');
        $this->assertSame('0.00', $this->controls()['approved']);
        $this->assertSame('Awaiting Finance approval.', $this->controls()['payment_block']);
    }

    public function test_a_line_with_only_a_typed_name_is_not_payable_by_receiver(): void
    {
        DB::table('petty_cash_requisition_items')->where('requisition_id', $this->requisition->id)
            ->where('description', 'Transport')->update(['other_recipient_reference' => null]);
        // Re-verify the changed details, as the workflow would.
        $this->requisition->forceFill(['verification_fingerprint' => app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class)
            ->fingerprint($this->requisition)])->saveQuietly();

        $this->refused(fn () => $this->payReceiver([$this->lines()['Materials']], '25000'), 'items');
        $this->assertSame('unresolved', collect($this->controls()['receivers'])->firstWhere('name', 'Timothy Mwangi')['receiver_type']);
        $this->assertFalse($this->controls()['can_process_payment']);
    }

    public function test_submission_refuses_ambiguous_receivers(): void
    {
        $identity = app(\App\Modules\Finance\PettyCash\Services\RequisitionReceiverIdentity::class);
        try {
            $identity->validateSubmittedLines([
                ['description' => 'A', 'amount' => 10, 'payee_id' => 1, 'supplier_id' => 2],
                ['description' => 'B', 'amount' => 10, 'other_recipient_reference' => 'ID-1'],
                ['description' => 'C', 'amount' => 10, 'other_recipient_reference' => 'ID-2', 'payee_name' => 'Asha'],
                ['description' => 'D', 'amount' => 10, 'other_recipient_reference' => 'id-2', 'payee_name' => 'Brian'],
                ['description' => 'E', 'amount' => 10, 'payee_name' => 'Typed only'],
            ], null);
            $this->fail('Ambiguous receivers were accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['items.0.payee_name', 'items.1.payee_name', 'items.3.other_recipient_reference', 'items.4.payee_name'],
                array_keys($e->errors()));
        }

        // Two typed names are two receivers nobody can tell apart or pay separately.
        try {
            $identity->validateSubmittedLines([
                ['description' => 'A', 'amount' => 10, 'payee_name' => 'Asha'],
                ['description' => 'B', 'amount' => 10, 'payee_name' => 'Brian'],
            ], null);
            $this->fail('Two typed names were accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['items.0.payee_name', 'items.1.payee_name'], array_keys($e->errors()));
        }

        // One typed payee for the whole requisition remains the single-payment form.
        $identity->validateSubmittedLines([
            ['description' => 'A', 'amount' => 10, 'payee_name' => 'Asha'],
            ['description' => 'B', 'amount' => 10, 'payee_name' => 'asha '],
            ['description' => 'C', 'amount' => 10],
        ], null, 'Asha');
        $this->addToAssertionCount(1);
    }

    public function test_the_api_stores_supplier_and_referenced_recipient_lines(): void
    {
        $supplierId = (int) DB::table('suppliers')->value('id');
        $employeeId = (int) DB::table('employees')->value('id');
        $type = PettyCashRequisitionType::create(['code' => 'group-75ra', 'name' => 'Group payment', 'recipient_mode' => 'per_item',
            'is_active' => true, 'requires_project' => false, 'request_fields' => [], 'item_fields' => []]);
        $payload = [
            'responsible_verifier_id' => $this->verifier->id, 'department_id' => $this->departmentId,
            'category' => $type->name, 'requisition_type_id' => $type->id, 'purpose' => 'Second event',
            'items' => [
                ['description' => 'Facilitation', 'amount' => '1000.00', 'payee_id' => $employeeId],
                ['description' => 'Hire', 'amount' => '2000.00', 'supplier_id' => $supplierId],
                ['description' => 'Transport', 'amount' => '3000.00', 'payee_name' => 'Timothy Mwangi', 'other_recipient_reference' => 'ID-22334455'],
            ],
        ];

        $response = $this->actingAs($this->creator, 'sanctum')->postJson('/api/finance/petty-cash/requisitions', $payload)->assertCreated();
        $created = PettyCashRequisition::findOrFail($response->json('data.id'));
        $this->assertSame([$employeeId, null, null], $created->items->pluck('payee_id')->all());
        $this->assertSame([null, $supplierId, null], $created->items->pluck('supplier_id')->all());
        $this->assertSame('ID-22334455', $created->items[2]->other_recipient_reference);
        $this->assertSame('Winnie Supplies', $created->items[1]->payee_name, 'A supplier line carries the registered name.');
        $this->assertSame('6000.00', (string) $created->total_amount);

        $payload['items'][2]['other_recipient_reference'] = null;
        $this->postJson('/api/finance/petty-cash/requisitions', $payload)->assertStatus(422)->assertJsonValidationErrors('items.2.payee_name');
    }

    // ── Reversal ─────────────────────────────────────────────────────────────

    public function test_reversal_reduces_active_paid_and_keeps_the_history(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $timothy = $this->payReceiver([$lines['Transport']], '30000');
        $this->payReceiver([$lines['Materials']], '25000');
        $this->assertSame('fully_disbursed', $this->controls()['disbursement_status']);
        $entry = JournalEntry::findOrFail($timothy->advance_journal_entry_id);

        app(PaymentReversalService::class)->reverse($timothy, $this->approver->id, 'Sent to the wrong account');

        $controls = $this->controls();
        $this->assertSame('45000.00', $controls['disbursed']);
        $this->assertSame('30000.00', $controls['outstanding']);
        $this->assertSame('partially_disbursed', $controls['disbursement_status']);
        $this->assertSame('30000.00', $this->receiver('Timothy')['outstanding']);
        $this->assertSame('not_disbursed', $this->receiver('Timothy')['payment_allocation_status']);

        // Nothing is deleted: the Payment, its allocation and its journal remain.
        $this->assertSame('voided', $timothy->fresh()->status);
        $this->assertSame(1, $timothy->requisitionAllocations()->count());
        $this->assertSame('reversed', $entry->fresh()->status);
        $row = collect($controls['payments'])->firstWhere('id', $timothy->id);
        $this->assertSame('reversed', $row['posting']['state']);
        $this->assertSame('Sent to the wrong account', $row['reversal']['reason']);
        $this->assertSame('disbursed', $this->requisition->fresh()->status);

        // The replacement takes the next child reference; D02 is never reused.
        $replacement = $this->payReceiver([$lines['Transport']], '30000');
        $this->assertStringEndsWith('-D04', $replacement->requisition_child_reference);
        $this->assertSame('fully_disbursed', $this->controls()['disbursement_status']);
    }

    public function test_reversing_the_only_payment_returns_the_parent_to_approved(): void
    {
        $payment = $this->payReceiver([$this->lines()['Materials']], '25000');
        $this->assertSame('disbursed', $this->requisition->fresh()->status);

        app(PaymentReversalService::class)->reverse($payment, $this->approver->id, 'Duplicate transfer at the bank');

        $this->assertSame('approved', $this->requisition->fresh()->status);
        $this->assertSame('not_disbursed', $this->controls()['disbursement_status']);
        $this->assertTrue($this->controls()['can_process_payment']);
    }

    public function test_lines_with_payment_history_cannot_be_rewritten(): void
    {
        $payment = $this->payReceiver([$this->lines()['Materials']], '25000');
        app(PaymentReversalService::class)->reverse($payment, $this->approver->id, 'Duplicate transfer at the bank');

        $this->assertTrue($this->requisition->fresh()->hasPaymentHistory());
        // Even someone who may edit an approved requisition is refused.
        $this->actingAs($this->approver, 'sanctum')->putJson('/api/finance/petty-cash/requisitions/'.$this->requisition->id, [])
            ->assertStatus(409);
    }

    // ── Ledger ───────────────────────────────────────────────────────────────

    public function test_each_payment_posts_its_own_journal_once_and_the_parent_carries_none(): void
    {
        $lines = $this->lines();
        $first = $this->payReceiver([$lines['Transport']], '20000');
        $second = $this->payReceiver([$lines['Transport']], '10000');

        $this->assertNotNull($first->advance_journal_entry_id);
        $this->assertNotNull($second->advance_journal_entry_id);
        $this->assertNotSame($first->advance_journal_entry_id, $second->advance_journal_entry_id);
        $this->assertNull($this->requisition->fresh()->advance_journal_entry_id);

        $before = JournalEntry::count();
        app(PettyCashAdvancePoster::class)->attemptPayment($first);
        app(PettyCashAdvancePoster::class)->attemptPayment($second);
        $this->postJson($this->url('/retry-advance-posting'))->assertForbidden();
        $this->actingAs($this->approver, 'sanctum')->postJson($this->url('/retry-advance-posting'))->assertOk();
        $this->assertSame($before, JournalEntry::count());

        $posted = DB::table('journal_lines')->whereIn('journal_entry_id', [$first->advance_journal_entry_id, $second->advance_journal_entry_id])
            ->where('entry_type', 'debit')->sum('amount');
        $this->assertSame('30000.00', number_format((float) $posted, 2, '.', ''));
        $this->assertSame(['posted', 'posted'], collect($this->controls()['payments'])->pluck('posting.state')->all());
    }

    public function test_a_posting_failure_is_recorded_on_its_payment_and_the_retry_is_safe(): void
    {
        $lines = $this->lines();
        $good = $this->payReceiver([$lines['Materials']], '25000');

        ChartOfAccount::where('code', '1300')->update(['is_postable' => false]);
        $failed = $this->payReceiver([$lines['Transport']], '5000');

        // The cash moved and is counted; only its journal is outstanding.
        $this->assertSame('active', $failed->status);
        $this->assertNotNull($failed->advance_gl_posting_failed_at);
        $this->assertNull($failed->advance_journal_entry_id);
        $this->assertNull($good->fresh()->advance_gl_posting_failed_at);
        $this->assertSame('30000.00', $this->controls()['disbursed']);
        $this->assertSame(1, $this->controls()['posting']['failed']);
        $this->actingAs($this->approver, 'sanctum')->postJson($this->url('/retry-advance-posting'))->assertStatus(422);

        ChartOfAccount::where('code', '1300')->update(['is_postable' => true]);
        $this->postJson($this->url('/retry-advance-posting'))->assertOk();
        $this->postJson($this->url('/retry-advance-posting'))->assertOk();

        $failed->refresh();
        $this->assertNull($failed->advance_gl_posting_failed_at);
        $this->assertNull($failed->advance_gl_posting_error);
        $this->assertNotNull($failed->advance_journal_entry_id);
        $this->assertSame(1, JournalEntry::where('source_type', Payment::class)->where('source_id', $failed->id)->count());
        $this->assertSame(1, JournalEntry::where('source_type', Payment::class)->where('source_id', $good->id)->count());
    }

    // ── Project cost ─────────────────────────────────────────────────────────

    public function test_several_payments_create_no_actual_cost_and_leave_the_commitment_standing(): void
    {
        $requisition = $this->projectRequisition();
        $this->assertSame('committed', app(PettyCashCostProducer::class)->commitFor($requisition));
        $openCommitments = fn () => CostLine::where('source_type', PettyCashRequisition::class)->where('source_id', $requisition->id)
            ->where('nature', CostLine::NATURE_COMMITTED)->where('status', CostLine::STATUS_VERIFIED)->count();
        $lines = $this->lines();

        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->payReceiver([$lines['Transport']], '20000');
        $this->assertSame(1, $openCommitments(), 'A part-paid requisition is still a promise in full.');
        $this->assertSame('75000.00', (string) CostLine::firstOrFail()->net_amount);

        $this->payReceiver([$lines['Transport']], '10000');
        $this->payReceiver([$lines['Materials']], '25000');
        // Report 75R-B: paid in full is still not a cost. The commitment stands
        // until the money is accounted for, returned or released.
        $this->assertSame(1, $openCommitments());

        // Four transfers, and the one economic cost is still to come (the surrender):
        // no payment became a project actual, and no second commitment appeared.
        $this->assertSame(1, CostLine::count());
        $this->assertSame(0, CostLine::where('nature', CostLine::NATURE_ACTUAL)->count());
        $this->assertSame(0, CostLine::where('source_type', Payment::class)->count());
    }

    // ── Accountability and receipt ───────────────────────────────────────────

    public function test_the_whole_requisition_surrender_and_receipt_refuse_a_receiver_paid_requisition(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Materials']], '25000');

        // Report 75R-B: each receiver confirms and accounts for their own money
        // (RequisitionReceiverAccountabilityTest). The one-payment surrender and
        // the one-signature receipt must not be used around that.
        $this->actingAs($this->creator, 'sanctum')->postJson($this->url('/surrender'), [])
            ->assertStatus(422)->assertJsonPath('errors.surrender.0', fn ($message) => str_contains($message, 'paid to its receivers separately'));
        $this->postJson($this->url('/confirm-receipt'), ['signature' => 'data:signed'])->assertStatus(409);
        $this->postJson($this->url('/items/'.$lines['Materials'].'/confirm-receipt'), ['signature' => 'data:signed'])->assertStatus(409);
        $this->assertSame('disbursed', $this->requisition->fresh()->status);
        $this->assertDatabaseCount('petty_cash_surrender_items', 0);
        $this->assertSame([], $this->controls()['software_gaps']);

        // The funding record a surrender clears: receiver, Payment and paying account, per line.
        $allocation = RequisitionPaymentAllocation::with('payment.paymentSource')->firstOrFail();
        $this->assertSame('supplier', $allocation->receiver_type);
        $this->assertSame($this->paymentSourceId, $allocation->payment->paymentSource->id);
        $this->assertSame($this->requisition->id, $allocation->requisition_id);
    }

    // ── Story and reporting ──────────────────────────────────────────────────

    public function test_the_story_reads_as_a_person_would_tell_it(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->payReceiver([$lines['Transport']], '20000');

        $story = collect($this->controls()['story']);
        // Report 75R-B adds each paid receiver's position after the payments.
        $this->assertSame(['created', 'verified', 'approved', 'paid', 'paid', 'receiver_position', 'receiver_position', 'awaiting', 'overall'], $story->pluck('kind')->all());
        $this->assertSame('Rita Creator', $story[0]['actor']);
        $this->assertSame('Winnie Verifier', $story[1]['actor']);
        $this->assertSame('Finance Approver', $story[2]['actor']);
        $this->assertSame('D01', $story[3]['title']);
        $this->assertSame('Steve Otieno — KES 20,000.00 paid', $story[3]['detail']);
        $this->assertSame('Finance Cashier', $story[3]['actor']);
        $this->assertStringStartsWith('PAY-', $story[3]['reference']);
        $this->assertSame('Timothy Mwangi — KES 20,000.00 part payment. Timothy Mwangi outstanding: KES 10,000.00', $story[4]['detail']);
        $this->assertSame('Awaiting receipt confirmation — KES 20,000.00', $story[5]['detail']);
        $this->assertStringStartsWith('Winnie Supplies', $story[7]['detail']);
        $this->assertSame('KES 40,000.00 / 75,000.00 partially disbursed', $story[8]['detail']);

        $event = GovernanceAuditLog::where('gate_type', 'requisition_receiver_payment')->latest('id')->firstOrFail();
        $this->assertTrue($event->context['part_payment']);
        $this->assertSame('10000.00', $event->context['receiver_outstanding_after']);
    }

    public function test_search_and_reporting_queries(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $first = $this->payReceiver([$lines['Transport']], '20000');
        $this->payReceiver([$lines['Transport']], '10000');
        app(PaymentReversalService::class)->reverse($first, $this->approver->id, 'Sent to the wrong account');

        $list = fn (array $query) => $this->getJson('/api/finance/petty-cash/requisitions?'.http_build_query($query))->assertOk()->json('data');
        $this->assertCount(1, $list(['disbursement_status' => 'partially_disbursed']));
        $this->assertCount(0, $list(['disbursement_status' => 'fully_disbursed']));
        $this->assertCount(1, $list(['search' => $first->requisition_child_reference]));
        $this->assertCount(1, $list(['search' => $first->payment_no]));
        $this->assertCount(1, $list(['has_reversed_payments' => 1]));
        $this->assertCount(1, $list(['responsible_verifier_id' => $this->verifier->id]));
        $this->assertCount(1, $list(['user_id' => $this->creator->id]));

        $payments = fn (array $query) => $this->getJson('/api/finance/petty-cash/finance/requisition-payments?'.http_build_query($query))->assertOk()->json('data');
        $this->assertCount(3, $payments(['requisition_id' => $this->requisition->id]));
        $this->assertCount(1, $payments(['status' => 'reversed']));
        $this->assertCount(2, $payments(['instalments' => 1]));
        $this->assertCount(1, $payments(['receiver_type' => 'employee']));
        $this->assertCount(1, $payments(['search' => $first->requisition_child_reference]));
        $this->assertCount(3, $payments(['verifier_id' => $this->verifier->id, 'requester_id' => $this->creator->id]));
        $this->assertSame(['Site facilitation', 'Casual support'], array_column($payments(['receiver_type' => 'employee'])[0]['lines'], 'purpose'));

        $balances = $this->getJson('/api/finance/petty-cash/finance/receiver-balances')->assertOk()->json('data');
        $this->assertCount(1, $balances);
        $this->assertSame('30000.00', $balances[0]['disbursed']);
        $this->assertSame('45000.00', $balances[0]['outstanding']);
        $this->assertSame(['0.00', '20000.00', '25000.00'], array_column($balances[0]['receivers'], 'outstanding'));

        $this->actingAs($this->creator, 'sanctum')->getJson('/api/finance/petty-cash/finance/receiver-balances')->assertForbidden();
    }
}
