<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService;
use App\Modules\Finance\PettyCash\Services\RequisitionClosureService;
use App\Modules\Finance\PettyCash\Services\RequisitionDisbursementService;
use App\Modules\Finance\Support\RequisitionAdvanceControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReceiverRequisitionFixture;
use Tests\TestCase;

/**
 * Report 75R-C: WNG's four Financial Requisition decisions.
 *
 *   1. Every disbursement is held in Staff Advances until accounted for.
 *   2. Overspend is never reimbursed automatically.
 *   3. Finance releases an unused approved balance; the requester cannot.
 *   4. The approver must not be the payer; the payer may reconcile and close.
 */
class RequisitionPolicyClosureTest extends TestCase
{
    use RefreshDatabase;
    use ReceiverRequisitionFixture;

    private User $releaser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFinanceReferenceData();
        $this->createReceiverRequisition();
        foreach ([Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, Permissions::FINANCE_CONFIG_VIEW] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->releaser = User::factory()->create(['is_active' => true, 'name' => 'Finance Controller']);
        $this->releaser->givePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
        $this->actingAs($this->finance, 'sanctum');
    }

    private function url(string $suffix): string
    {
        return '/api/finance/petty-cash/requisitions/'.$this->requisition->id.$suffix;
    }

    private function overview(User $as): array
    {
        $as->givePermissionTo(Permissions::FINANCE_CONFIG_VIEW);

        return collect($this->actingAs($as, 'sanctum')->getJson('/api/finance/governance')->assertOk()->json('data.requisition_controls'))->keyBy('key')->all();
    }

    // ── 1. Advance account ───────────────────────────────────────────────────

    public function test_every_kind_of_receiver_is_held_in_staff_advances_and_payment_is_not_a_cost(): void
    {
        $this->projectRequisition();
        app(PettyCashCostProducer::class)->commitFor($this->requisition);
        $lines = $this->lines();
        $advance = (int) ChartOfAccount::where('code', '1300')->value('id');

        $payments = [
            'employee' => $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000'),
            'other' => $this->payReceiver([$lines['Transport']], '30000'),
            'supplier' => $this->payReceiver([$lines['Materials']], '25000'),
        ];
        foreach ($payments as $type => $payment) {
            $this->assertSame($type, $payment->payee_type);
            $legs = DB::table('journal_lines')->where('journal_entry_id', $payment->advance_journal_entry_id)->get();
            $this->assertSame($advance, (int) $legs->firstWhere('entry_type', 'debit')->account_id, "{$type}: held in Staff Advances");
            $this->assertSame(1, $legs->where('entry_type', 'debit')->count(), "{$type}: nothing is expensed by paying");
        }
        // Payment is a money movement: no project actual, and no expense account is touched.
        $this->assertSame(0, CostLine::where('nature', CostLine::NATURE_ACTUAL)->count());

        // Accepted accountability is where the cost is recognised — once.
        $accountability = app(RequisitionAccountabilityService::class);
        $key = $this->receiver('Winnie')['key'];
        $accountability->confirmReceipt($this->requisition->id, $this->creator, ['receiver_key' => $key, 'evidence_reference' => 'BANK-1']);
        $surrender = $accountability->submit($this->requisition->id, $this->creator, ['receiver_key' => $key, 'idempotency_key' => (string) Str::uuid(),
            'items' => [['requisition_item_id' => $lines['Materials'], 'expense_code_id' => $this->projectExpenseCodeId, 'amount' => '24000', 'receipt_type' => 'none', 'description' => 'Materials']],
            'returns' => [['requisition_item_id' => $lines['Materials'], 'amount' => '1000']]]);
        $accountability->reconcile($surrender->id, $this->finance);
        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_ACTUAL)->count());
        $this->assertSame('24000.00', (string) CostLine::where('nature', CostLine::NATURE_ACTUAL)->value('net_amount'));
    }

    public function test_the_advance_policy_is_proposed_until_someone_with_authority_approves_it(): void
    {
        $controls = $this->overview($this->finance);
        $this->assertSame(['proposed', 'PROPOSED'], [$controls['advance_account']['state'], $controls['advance_account']['status']]);
        $this->assertCount(3, $controls['advance_account']['items']);
        $this->assertStringContainsString('1300', $controls['advance_account']['rule']);
        $this->assertSame(0, FinanceConfigVersion::count(), 'Nothing was approved on anybody\'s behalf.');
        foreach (['employee', 'supplier', 'other'] as $type) {
            $this->assertSame('existing_unapproved', RequisitionAdvanceControl::for($type)['basis']);
        }

        // Approved and active for all three, through the governance record, it reads as configured.
        foreach (['employee', 'supplier', 'other'] as $type) {
            DB::table('finance_config_versions')->insert(['item_key' => RequisitionAdvanceControl::item($type), 'domain' => 'policies', 'version' => 1,
                'value' => json_encode(['account_code' => '1300']), 'status' => 'active', 'revision' => 1, 'effective_from' => now()->toDateString(),
                'in_force_from' => now()->toDateString(), 'proposed_by' => $this->finance->id, 'decided_by' => $this->approver->id,
                'created_at' => now(), 'updated_at' => now()]);
            GovernanceRuntime::flush();
            $expected = $type === 'other' ? ['active', 'CONFIGURED / ACTIVE'] : ['proposed', 'PROPOSED'];
            $state = $this->overview($this->finance)['advance_account'];
            $this->assertSame($expected, [$state['state'], $state['status']], "after approving {$type}");
        }
        $this->assertSame(['approved', '1300'], [RequisitionAdvanceControl::for('supplier')['basis'], RequisitionAdvanceControl::for('supplier')['account_code']]);
    }

    // ── 2. Overspend ─────────────────────────────────────────────────────────

    public function test_receiver_overspend_reimburses_nothing_and_tells_the_person_what_to_do(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Materials']], '25000');
        $accountability = app(RequisitionAccountabilityService::class);
        $key = $this->receiver('Winnie')['key'];
        $accountability->confirmReceipt($this->requisition->id, $this->creator, ['receiver_key' => $key, 'evidence_reference' => 'BANK-1']);
        $before = [Payment::count(), JournalEntry::count(), CostLine::count(), CashMovement::count()];

        $surrender = $accountability->submit($this->requisition->id, $this->creator, ['receiver_key' => $key, 'idempotency_key' => (string) Str::uuid(),
            'items' => [['requisition_item_id' => $lines['Materials'], 'expense_code_id' => $this->expenseCodeId, 'amount' => '27000', 'receipt_type' => 'none', 'description' => 'Materials']]]);
        $this->assertSame('2000.00', (string) $surrender->overspend_amount);
        $this->assertSame('overspend_requires_resolution', $this->receiver('Winnie')['accountability_state']);
        $this->assertSame(RequisitionAccountabilityService::OVERSPEND_INSTRUCTION, $this->controls()['overspend_instruction']);

        $this->postJson($this->url("/accountabilities/{$surrender->id}/reconcile"))->assertStatus(422)
            ->assertJsonPath('errors.accountability.0', fn ($message) => str_contains($message, 'Overspend requires resolution')
                && str_contains($message, 'requires approval before Finance can disburse the additional amount'));
        $this->assertSame($before, [Payment::count(), JournalEntry::count(), CostLine::count(), CashMovement::count()], 'No payment, journal, cost or return appears.');
        $this->assertSame('ACTIVE', $this->overview($this->finance)['overspend']['status']);
        $this->assertSame('NO AUTOMATIC REIMBURSEMENT — ADDITIONAL FUNDING REQUIRES APPROVAL.', $this->overview($this->finance)['overspend']['rule']);
    }

    // The one-payment requisition follows the same rule: see
    // Stab7PettyCashTriplePostingTest::test_spend_exceeding_the_advance_is_not_reconciled_and_reimburses_nothing.

    // ── 3. Unused approved balance ───────────────────────────────────────────

    public function test_finance_releases_an_unused_balance_and_the_requester_cannot(): void
    {
        $this->payReceiver([$this->lines()['Materials']], '15000');
        $payload = ['receiver_key' => $this->receiver('Winnie')['key'], 'amount' => '10000.00', 'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Requester confirmed the balance is no longer required'];

        // The requester cannot release their own, even holding the permission.
        $this->creator->givePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
        $this->actingAs($this->creator->fresh(), 'sanctum')->postJson($this->url('/release-unused'), $payload)->assertStatus(422)
            ->assertJsonPath('errors.requisition.0', 'The requester cannot release the balance of their own requisition.');
        // Nor the verifier, nor Finance without the authority.
        foreach ([$this->verifier, $this->finance, $this->approver] as $user) {
            $this->actingAs($user, 'sanctum')->postJson($this->url('/release-unused'), $payload)->assertForbidden();
        }
        // A reason is mandatory.
        $this->actingAs($this->releaser, 'sanctum')->postJson($this->url('/release-unused'), ['reason' => ''] + $payload)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('requisition_balance_releases', 0);

        $this->postJson($this->url('/release-unused'), $payload)->assertOk();
        $controls = $this->controls();
        $this->assertSame(['75000.00', '15000.00', '10000.00'], [$controls['approved'], $controls['disbursed'], $controls['released_unused']]);
        $this->assertSame('75000.00', (string) $this->requisition->fresh()->total_amount, 'The approved amount is never reduced.');
        $this->assertDatabaseHas('requisition_balance_releases', ['released_by' => $this->releaser->id, 'reason' => $payload['reason']]);
        $this->assertDatabaseHas('governance_audit_logs', ['gate_type' => 'requisition_unused_balance_released', 'user_id' => $this->releaser->id]);
    }

    public function test_release_authority_is_held_by_no_role_until_wng_assigns_it(): void
    {
        foreach (RolePermissions::matrix() as $role => $permissions) {
            $this->assertNotContains(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, $permissions, "{$role} must not hold it by default");
        }
        $this->releaser->revokePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
        $unused = $this->overview($this->finance)['unused_balance'];
        $this->assertSame(['assignment_required', 'AUTHORITY ASSIGNMENT REQUIRED'], [$unused['state'], $unused['status']]);

        $this->releaser->givePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
        $unused = $this->overview($this->finance)['unused_balance'];
        $this->assertSame('AUTHORITY ASSIGNED', $unused['status']);
        $this->assertStringContainsString('Finance Controller', $unused['detail']);
    }

    // ── 4. Approver ≠ payer ──────────────────────────────────────────────────

    public function test_the_approver_cannot_pay_whatever_permissions_they_hold(): void
    {
        $lines = $this->lines();
        $this->approver->givePermissionTo([Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::APPROVALS_SELF_APPROVE]);
        $approver = $this->approver->fresh();
        $instructions = $this->instructions([$lines['Materials']], '25000');

        $this->actingAs($approver, 'sanctum')->postJson($this->url('/disburse'), $instructions)->assertForbidden()
            ->assertJsonPath('message', 'This requisition must be paid by a different authorised Finance user from the person who approved it.');
        try {
            app(RequisitionDisbursementService::class)->pay($this->requisition->id, $approver, $instructions);
            $this->fail('The approver paid.');
        } catch (AuthorizationException $e) {
            $this->assertSame(RequisitionDisbursementService::APPROVER_IS_PAYER, $e->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $controls = app(\App\Modules\Finance\PettyCash\Services\RequisitionControlProjection::class)->forRequisition($this->requisition->fresh());
        $this->assertFalse($controls['can_process_payment'], 'No button is offered to the approver either.');
        $this->assertSame(RequisitionDisbursementService::APPROVER_IS_PAYER, $controls['payment_block']);

        // A different authorised Finance user pays.
        $this->actingAs($this->finance, 'sanctum')->postJson($this->url('/disburse'), $instructions)->assertOk();
        $this->assertSame('ACTIVE', $this->overview($this->finance)['segregation']['status']);
        $this->assertSame('APPROVER ≠ PAYER', $this->overview($this->finance)['segregation']['rule']);
    }

    public function test_the_approver_cannot_pay_a_one_payment_requisition_either(): void
    {
        $this->approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $legacy = PettyCashRequisition::create(['requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
            'user_id' => $this->creator->id, 'responsible_verifier_id' => $this->verifier->id, 'department_id' => $this->departmentId,
            'category' => 'Operations', 'purpose' => 'Office supplies', 'total_amount' => '4000.00', 'status' => 'pending', 'payee_name' => 'Sam']);
        $legacy->items()->create(['description' => 'Office supplies', 'amount' => '4000.00', 'payee_name' => 'Sam']);
        $verification = app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class);
        $verification->submit($legacy, $this->creator->id);
        $verification->review($legacy->id, $this->verifier, 'verified', null);
        $legacy->refresh()->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->approver->id])->save();
        $payload = ['idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->expenseCodeId, 'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'bank_transfer', 'external_reference' => 'TRX-1', 'amount' => '4000.00', 'payee_name' => 'Sam',
            'description' => 'Office supplies', 'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none'];

        $this->actingAs($this->approver->fresh(), 'sanctum')->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/disburse', $payload)
            ->assertForbidden()->assertJsonPath('message', RequisitionDisbursementService::APPROVER_IS_PAYER);
        $this->assertSame(0, Payment::where('requisition_id', $legacy->id)->count());
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/disburse', $payload)->assertOk();
    }

    public function test_the_payer_may_reconcile_and_close(): void
    {
        $lines = $this->lines();
        $accountability = app(RequisitionAccountabilityService::class);
        // One person — the cashier — pays every receiver, reconciles every surrender and closes.
        foreach ([['Steve', ['Site facilitation' => '15000', 'Casual support' => '5000']], ['Timothy', ['Transport' => '30000']], ['Winnie', ['Materials' => '25000']]] as [$name, $spend]) {
            $this->payReceiver(array_map(fn ($purpose) => $lines[$purpose], array_keys($spend)), (string) array_sum($spend));
            $key = $this->receiver($name)['key'];
            $accountability->confirmReceipt($this->requisition->id, $this->creator, ['receiver_key' => $key, 'evidence_reference' => 'REF-'.$name]);
            $surrender = $accountability->submit($this->requisition->id, $this->creator, ['receiver_key' => $key, 'idempotency_key' => (string) Str::uuid(),
                'items' => collect($spend)->map(fn ($amount, $purpose) => ['requisition_item_id' => $lines[$purpose], 'expense_code_id' => $this->expenseCodeId,
                    'amount' => $amount, 'receipt_type' => 'none', 'description' => $purpose])->values()->all()]);
            $this->actingAs($this->finance, 'sanctum')->postJson($this->url("/accountabilities/{$surrender->id}/reconcile"))->assertOk();
        }
        $this->assertSame([$this->finance->id], Payment::where('requisition_id', $this->requisition->id)->distinct()->pluck('created_by')->all());
        $this->postJson($this->url('/close'))->assertOk()->assertJsonPath('controls.control_state', 'closed');
        $this->assertSame($this->finance->id, $this->requisition->fresh()->closed_by);

        // Closure still needs the mathematics: a part-accounted one is refused for the same person.
        $this->createReceiverRequisition();
        $this->payReceiver([$this->lines()['Materials']], '25000');
        try {
            app(RequisitionClosureService::class)->close($this->requisition->id, $this->finance);
            $this->fail('Closed without reconciling.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('requisition', $e->errors());
        }
    }

    public function test_creator_and_verifier_controls_are_unchanged(): void
    {
        $instructions = $this->instructions([$this->lines()['Materials']], '25000');
        foreach ([$this->creator, $this->verifier] as $user) {
            $this->actingAs($user, 'sanctum')->postJson($this->url('/disburse'), $instructions)->assertForbidden();
        }
        $this->creator->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $this->actingAs($this->creator->fresh(), 'sanctum')->postJson($this->url('/disburse'), $instructions)->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }
}
