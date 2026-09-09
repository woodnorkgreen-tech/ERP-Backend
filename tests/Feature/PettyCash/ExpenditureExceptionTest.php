<?php

namespace Tests\Feature\PettyCash;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Approving a fund requisition that takes a project past its budget.
 *
 * Two things are under test and they are not the same thing.
 *
 * The first is whether the block is telling the truth. ExpenditureLimitPolicy
 * used to answer from `budget_summary.grandTotal` and a hand-rolled sum of
 * purchase orders — so an approved petty cash requisition, which has been a
 * `committed` cost line since RecordPettyCashCommitment shipped, counted for
 * nothing. A job could be walked past its budget one requisition at a time,
 * each approval blind to the last. `test_an_earlier_requisition_counts_against_the_next`
 * is that hole.
 *
 * The second is what happens once the figures are right. A hard block over a
 * number no one can override is not a control — the budget JSON is editable by
 * anyone who can open the budget task, so refusing outright routes people to
 * that door instead, and the overrun leaves no trace at all. The exception path
 * makes the expensive route the recorded one.
 */
class ExpenditureExceptionTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private ProjectEnquiry $enquiry;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_PETTY_CASH_UPDATE,
            Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE,
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->requester = User::factory()->create(['is_active' => true]);
        $this->requester->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);

        $this->approver = User::factory()->create(['is_active' => true]);
        $this->approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);

        $client = Client::create([
            'full_name' => 'Budget Test Client', 'email' => 'budget@example.test', 'phone' => '0700000001',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'referral', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
        ]);

        $this->enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => 'Budgeted job',
            'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-BUD-001',
            'job_number' => 'WNG-09-2026-001',
            'status' => EnquiryConstants::STATUS_PLANNING,
            'created_by' => $this->requester->id,
        ]);
    }

    /**
     * Figures read back through a JSON column or an HTTP body are compared by
     * value, not identity: MySQL's JSON serializer prints a whole float without
     * its fractional part, so 1650.0 decodes as int 1650 and assertSame fails on
     * a figure that is exactly right.
     */

    /**
     * Only the tests where the approval actually goes through need this.
     *
     * Approving dispatches RecordPettyCashCommitment, and the sync queue runs it
     * inline — so the cost producer posts within the request, and it refuses to
     * post into an accounting period that does not exist. Four seeders in this
     * order, because ExpenseCodeSeeder only activates a code that resolves a
     * postable account. Kept out of setUp() because it is the most expensive
     * thing in the class and a blocked approval never reaches the producer.
     */
    private function seedFinanceCatalogue(): void
    {
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);
    }

    /** The approved budget, as the cost account holds it. */
    private function budget(string $amount): CostLine
    {
        return $this->costLine(CostLine::NATURE_PLANNED, $amount);
    }

    private function costLine(string $nature, string $amount, array $overrides = []): CostLine
    {
        $line = CostLine::create([
            'ref' => 'TMP-' . uniqid(),
            'project_enquiry_id' => $this->enquiry->id,
            'job_number' => $this->enquiry->job_number,
            'nature' => $nature,
            'status' => CostLine::STATUS_VERIFIED,
            'currency' => 'KES',
            'fx_rate' => 1,
            'amount' => $amount,
            'net_amount' => $amount,
            'base_net_amount' => $amount,
            'verified_at' => now(),
            'incurred_at' => now(),
            ...$overrides,
        ]);

        return $line->forceFill(['ref' => 'CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT)]);
    }

    private function requisition(string $amount): PettyCashRequisition
    {
        return PettyCashRequisition::create([
            'requisition_number' => 'REQ-' . uniqid(),
            'user_id' => $this->requester->id,
            'department_id' => \App\Modules\HR\Models\Department::firstOrCreate(['name' => 'Production'])->id,
            'category' => 'project',
            'purpose' => 'Site materials',
            'total_amount' => $amount,
            'status' => 'pending',
            'enquiry_id' => $this->enquiry->id,
            'requester_name' => 'Requester',
        ]);
    }

    private function approve(PettyCashRequisition $requisition, array $payload = [])
    {
        return $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/approve", $payload);
    }

    /**
     * The hole the rewrite closes.
     *
     * Budget 10,000; one requisition already approved for 7,000, sitting on the
     * job as a committed cost line. The next 4,000 must be refused. The old
     * policy summed only purchase orders and procurement requisitions, saw
     * nothing, and let it through — and would have let the one after that
     * through too.
     */
    public function test_an_earlier_requisition_counts_against_the_next(): void
    {
        $this->budget('10000.00');
        $this->costLine(CostLine::NATURE_COMMITTED, '7000.00', [
            'source_type' => PettyCashRequisition::class,
            'source_id' => 999999,
        ]);

        $response = $this->approve($this->requisition('4000.00'));

        $response->assertStatus(422)->assertJsonPath('code', 'PROJECT_BUDGET_NOT_READY');
        $this->assertEqualsWithDelta(10000, $response->json('context.budget'), 0.001);
        $this->assertEqualsWithDelta(7000, $response->json('context.current_commitment'), 0.001);
        $this->assertEqualsWithDelta(1000, $response->json('context.overage'), 0.001);
        $this->assertSame('cost_ledger', $response->json('context.budget_source'));
    }

    /** A released commitment is reversed, not deleted, so it must stop counting. */
    public function test_a_released_commitment_is_not_exposure(): void
    {
        $this->seedFinanceCatalogue();
        $this->budget('10000.00');
        $this->costLine(CostLine::NATURE_COMMITTED, '7000.00', ['status' => CostLine::STATUS_REVERSED]);

        $this->approve($this->requisition('4000.00'))->assertOk();
    }

    /**
     * A requisition edited after approval goes back to pending, which releases
     * its commitment — asynchronously. Re-approving before that queue drains must
     * not weigh the requisition against its own outstanding promise.
     */
    public function test_a_requisition_is_not_blocked_by_its_own_open_commitment(): void
    {
        $this->seedFinanceCatalogue();
        $this->budget('10000.00');
        $requisition = $this->requisition('9000.00');

        $this->costLine(CostLine::NATURE_COMMITTED, '9000.00', [
            'source_type' => PettyCashRequisition::class,
            'source_id' => $requisition->id,
        ]);

        $this->approve($requisition)->assertOk();
    }

    /**
     * The refusal comes back with the figures and with whether this person could
     * authorise an exception — the screen needs the second to know whether to
     * offer the door at all, rather than showing everyone a button that 403s.
     */
    public function test_a_block_says_whether_an_exception_is_available(): void
    {
        $this->budget('1650.00');

        $this->approve($this->requisition('4999.99'))
            ->assertStatus(422)
            ->assertJsonPath('can_authorize_exception', false);

        $this->approver->givePermissionTo(Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE);
        $this->approver->forgetCachedPermissions();

        $this->approve($this->requisition('4999.99'))
            ->assertStatus(422)
            ->assertJsonPath('can_authorize_exception', true);
    }

    /** Approving the requisition and carrying an overrun are separate authorities. */
    public function test_a_reason_alone_does_not_authorize_the_overrun(): void
    {
        $this->budget('1650.00');

        $this->approve($this->requisition('4999.99'), [
            'budget_exception_reason' => 'Client brought the install date forward by two weeks.',
            'budget_exception_funding_source' => 'Contingency',
        ])->assertStatus(403)->assertJsonPath('code', 'EXPENDITURE_EXCEPTION_FORBIDDEN');

        $this->assertDatabaseMissing('petty_cash_requisitions', ['status' => 'approved']);
    }

    /** The justification is the record, so an empty one is not an exception. */
    public function test_the_justification_is_not_optional(): void
    {
        $this->budget('1650.00');
        $this->approver->givePermissionTo(Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE);
        $this->approver->forgetCachedPermissions();

        $this->approve($this->requisition('4999.99'), [
            'budget_exception_reason' => 'urgent',
            'budget_exception_funding_source' => 'Contingency',
        ])->assertStatus(422)->assertJsonValidationErrors(['budget_exception_reason']);
    }

    /**
     * The whole point, end to end: the overrun goes through, and it goes through
     * recorded — in the governance log, on the requisition, and on the money.
     */
    public function test_an_authorized_exception_approves_and_records_the_overrun(): void
    {
        $this->seedFinanceCatalogue();
        $this->budget('1650.00');
        $this->costLine(CostLine::NATURE_COMMITTED, '7000.00');

        $this->approver->givePermissionTo(Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE);
        $this->approver->forgetCachedPermissions();

        $requisition = $this->requisition('4999.99');

        $this->approve($requisition, [
            'budget_exception_reason' => 'Client moved the install date forward; stand cannot ship without it.',
            'budget_exception_funding_source' => 'Q3 contingency, approved by the MD',
        ])->assertOk();

        $requisition->refresh();
        $this->assertSame('approved', $requisition->status);

        // The requisition's own copy, for the screen and for the producer.
        $exception = $requisition->budget_exception;
        $this->assertEqualsWithDelta(1650, $exception['budget'], 0.001);
        $this->assertEqualsWithDelta(7000, $exception['exposure_before'], 0.001);
        $this->assertEqualsWithDelta(4999.99, $exception['requested'], 0.001);
        $this->assertEqualsWithDelta(10349.99, $exception['overage'], 0.001);
        $this->assertSame('Q3 contingency, approved by the MD', $exception['funding_source']);
        $this->assertSame($this->approver->id, (int) $exception['approved_by']);

        // The authoritative record, alongside the refusal that preceded it.
        $log = GovernanceAuditLog::where('gate_type', 'expenditure_exception')->firstOrFail();
        $this->assertSame($log->id, (int) $exception['governance_log_id']);
        $this->assertSame('authorized', $log->action_status);
        $this->assertSame($this->approver->id, $log->user_id);
        $this->assertStringContainsString('WNG-09-2026-001', $log->message);
        $this->assertStringContainsString('10,349.99', $log->message);
        $this->assertEqualsWithDelta(10349.99, $log->context['overage'], 0.001);

        $this->assertDatabaseHas('governance_audit_logs', [
            'project_enquiry_id' => $this->enquiry->id,
            'gate_type' => 'expenditure',
            'action_status' => 'blocked',
        ]);

        // And the money: the commitment carries the justification, so it lands in
        // the cost account's Unbudgeted panel explained rather than bare. Posted
        // directly here because the listener that normally does it is queued.
        app(PettyCashCostProducer::class)->commitFor($requisition);

        $commitment = CostLine::where('source_type', PettyCashRequisition::class)
            ->where('source_id', $requisition->id)
            ->where('nature', CostLine::NATURE_COMMITTED)
            ->firstOrFail();

        $this->assertNull($commitment->consumes_line_id, 'An overrun consumes no budget line — that is what makes it unbudgeted.');
        $this->assertSame(
            'Client moved the install date forward; stand cannot ship without it.',
            $commitment->details['unbudgeted_reason'],
        );
        $this->assertSame($log->id, (int) $commitment->details['budget_exception_log_id']);
    }

    /**
     * The justification has to survive the payment.
     *
     * Paying an approved requisition releases its commitment and posts an actual
     * in its place. The reason lives on the commitment, so without carrying it
     * across, the explanation would be reversed away at exactly the moment the
     * cash left the tin — and the cost account would show an unexplained overrun
     * where a minute earlier it showed an authorised one.
     */
    public function test_the_justification_survives_the_payment(): void
    {
        $this->seedFinanceCatalogue();
        $this->budget('1650.00');

        $this->approver->givePermissionTo(Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE);
        $this->approver->forgetCachedPermissions();

        $requisition = $this->requisition('4999.99');
        $reason = 'Client moved the install date forward; stand cannot ship without it.';

        $this->approve($requisition, [
            'budget_exception_reason' => $reason,
            'budget_exception_funding_source' => 'Q3 contingency',
        ])->assertOk();

        $topUpId = \App\Modules\Finance\PettyCash\Models\PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->approver->id,
        ])->id;

        $payment = \App\Modules\Finance\Models\Payment::create([
            'top_up_id' => $topUpId,
            'requisition_id' => $requisition->id,
            'amount' => 4999.99,
            'payee_name' => 'Supplier',
            'account' => 'Cost of Sales:Materials',
            'description' => 'Site materials',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'status' => 'active',
            'job_number' => $this->enquiry->job_number,
            'date_disbursed' => now()->toDateString(),
            'created_by' => $this->approver->id,
        ]);

        app(PettyCashCostProducer::class)->postFor($payment);

        $actual = CostLine::where('source_type', \App\Modules\Finance\Models\Payment::class)
            ->where('source_id', $payment->id)
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->firstOrFail();

        $this->assertSame($reason, $actual->details['unbudgeted_reason']);
    }

    /** An approval inside its budget records no exception and asks for none. */
    public function test_spending_within_budget_is_untouched(): void
    {
        $this->seedFinanceCatalogue();
        $this->budget('10000.00');

        $this->approve($this->requisition('4000.00'))->assertOk();

        $this->assertNull($this->requisition('1.00')->fresh()->budget_exception);
        $this->assertSame(0, GovernanceAuditLog::where('gate_type', 'expenditure_exception')->count());
    }
}
