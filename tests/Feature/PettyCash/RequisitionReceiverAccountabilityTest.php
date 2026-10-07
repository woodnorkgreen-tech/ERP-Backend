<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Models\CashMovement;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrender;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderAllocation;
use App\Modules\Finance\PettyCash\Models\RequisitionBalanceRelease;
use App\Modules\Finance\PettyCash\Models\RequisitionReceiptConfirmation;
use App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService;
use App\Modules\Finance\PettyCash\Services\RequisitionClosureService;
use App\Modules\Finance\PettyCash\Services\RequisitionDisbursementService;
use App\Modules\Finance\Services\PaymentReversalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReceiverRequisitionFixture;
use Tests\TestCase;

/**
 * Report 75R-B: the life of a requisition after its receivers are paid —
 * receipt confirmation, surrender by receiver, reconciliation, return of unused
 * money, release of an unpaid balance, and closure.
 *
 * The worked example throughout is Report 75R-A's: KES 75,000 for Steve
 * (20,000 over two lines), Timothy (30,000) and Winnie (25,000).
 */
class RequisitionReceiverAccountabilityTest extends TestCase
{
    use RefreshDatabase;
    use ReceiverRequisitionFixture;

    private User $releaser;
    private User $reverser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFinanceReferenceData();
        $this->createReceiverRequisition();
        foreach ([Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, Permissions::FINANCE_JOURNALS_REVERSE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->releaser = User::factory()->create(['is_active' => true, 'name' => 'Finance Controller']);
        $this->releaser->givePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
        $this->reverser = User::factory()->create(['is_active' => true, 'name' => 'Finance Lead']);
        $this->reverser->givePermissionTo([Permissions::FINANCE_JOURNALS_REVERSE, Permissions::FINANCE_PAYMENTS_REVERSE]);
        $this->actingAs($this->finance, 'sanctum');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** D01 Steve 20,000 · D02 Timothy 20,000 · D03 Timothy 10,000 · D04 Winnie 25,000. */
    private function payEveryone(?int $timothySecondSource = null): array
    {
        $lines = $this->lines();
        $service = app(RequisitionDisbursementService::class);
        $pay = fn (array $ids, string $amount, array $more = []) => $service->pay($this->requisition->id, $this->finance, $this->instructions($ids, $amount, $more));

        return [
            'D01' => $pay([$lines['Site facilitation'], $lines['Casual support']], '20000'),
            'D02' => $pay([$lines['Transport']], '20000'),
            'D03' => $pay([$lines['Transport']], '10000', $timothySecondSource ? ['payment_source_id' => $timothySecondSource] : []),
            'D04' => $pay([$lines['Materials']], '25000'),
        ];
    }

    private function key(string $name): string
    {
        return $this->receiver($name)['key'];
    }

    private function accountability(): RequisitionAccountabilityService
    {
        return app(RequisitionAccountabilityService::class);
    }

    /** The requester confirms for a receiver who has no login, stating what they rely on. */
    private function confirm(string $name, ?User $actor = null, array $more = []): array
    {
        return $this->accountability()->confirmReceipt($this->requisition->id, $actor ?? $this->creator,
            ['receiver_key' => $this->key($name), 'evidence_reference' => 'MPESA-QWE123'] + $more);
    }

    /**
     * @param  array<string, string>  $spent  purpose => amount
     * @param  array<int, array{0: string, 1: string, 2?: int}>  $returns  [purpose, amount, payment id]
     */
    private function account(string $name, array $spent, array $returns = [], ?User $actor = null): PettyCashSurrender
    {
        $lines = $this->lines();
        $items = [];
        foreach ($spent as $purpose => $amount) {
            $items[] = ['requisition_item_id' => $lines[$purpose], 'expense_code_id' => isset($this->projectExpenseCodeId) ? $this->projectExpenseCodeId : $this->expenseCodeId,
                'amount' => $amount, 'receipt_type' => 'none', 'description' => "{$purpose} — receipts"];
        }

        return $this->accountability()->submit($this->requisition->id, $actor ?? $this->creator, [
            'receiver_key' => $this->key($name), 'idempotency_key' => (string) Str::uuid(), 'items' => $items,
            'returns' => array_map(fn (array $r) => array_filter(['requisition_item_id' => $lines[$r[0]], 'amount' => $r[1], 'payment_id' => $r[2] ?? null]), $returns),
        ]);
    }

    private function reconcile(PettyCashSurrender $surrender): PettyCashSurrender
    {
        return $this->accountability()->reconcile($surrender->id, $this->finance);
    }

    private function refused(callable $attempt, string $field): string
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));

            return implode(' ', $e->errors()[$field]);
        }
        $this->fail('The action was accepted.');
    }

    private function blockerCodes(): array
    {
        return array_column($this->controls()['closure_blockers'], 'code');
    }

    /** Everyone paid, confirmed and accounted for in full: 71,500 accepted, 3,500 returned. */
    private function accountForEverything(): void
    {
        $this->payEveryone();
        foreach (['Steve', 'Timothy', 'Winnie'] as $name) {
            $this->confirm($name);
        }
        $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));
        $this->reconcile($this->account('Timothy', ['Transport' => '28000'], [['Transport', '2000']]));
        $this->reconcile($this->account('Winnie', ['Materials' => '24000'], [['Materials', '1000']]));
    }

    // ── Receipt confirmation ─────────────────────────────────────────────────

    public function test_three_receivers_confirm_independently(): void
    {
        $this->payEveryone();
        $this->assertSame('awaiting_receipt_confirmation', $this->controls()['control_state']);

        // Steve has a login and confirms for himself.
        $this->actingAs($this->steveUser, 'sanctum')->postJson($this->url('/receivers/confirm-receipt'), ['receiver_key' => $this->key('Steve')])
            ->assertOk()->assertJsonPath('controls.confirmed_received', '20000.00');
        $this->assertSame(RequisitionReceiptConfirmation::BY_RECEIVER, RequisitionReceiptConfirmation::firstOrFail()->basis);

        $steve = $this->receiver('Steve');
        $this->assertSame(['20000.00', '0.00', 'awaiting_accountability'], [$steve['confirmed'], $steve['awaiting_confirmation'], $steve['accountability_state']]);
        $this->assertSame('awaiting_receipt_confirmation', $this->receiver('Winnie')['accountability_state'], 'Winnie is not waited for, and not confirmed by Steve.');
        $this->assertSame('0.00', $this->receiver('Timothy')['confirmed']);

        // Timothy has no login: the requester confirms for him and must say on what basis.
        $this->actingAs($this->creator, 'sanctum')->postJson($this->url('/receivers/confirm-receipt'), ['receiver_key' => $this->key('Timothy')])
            ->assertStatus(422)->assertJsonValidationErrors('evidence_reference');
        $this->postJson($this->url('/receivers/confirm-receipt'), ['receiver_key' => $this->key('Timothy'), 'evidence_reference' => 'MPESA-ABC'])->assertOk();
        $record = RequisitionReceiptConfirmation::where('receiver_type', 'other')->firstOrFail();
        $this->assertSame(['on_behalf', 'Timothy Mwangi', 'MPESA-ABC', $this->creator->id],
            [$record->basis, $record->represented_name, $record->evidence_reference, $record->confirmed_by]);

        $controls = $this->controls();
        $this->assertSame(['50000.00', '25000.00', 'partially_confirmed'],
            [$controls['confirmed_received'], $controls['awaiting_confirmation'], $controls['confirmation_status']]);
        $this->assertSame(['receipt_unconfirmed'], array_values(array_unique(array_intersect($this->blockerCodes(), ['receipt_unconfirmed']))));
    }

    public function test_only_the_receiver_or_the_requester_may_confirm(): void
    {
        $this->payEveryone();
        foreach ([$this->finance, $this->verifier, $this->approver] as $user) {
            $this->actingAs($user, 'sanctum')->postJson($this->url('/receivers/confirm-receipt'),
                ['receiver_key' => $this->key('Winnie'), 'evidence_reference' => 'X-1'])->assertForbidden();
        }
        // Steve may confirm his own money, not Winnie's.
        $this->actingAs($this->steveUser, 'sanctum')->postJson($this->url('/receivers/confirm-receipt'),
            ['receiver_key' => $this->key('Winnie'), 'evidence_reference' => 'X-1'])->assertForbidden();
        $this->assertDatabaseCount('requisition_receipt_confirmations', 0);
        $this->assertFalse($this->controls()['receivers'][2]['actions']['confirm_receipt'], 'No button is offered to someone who may not use it.');
    }

    public function test_a_receiver_paid_twice_confirms_each_payment_and_never_more_than_was_paid(): void
    {
        $lines = $this->lines();
        $first = $this->payReceiver([$lines['Transport']], '20000');
        $this->confirm('Timothy');
        $this->assertSame('20000.00', $this->receiver('Timothy')['confirmed']);

        // Nothing further to confirm, and the same payment cannot be confirmed twice.
        $this->refused(fn () => $this->confirm('Timothy'), 'payment_ids');
        $this->refused(fn () => $this->confirm('Timothy', null, ['payment_ids' => [$first->id]]), 'payment_ids');

        $second = $this->payReceiver([$lines['Transport']], '10000');
        $this->assertSame(['20000.00', '10000.00'], [$this->receiver('Timothy')['confirmed'], $this->receiver('Timothy')['awaiting_confirmation']]);
        $steves = $this->payReceiver([$lines['Site facilitation']], '15000');
        // Somebody else's payment cannot be confirmed under Timothy.
        $this->refused(fn () => $this->confirm('Timothy', null, ['payment_ids' => [$steves->id]]), 'payment_ids');

        $this->confirm('Timothy', null, ['payment_ids' => [$second->id]]);
        $this->assertSame(['30000.00', '0.00'], [$this->receiver('Timothy')['confirmed'], $this->receiver('Timothy')['awaiting_confirmation']]);
        $this->assertSame(2, RequisitionReceiptConfirmation::where('receiver_type', 'other')->count());
        $this->assertSame('30000.00', number_format((float) RequisitionReceiptConfirmation::where('receiver_type', 'other')->sum('amount'), 2, '.', ''));
    }

    public function test_reversal_before_and_after_confirmation(): void
    {
        $payments = $this->payEveryone();

        // Before confirmation: the reversed payment simply is not there to confirm.
        app(PaymentReversalService::class)->reverse($payments['D04'], $this->reverser->id, 'Sent to the wrong supplier');
        $this->refused(fn () => $this->confirm('Winnie'), 'payment_ids');
        $this->assertSame('awaiting_payment', $this->receiver('Winnie')['accountability_state']);

        // After confirmation: the money came back, so it is no longer confirmed as received.
        $this->confirm('Timothy');
        $this->assertSame('30000.00', $this->receiver('Timothy')['confirmed']);
        app(PaymentReversalService::class)->reverse($payments['D03'], $this->reverser->id, 'Duplicate transfer at the bank');

        $timothy = $this->receiver('Timothy');
        $this->assertSame(['20000.00', '20000.00', '10000.00'], [$timothy['paid'], $timothy['confirmed'], $timothy['to_disburse']]);
        $kept = RequisitionReceiptConfirmation::where('payment_id', $payments['D03']->id)->firstOrFail();
        $this->assertNotNull($kept->invalidated_at, 'The confirmation is kept as a record, marked as no longer standing.');
        $this->assertStringContainsString('Duplicate transfer', $kept->invalidated_reason);
        $this->assertSame('20000.00', $this->controls()['confirmed_received']);
    }

    // ── Accountability ───────────────────────────────────────────────────────

    public function test_only_confirmed_money_can_be_accounted_for(): void
    {
        $this->payEveryone();
        $surrender = $this->account('Winnie', ['Materials' => '24000']);
        $this->assertSame('24000.00', (string) $surrender->overspend_amount, 'Nothing is confirmed, so nothing is held to account against.');
        $this->refused(fn () => $this->reconcile($surrender), 'accountability');
        $this->assertFalse($this->receiver('Winnie')['surrenders'][0]['actions']['reconcile']);
    }

    public function test_line_purposes_are_retained_through_accountability(): void
    {
        $this->payEveryone();
        $this->confirm('Steve');
        $surrender = $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));

        $lines = collect($this->receiver('Steve')['lines'])->keyBy('purpose');
        $this->assertSame(['15000.00', '14500.00', '500.00', '0.00'], [$lines['Site facilitation']['paid'], $lines['Site facilitation']['accepted'],
            $lines['Site facilitation']['returned'], $lines['Site facilitation']['to_account']]);
        $this->assertSame(['5000.00', '5000.00', '0.00', '0.00'], [$lines['Casual support']['paid'], $lines['Casual support']['accepted'],
            $lines['Casual support']['returned'], $lines['Casual support']['to_account']]);

        $steve = $this->receiver('Steve');
        $this->assertSame(['20000.00', '19500.00', '500.00', '0.00', 'complete'],
            [$steve['confirmed'], $steve['accepted'], $steve['returned'], $steve['to_account'], $steve['accountability_state']]);
        // RECEIVED = ACCEPTED + RETURNED + OUTSTANDING
        $this->assertSame($steve['confirmed'], bcadd(bcadd($steve['accepted'], $steve['returned'], 2), $steve['to_account'], 2));

        $this->assertSame([$this->lines()['Site facilitation'], $this->lines()['Casual support']], $surrender->items->pluck('requisition_item_id')->all());
        $this->assertSame($this->requisition->requisition_number.'-A01', $surrender->reference);
        $this->assertSame(['Site facilitation', 'Casual support'], array_column($steve['surrenders'][0]['items'], 'purpose'));
    }

    public function test_accountability_in_two_stages(): void
    {
        $this->payEveryone();
        $this->confirm('Timothy');

        $first = $this->reconcile($this->account('Timothy', ['Transport' => '18000']));
        $timothy = $this->receiver('Timothy');
        $this->assertSame(['18000.00', '12000.00', 'partially_accounted'], [$timothy['accepted'], $timothy['to_account'], $timothy['accountability_state']]);
        $this->assertSame('partially_accounted', $this->controls()['accountability_status']);

        $second = $this->reconcile($this->account('Timothy', ['Transport' => '10000'], [['Transport', '2000']]));
        $timothy = $this->receiver('Timothy');
        $this->assertSame(['28000.00', '2000.00', '0.00', 'complete'], [$timothy['accepted'], $timothy['returned'], $timothy['to_account'], $timothy['accountability_state']]);
        $this->assertSame([$this->requisition->requisition_number.'-A01', $this->requisition->requisition_number.'-A02'], [$first->reference, $second->reference]);
        $this->assertCount(2, $timothy['surrenders']);
        $this->assertNotSame($first->journal_entry_id, $second->journal_entry_id);

        // The first stage cleared the first instalment; the second finished it and went on to the next.
        $cleared = PettyCashSurrenderAllocation::with('payment')->get()->groupBy(fn ($a) => substr($a->payment->requisition_child_reference, -3))
            ->map(fn ($group) => number_format((float) $group->sum('amount'), 2, '.', ''));
        $this->assertSame(['D02' => '20000.00', 'D03' => '10000.00'], $cleared->all());
    }

    public function test_one_stage_at_a_time_and_finance_can_return_it(): void
    {
        $this->payEveryone();
        $this->confirm('Timothy');
        $surrender = $this->account('Timothy', ['Transport' => '18000']);
        $this->assertSame(['18000.00', 'accountability_review'], [$this->receiver('Timothy')['under_review'], $this->receiver('Timothy')['accountability_state']]);
        $this->refused(fn () => $this->account('Timothy', ['Transport' => '5000']), 'accountability');

        $this->postJson($this->url("/accountabilities/{$surrender->id}/return"), ['reason' => 'Fuel receipt is missing'])->assertOk();
        $this->assertSame(['0.00', 'accountability_returned'], [$this->receiver('Timothy')['under_review'], $this->receiver('Timothy')['accountability_state']]);
        $this->assertSame(0, JournalEntry::where('source_type', PettyCashSurrender::class)->count(), 'Returning posts nothing.');

        $corrected = $this->account('Timothy', ['Transport' => '17000']);
        $this->assertSame($surrender->id, $corrected->id, 'The correction is the same surrender, resubmitted.');
        $this->assertSame('17000.00', (string) $corrected->spent_amount);
        $this->assertSame(2, DB::table('petty_cash_surrender_reviews')->where('petty_cash_requisition_id', $this->requisition->id)->count());
    }

    public function test_a_replayed_submission_creates_one_surrender(): void
    {
        $this->payEveryone();
        $this->confirm('Winnie');
        $payload = ['receiver_key' => $this->key('Winnie'), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['requisition_item_id' => $this->lines()['Materials'], 'expense_code_id' => $this->expenseCodeId, 'amount' => '24000.00',
                'receipt_type' => 'none', 'description' => 'Materials — receipts']]];

        $first = $this->actingAs($this->creator, 'sanctum')->postJson($this->url('/accountabilities'), $payload)->assertOk();
        $second = $this->postJson($this->url('/accountabilities'), $payload)->assertOk();
        $this->assertSame($first->json('surrender_id'), $second->json('surrender_id'));
        $this->assertDatabaseCount('petty_cash_surrenders', 1);
        $this->assertDatabaseCount('petty_cash_surrender_items', 1);
    }

    // ── Returns ──────────────────────────────────────────────────────────────

    public function test_returned_money_is_its_own_movement_into_the_account_it_left(): void
    {
        $payments = $this->payEveryone();
        $this->confirm('Winnie');
        $surrender = $this->reconcile($this->account('Winnie', ['Materials' => '24000'], [['Materials', '1000']]));

        $return = $surrender->allocations->firstWhere('kind', 'return');
        $this->assertSame($payments['D04']->id, $return->payment_id);
        $this->assertSame($this->paymentSourceId, $return->payment_source_id);

        $movement = CashMovement::findOrFail($return->cash_movement_id);
        $this->assertSame(['in', 'requisition_return', '1000.00', 'posted', $this->paymentSourceId],
            [$movement->direction, $movement->transaction_type, (string) $movement->amount, $movement->status, $movement->payment_source_id]);
        $advanceAccount = (int) ChartOfAccount::where('code', '1300')->value('id');
        $this->assertSame($advanceAccount, (int) $movement->offset_account_id);
        $legs = DB::table('journal_lines')->where('journal_entry_id', $movement->journal_entry_id)->pluck('amount', 'entry_type');
        $this->assertSame(['debit' => '1000.00', 'credit' => '1000.00'], $legs->map(fn ($a) => (string) $a)->all());

        // The clearing entry carries the spend only; the return is not netted into it.
        $clearing = DB::table('journal_lines')->where('journal_entry_id', $surrender->journal_entry_id)->get();
        $this->assertSame('24000.00', number_format((float) $clearing->where('entry_type', 'credit')->where('account_id', $advanceAccount)->sum('amount'), 2, '.', ''));
        $this->assertSame('24000.00', number_format((float) $clearing->where('entry_type', 'debit')->sum('amount'), 2, '.', ''));

        // The advance is cleared in full: 25,000 out, 24,000 expensed, 1,000 back.
        $balance = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $advanceAccount)->whereIn('e.id', [$payments['D04']->advance_journal_entry_id, $surrender->journal_entry_id, $movement->journal_entry_id])
            ->selectRaw("SUM(CASE WHEN l.entry_type='debit' THEN l.amount ELSE -l.amount END) b")->value('b');
        $this->assertSame('0.00', number_format((float) $balance, 2, '.', ''));
    }

    public function test_a_return_across_two_paying_accounts_must_name_its_payment(): void
    {
        $payments = $this->payEveryone($this->secondSourceId);
        $this->assertNotSame($payments['D02']->payment_source_id, $payments['D03']->payment_source_id);
        $this->confirm('Timothy');

        // 30,000 held across two accounts and nothing spent: which account does 2,000 go back to?
        $message = $this->refused(fn () => $this->account('Timothy', [], [['Transport', '2000']]), 'returns.0.payment_id');
        $this->assertStringContainsString('more than one account', $message);
        $this->assertDatabaseCount('petty_cash_surrenders', 0);

        $surrender = $this->reconcile($this->account('Timothy', ['Transport' => '28000'], [['Transport', '2000', $payments['D03']->id]]));
        $return = $surrender->allocations->firstWhere('kind', 'return');
        $this->assertSame([$payments['D03']->id, $this->secondSourceId], [$return->payment_id, $return->payment_source_id]);
        $this->assertSame($this->secondSourceId, CashMovement::findOrFail($return->cash_movement_id)->payment_source_id);

        // No more than a payment put on the line can be returned against it.
        $this->refused(fn () => $this->account('Timothy', [], [['Transport', '1', $payments['D03']->id]]), 'returns.0.amount');
    }

    public function test_a_full_unused_return_needs_no_receipts(): void
    {
        $this->payEveryone();
        $this->confirm('Winnie');
        $surrender = $this->reconcile($this->account('Winnie', [], [['Materials', '25000']]));

        $this->assertNull($surrender->journal_entry_id, 'Nothing was spent, so there is no expense entry.');
        $this->assertSame(['0.00', '25000.00', '0.00', 'complete'], [$this->receiver('Winnie')['accepted'], $this->receiver('Winnie')['returned'],
            $this->receiver('Winnie')['to_account'], $this->receiver('Winnie')['accountability_state']]);
        $this->assertSame(1, CashMovement::where('transaction_type', 'requisition_return')->count());
        $this->assertSame(0, CostLine::count());
    }

    // ── Overspend ────────────────────────────────────────────────────────────

    public function test_overspend_is_held_for_resolution_and_becomes_nothing_by_itself(): void
    {
        $this->payEveryone();
        $this->confirm('Steve');
        $before = ['payments' => Payment::count(), 'journals' => JournalEntry::count(), 'costs' => CostLine::count(), 'cash' => CashMovement::count()];

        $surrender = $this->account('Steve', ['Site facilitation' => '17000', 'Casual support' => '5000']);
        $this->assertSame(['22000.00', '2000.00'], [(string) $surrender->spent_amount, (string) $surrender->overspend_amount]);
        $steve = $this->receiver('Steve');
        $this->assertSame(['overspend_requires_resolution', '2000.00'], [$steve['accountability_state'], $steve['overspend']]);
        $this->assertFalse($steve['surrenders'][0]['actions']['reconcile']);
        $this->assertTrue($steve['surrenders'][0]['actions']['return']);
        $this->assertSame('exception', $this->controls()['control_state']);
        $this->assertContains('overspend_unresolved', $this->blockerCodes());

        $message = $this->refused(fn () => $this->reconcile($surrender), 'accountability');
        $this->assertStringContainsString('Overspend requires resolution', $message);
        // Not a payment, not an expense, not a reimbursement, not another advance.
        $this->assertSame($before, ['payments' => Payment::count(), 'journals' => JournalEntry::count(), 'costs' => CostLine::count(), 'cash' => CashMovement::count()]);

        $this->accountability()->returnForCorrection($surrender->id, $this->finance, 'Claim is above the advance; resubmit within it.');
        $this->reconcile($this->account('Steve', ['Site facilitation' => '15000', 'Casual support' => '5000']));
        $this->assertSame(['0.00', 'complete'], [$this->receiver('Steve')['overspend'], $this->receiver('Steve')['accountability_state']]);
        $this->assertNotContains('overspend_unresolved', $this->blockerCodes());
    }

    // ── Project cost and commitment ──────────────────────────────────────────

    public function test_accepted_accountability_reaches_project_cost_exactly_once(): void
    {
        $this->projectRequisition();
        app(PettyCashCostProducer::class)->commitFor($this->requisition);
        $actual = fn () => number_format((float) CostLine::where('nature', CostLine::NATURE_ACTUAL)->where('status', '!=', CostLine::STATUS_REVERSED)->sum('net_amount'), 2, '.', '');

        $this->payEveryone();
        foreach (['Steve', 'Timothy', 'Winnie'] as $name) {
            $this->confirm($name);
        }
        $this->assertSame('0.00', $actual(), 'Four payments and three confirmations are not a cost.');

        $steve = $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));
        $this->assertSame('19500.00', $actual());
        $this->reconcile($this->account('Timothy', ['Transport' => '28000'], [['Transport', '2000']]));
        $this->reconcile($this->account('Winnie', ['Materials' => '24000'], [['Materials', '1000']]));

        // 71,500 accepted, 3,500 returned — not 75,000 from payment plus 71,500 from surrender.
        $this->assertSame('71500.00', $actual());
        $this->assertSame(4, CostLine::where('nature', CostLine::NATURE_ACTUAL)->count(), 'One line per accepted item; returns create none.');
        $this->assertSame(0, CostLine::where('source_type', Payment::class)->count());
        $this->assertSame(['71500.00', '3500.00', '0.00'], [$this->controls()['accounted'], $this->controls()['returned'], $this->controls()['to_account']]);

        // Reconciling the same surrender again changes nothing.
        $this->refused(fn () => $this->reconcile($steve), 'accountability');
        $this->assertSame('71500.00', $actual());
        $this->assertSame(1, JournalEntry::where('source_type', PettyCashSurrender::class)->where('source_id', $steve->id)->count());
        foreach (CostLine::where('nature', CostLine::NATURE_ACTUAL)->get() as $line) {
            $this->assertNotNull($line->posted_at);
        }
    }

    public function test_the_commitment_follows_what_is_still_promised_at_every_stage(): void
    {
        $this->projectRequisition();
        app(PettyCashCostProducer::class)->commitFor($this->requisition);
        $committed = fn () => number_format((float) CostLine::where('nature', CostLine::NATURE_COMMITTED)->where('status', CostLine::STATUS_VERIFIED)->sum('net_amount'), 2, '.', '');
        $actual = fn () => number_format((float) CostLine::where('nature', CostLine::NATURE_ACTUAL)->where('status', '!=', CostLine::STATUS_REVERSED)->sum('net_amount'), 2, '.', '');
        $lines = $this->lines();

        // Approved but unpaid.
        $this->assertSame(['75000.00', '0.00'], [$committed(), $actual()]);

        // Part paid: still all promised, none of it a cost.
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->assertSame(['75000.00', '0.00'], [$committed(), $actual()]);

        // Fully paid but not accounted: the commitment does not vanish.
        $second = $this->payReceiver([$lines['Transport']], '30000');
        $winnie = $this->payReceiver([$lines['Materials']], '15000');
        $this->assertSame(['75000.00', '0.00'], [$committed(), $actual()]);

        // Reversing a payment leaves the promise standing.
        app(PaymentReversalService::class)->reverse($second, $this->reverser->id, 'Sent to the wrong account');
        $this->assertSame(['75000.00', '0.00'], [$committed(), $actual()]);
        $this->payReceiver([$lines['Transport']], '30000');

        // Accepted spend moves from commitment to actual; a return leaves both.
        $this->confirm('Steve');
        $surrender = $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));
        $this->assertSame(['55000.00', '19500.00'], [$committed(), $actual()]);

        // Money that will never be paid is released from the commitment.
        app(RequisitionClosureService::class)->release($this->requisition->id, $this->releaser,
            ['receiver_key' => $this->key('Winnie'), 'amount' => '10000', 'reason' => 'Supplier delivered less than ordered', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(['45000.00', '19500.00'], [$committed(), $actual()]);

        // Reversing the surrender puts its amount back under commitment.
        $this->accountability()->reverse($surrender->id, $this->reverser, 'Receipts were for another job');
        $this->assertSame(['65000.00', '0.00'], [$committed(), $actual()]);

        // commitment + actual never exceeds what was approved, and only one commitment is ever open.
        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_COMMITTED)->where('status', CostLine::STATUS_VERIFIED)->count());
        $this->assertNotNull($winnie);
    }

    // ── Unused balance ───────────────────────────────────────────────────────

    public function test_unused_approved_balance_is_released_without_changing_what_was_approved(): void
    {
        $lines = $this->lines();
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->payReceiver([$lines['Transport']], '30000');
        $this->payReceiver([$lines['Materials']], '15000');
        $payload = ['receiver_key' => $this->key('Winnie'), 'amount' => '10000.00', 'reason' => 'Supplier delivered less than ordered', 'idempotency_key' => (string) Str::uuid()];

        // Not Finance's ordinary authority, not an administrator's, and not the requester's.
        $this->postJson($this->url('/release-unused'), $payload)->assertForbidden();
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');
        $this->actingAs($admin, 'sanctum')->postJson($this->url('/release-unused'), $payload)->assertForbidden();
        $this->assertDatabaseCount('requisition_balance_releases', 0);

        // No more than is approved and unpaid.
        $this->actingAs($this->releaser, 'sanctum')->postJson($this->url('/release-unused'), ['amount' => '10000.01', 'idempotency_key' => (string) Str::uuid()] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson($this->url('/release-unused'), $payload)->assertOk();
        $this->postJson($this->url('/release-unused'), $payload)->assertOk();   // replay
        $this->assertDatabaseCount('requisition_balance_releases', 1);

        $controls = $this->controls();
        $this->assertSame(['75000.00', '65000.00', '10000.00', '0.00'],
            [$controls['approved'], $controls['disbursed'], $controls['released_unused'], $controls['to_disburse']]);
        $this->assertSame('75000.00', (string) $this->requisition->fresh()->total_amount, 'The approved amount is never edited.');
        $winnie = $this->receiver('Winnie');
        $this->assertSame(['25000.00', '15000.00', '10000.00', '0.00'], [$winnie['approved'], $winnie['paid'], $winnie['released'], $winnie['to_disburse']]);
        $this->assertFalse($winnie['can_pay']);

        // What was released can no longer be paid, and cannot be released twice.
        $this->refused(fn () => $this->payReceiver([$lines['Materials']], '1'), 'amount');
        $this->postJson($this->url('/release-unused'), ['amount' => '1.00', 'idempotency_key' => (string) Str::uuid()] + $payload)->assertStatus(422);

        $event = GovernanceAuditLog::where('gate_type', 'requisition_unused_balance_released')->firstOrFail();
        $this->assertSame([$this->releaser->id, '10000.00', '10000.00', '0.00'],
            [$event->user_id, $event->context['amount'], $event->context['before']['to_disburse'], $event->context['after']['to_disburse']]);
    }

    // ── Closure ──────────────────────────────────────────────────────────────

    public function test_closure_is_refused_until_the_requisition_reconciles(): void
    {
        $close = fn () => app(RequisitionClosureService::class)->close($this->requisition->id, $this->finance);
        $lines = $this->lines();

        // Fully paid is not closed.
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->payReceiver([$lines['Transport']], '30000');
        ChartOfAccount::where('code', '1300')->update(['is_postable' => false]);
        $failed = $this->payReceiver([$lines['Materials']], '15000');
        ChartOfAccount::where('code', '1300')->update(['is_postable' => true]);
        $this->assertSame('exception', $this->controls()['control_state']);
        $message = $this->refused($close, 'requisition');
        foreach (['has not confirmed receipt', 'neither paid nor released', 'has not reached the ledger'] as $reason) {
            $this->assertStringContainsString($reason, $message);
        }
        $this->assertFalse($this->controls()['can_close']);
        $this->assertNull($this->requisition->fresh()->closed_at);

        // A posting failure also stops the money it funded being reconciled.
        $this->confirm('Winnie');
        $blocked = $this->account('Winnie', ['Materials' => '15000']);
        $this->assertStringContainsString('has not reached the ledger', $this->refused(fn () => $this->reconcile($blocked), 'accountability'));
        app(\App\Modules\Finance\PettyCash\Services\PettyCashAdvancePoster::class)->attemptPayment($failed);
        $this->reconcile($blocked->fresh());
        $this->assertNotContains('posting_failed', $this->blockerCodes());

        // Unconfirmed receipt, then outstanding accountability, then an unpaid balance.
        $this->assertContains('receipt_unconfirmed', $this->blockerCodes());
        $this->confirm('Steve');
        $this->confirm('Timothy');
        $this->assertEqualsCanonicalizing(['undisbursed_balance', 'accountability_outstanding'], array_values(array_unique($this->blockerCodes())));
        $this->assertSame('partially_disbursed', $this->controls()['control_state']);

        $this->reconcile($this->account('Steve', ['Site facilitation' => '15000', 'Casual support' => '5000']));
        $pending = $this->account('Timothy', ['Transport' => '30000']);
        $this->assertContains('accountability_under_review', $this->blockerCodes());
        $this->assertStringContainsString("Timothy Mwangi's surrender", $this->refused($close, 'requisition'));
        $this->reconcile($pending);

        $this->assertSame(['undisbursed_balance'], $this->blockerCodes());
        $this->assertSame('unused_balance_requires_release', $this->controls()['control_state']);
        $this->assertSame('Winnie Supplies', $this->controls()['closure_blockers'][0]['receiver'], 'The receiver holding up closure is named.');

        app(RequisitionClosureService::class)->release($this->requisition->id, $this->releaser,
            ['receiver_key' => $this->key('Winnie'), 'amount' => '10000', 'reason' => 'Supplier delivered less than ordered', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame([[], 'ready_to_close', true], [$this->blockerCodes(), $this->controls()['control_state'], $this->controls()['can_close']]);

        // The requester, and anyone without reconciliation authority, cannot close.
        $this->actingAs($this->creator, 'sanctum')->postJson($this->url('/close'))->assertForbidden();
        $this->actingAs($this->finance, 'sanctum')->postJson($this->url('/close'))->assertOk()->assertJsonPath('controls.control_state', 'closed');

        $closed = $this->requisition->fresh();
        $this->assertSame([$this->finance->id, 'surrendered'], [$closed->closed_by, $closed->status]);
        // APPROVED = DISBURSED + RELEASED and DISBURSED = ACCEPTED + RETURNED
        $controls = $this->controls();
        $this->assertSame($controls['approved'], bcadd($controls['disbursed'], $controls['released_unused'], 2));
        $this->assertSame($controls['disbursed'], bcadd($controls['accounted'], $controls['returned'], 2));
        $this->postJson($this->url('/close'))->assertOk();   // closing twice changes nothing
        $this->assertSame(1, GovernanceAuditLog::where('gate_type', 'requisition_closed')->count());

        // A closed requisition takes no more money, confirmations, surrenders or releases.
        $this->refused(fn () => $this->payReceiver([$lines['Materials']], '1'), 'requisition_id');
        $this->refused(fn () => $this->confirm('Winnie'), 'requisition');
    }

    public function test_the_brief_worked_example_closes(): void
    {
        $this->accountForEverything();
        $controls = $this->controls();
        $this->assertSame(['75000.00', '75000.00', '75000.00', '71500.00', '3500.00', '0.00', '0.00', '0.00'], [
            $controls['approved'], $controls['disbursed'], $controls['confirmed_received'], $controls['accounted'],
            $controls['returned'], $controls['released_unused'], $controls['to_disburse'], $controls['to_account'],
        ]);
        $this->assertSame(['ready_to_close', 'accounted_in_full', 'confirmed'], [$controls['control_state'], $controls['accountability_status'], $controls['confirmation_status']]);
        app(RequisitionClosureService::class)->close($this->requisition->id, $this->finance);
        $this->assertSame(['closed', 'closed', 'accepted'], [$this->controls()['control_state'], $this->controls()['closure_status'], $this->controls()['accountability_status']]);
    }

    /** One answer for every screen: what happens next, who does it, and whether that is the viewer. */
    public function test_the_next_step_names_one_action_and_who_takes_it(): void
    {
        $next = fn (User $as) => $this->actingAs($as, 'sanctum')->controls()['next_step'];

        $this->assertSame(['pay', true], [$next($this->finance)['key'], $next($this->finance)['can_act']]);
        // The approver is shown the step, not a button.
        $this->assertSame(['pay', false, 'You approved this, so a different Finance user pays it.'],
            [$next($this->approver)['key'], $next($this->approver)['can_act'], $next($this->approver)['hint']]);

        $this->actingAs($this->finance, 'sanctum');
        $this->payEveryone();
        $this->assertSame(['confirm', false], [$next($this->finance)['key'], $next($this->finance)['can_act']]);
        $this->assertStringContainsString('Steve', $next($this->finance)['who']);
        $this->assertSame(['confirm', true], [$next($this->steveUser)['key'], $next($this->steveUser)['can_act']]);

        $this->actingAs($this->finance, 'sanctum');
        foreach (['Steve', 'Timothy', 'Winnie'] as $name) {
            $this->confirm($name);
        }
        $this->assertSame('account', $next($this->finance)['key']);
        $this->assertSame(['done', 'done', 'done', 'done', 'current', 'todo'], array_column($this->controls()['steps'], 'state'));

        // A submitted account waits on Finance while the others are still to account.
        $this->actingAs($this->finance, 'sanctum');
        $steve = $this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]);
        $this->assertSame(['reconcile', true], [$next($this->finance)['key'], $next($this->finance)['can_act']]);
        $this->reconcile($steve);
        $this->reconcile($this->account('Timothy', ['Transport' => '28000'], [['Transport', '2000']]));
        $this->reconcile($this->account('Winnie', ['Materials' => '24000'], [['Materials', '1000']]));

        $this->assertSame(['close', true], [$next($this->finance)['key'], $next($this->finance)['can_act']]);
        $this->assertSame(['close', false], [$next($this->creator)['key'], $next($this->creator)['can_act']]);
        app(RequisitionClosureService::class)->close($this->requisition->id, $this->finance);
        $this->assertSame(['done', 'Closed', false], [$next($this->finance)['key'], $next($this->finance)['label'], $next($this->finance)['can_act']]);
        $this->assertSame(array_fill(0, 6, 'done'), array_column($this->controls()['steps'], 'state'));
    }

    // ── Reversal and correction ──────────────────────────────────────────────

    public function test_a_payment_cannot_be_reversed_from_under_its_accountability(): void
    {
        $payments = $this->payEveryone();
        $this->confirm('Steve');
        $surrender = $this->account('Steve', ['Site facilitation' => '15000', 'Casual support' => '5000']);

        // Submitted is enough: the money is already spoken for.
        try {
            app(PaymentReversalService::class)->reverse($payments['D01'], $this->reverser->id, 'Trying to pull it back');
            $this->fail('A payment under review was reversed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($surrender->reference, $e->getMessage());
        }
        $this->reconcile($surrender);
        $this->actingAs($this->reverser, 'sanctum')->postJson('/api/finance/payments/'.$payments['D01']->id.'/reverse', ['reason' => 'Trying to pull it back'])
            ->assertStatus(422);
        $this->assertSame('active', $payments['D01']->fresh()->status);
        $row = collect($this->controls()['payments'])->firstWhere('id', $payments['D01']->id);
        $this->assertFalse($row['can_reverse'], 'The button is not offered either.');
        $this->assertSame('20000.00', $row['accounted']);

        // The proper chain: reverse the surrender, then the payment may be reversed.
        $this->accountability()->reverse($surrender->id, $this->reverser, 'Receipts were for another job');
        app(PaymentReversalService::class)->reverse($payments['D01'], $this->reverser->id, 'Money was never collected');
        $this->assertSame('voided', $payments['D01']->fresh()->status);
    }

    public function test_reversing_a_reconciled_surrender_compensates_and_deletes_nothing(): void
    {
        $this->projectRequisition();
        app(PettyCashCostProducer::class)->commitFor($this->requisition);
        $this->accountForEverything();
        app(RequisitionClosureService::class)->close($this->requisition->id, $this->finance);
        $surrender = PettyCashSurrender::where('receiver_name', 'Winnie Supplies')->firstOrFail();
        $movementId = $surrender->allocations()->where('kind', 'return')->value('cash_movement_id');

        // Not Finance's ordinary authority, and not the requester.
        $this->postJson($this->url("/accountabilities/{$surrender->id}/reverse"), ['reason' => 'Receipts were for another job'])->assertForbidden();
        $this->actingAs($this->reverser, 'sanctum')->postJson($this->url("/accountabilities/{$surrender->id}/reverse"), ['reason' => 'Receipts were for another job'])->assertOk();

        $surrender->refresh();
        $this->assertSame('reversed', $surrender->status);
        $this->assertSame('reversed', JournalEntry::findOrFail($surrender->journal_entry_id)->status);
        $this->assertSame('voided', CashMovement::findOrFail($movementId)->status);
        $this->assertSame(1, $surrender->allItems()->whereNotNull('superseded_at')->count());
        $this->assertSame(CostLine::STATUS_REVERSED, CostLine::findOrFail($surrender->allItems()->value('cost_line_id'))->status);

        // The requisition is no longer reconciled, so it is no longer closed.
        $reopened = $this->requisition->fresh();
        $this->assertSame([null, 'disbursed'], [$reopened->closed_at, $reopened->status]);
        $winnie = $this->receiver('Winnie');
        $this->assertSame(['0.00', '0.00', '25000.00', 'awaiting_accountability'], [$winnie['accepted'], $winnie['returned'], $winnie['to_account'], $winnie['accountability_state']]);
        $this->assertSame('partially_accounted', $this->controls()['control_state']);
        $this->assertSame(1, GovernanceAuditLog::where('gate_type', 'requisition_reopened')->count());

        // And it can be accounted for again, as a new stage.
        $again = $this->reconcile($this->account('Winnie', ['Materials' => '25000']));
        $this->assertStringEndsWith('-A04', $again->reference);
        $this->assertSame('ready_to_close', $this->controls()['control_state']);
    }

    // ── Authority ────────────────────────────────────────────────────────────

    public function test_who_may_account_and_who_may_reconcile(): void
    {
        $this->payEveryone();
        $this->confirm('Steve', $this->steveUser);
        $this->confirm('Winnie');

        // The receiver accounts for himself; a stranger does not; Finance may enter one for a receiver.
        try {
            $this->account('Winnie', ['Materials' => '1000'], [], $this->verifier);
            $this->fail('The verifier accounted for a receiver.');
        } catch (AuthorizationException) {
        }
        $own = $this->account('Steve', ['Site facilitation' => '15000', 'Casual support' => '5000'], [], $this->steveUser);
        $this->assertSame($this->steveUser->id, $own->submitted_by);

        // Reconciling needs Finance's authority and is never done over one's own money.
        foreach ([$this->creator, $this->verifier, $this->approver] as $user) {
            $this->actingAs($user, 'sanctum')->postJson($this->url("/accountabilities/{$own->id}/reconcile"))->assertForbidden();
        }
        $this->steveUser->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $this->actingAs($this->steveUser->fresh(), 'sanctum')->postJson($this->url("/accountabilities/{$own->id}/reconcile"))->assertStatus(422);
        $this->assertSame('submitted', $own->fresh()->status);

        $this->actingAs($this->finance, 'sanctum')->postJson($this->url("/accountabilities/{$own->id}/reconcile"))->assertOk();
        $this->assertSame($this->finance->id, $own->fresh()->reconciled_by);

        // A surrender belongs to its requisition.
        $this->postJson('/api/finance/petty-cash/requisitions/999999/accountabilities/'.$own->id.'/reconcile')->assertNotFound();
    }

    // ── Story, audit, advance control ────────────────────────────────────────

    public function test_the_story_and_audit_tell_the_whole_life(): void
    {
        $this->payEveryone();
        $this->confirm('Steve', $this->steveUser);
        $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));
        $this->confirm('Timothy');
        $this->reconcile($this->account('Timothy', ['Transport' => '18000']));

        $story = collect($this->controls()['story']);
        $this->assertSame(['created', 'verified', 'approved'], $story->take(3)->pluck('kind')->all());
        $this->assertSame(4, $story->where('kind', 'paid')->count());
        $find = fn (string $text) => $story->first(fn ($e) => str_contains((string) $e['detail'], $text));
        $this->assertSame('Steve Otieno', $find('Steve Otieno confirmed KES 20,000.00')['actor']);
        $this->assertSame('Rita Creator', $find('Timothy Mwangi confirmed KES 30,000.00 (confirmed on their behalf)')['actor']);
        $this->assertSame('Finance Cashier', $find('Steve Otieno accounted KES 19,500.00; returned KES 500.00')['actor']);
        $this->assertSame('Complete', $story->first(fn ($e) => $e['kind'] === 'receiver_position' && $e['title'] === 'Steve Otieno')['detail']);
        $this->assertSame('Still to account — KES 12,000.00', $story->first(fn ($e) => $e['kind'] === 'receiver_position' && $e['title'] === 'Timothy Mwangi')['detail']);
        $this->assertSame('Awaiting receipt confirmation — KES 25,000.00', $story->first(fn ($e) => $e['kind'] === 'receiver_position' && $e['title'] === 'Winnie Supplies')['detail']);
        $this->assertSame('KES 75,000.00 approved; KES 75,000.00 disbursed; KES 37,500.00 accounted; KES 500.00 returned; KES 37,000.00 awaiting accountability',
            $story->last()['detail']);

        $events = GovernanceAuditLog::where('model_type', PettyCashRequisition::class)->where('model_id', $this->requisition->id)->pluck('gate_type');
        foreach (['requisition_receipt_confirmed', 'requisition_accountability_submitted', 'requisition_accountability_reconciled'] as $event) {
            $this->assertContains($event, $events);
        }
        $reconciled = GovernanceAuditLog::where('gate_type', 'requisition_accountability_reconciled')->latest('id')->firstOrFail();
        $this->assertSame([$this->finance->id, '18000.00', '12000.00'], [$reconciled->user_id, $reconciled->context['accepted'], $reconciled->context['to_account_after']]);
        $this->assertNotEmpty($reconciled->context['child_references']);
    }

    public function test_the_advance_account_is_existing_behaviour_until_an_accountant_approves_one(): void
    {
        $payments = $this->payEveryone();
        $advance = (int) ChartOfAccount::where('code', '1300')->value('id');
        foreach ($payments as $payment) {
            $this->assertSame($advance, (int) DB::table('journal_lines')->where('journal_entry_id', $payment->advance_journal_entry_id)->where('entry_type', 'debit')->value('account_id'));
        }
        // Reported as what it is, for each kind of receiver: in use, not yet approved.
        $controls = collect($this->controls()['advance_control'])->keyBy('receiver_type');
        $this->assertSame(['employee', 'other', 'supplier'], $controls->keys()->sort()->values()->all());
        foreach ($controls as $control) {
            $this->assertSame(['1300', 'existing_unapproved'], [$control['account_code'], $control['basis']]);
        }
        // Report 75R-C: WNG's decision (Staff Advances for all) is the proposed answer — proposed, not approved.
        $catalogue = app(\App\Modules\Finance\Governance\GovernanceCatalogue::class);
        foreach (['employee', 'supplier', 'other'] as $type) {
            $item = $catalogue->item("policy.requisition_advance.{$type}");
            $this->assertSame('How should money advanced through a Financial Requisition be controlled before accountability?', $item['question']);
            $this->assertSame(['account_code' => '1300'], $item['suggestion']);
            $this->assertSame('accountant_approval', $item['requirement']);
            $this->assertSame('existing_unapproved', $item['current']['basis']);
        }

        // Once an accountant's answer is in force for suppliers, supplier advances follow it — and only those.
        $other = ChartOfAccount::postable()->where('category', 'asset')->where('code', '!=', '1300')->orderBy('code')->firstOrFail();
        DB::table('finance_config_versions')->insert(['item_key' => 'policy.requisition_advance.supplier', 'domain' => 'policies', 'version' => 1,
            'value' => json_encode(['account_code' => $other->code]), 'status' => 'active', 'revision' => 1,
            'effective_from' => now()->toDateString(), 'in_force_from' => now()->toDateString(), 'proposed_by' => $this->finance->id,
            'created_at' => now(), 'updated_at' => now()]);
        \App\Modules\Finance\Governance\GovernanceRuntime::flush();
        $this->assertSame(['approved', $other->code], [\App\Modules\Finance\Support\RequisitionAdvanceControl::for('supplier')['basis'], \App\Modules\Finance\Support\RequisitionAdvanceControl::for('supplier')['account_code']]);
        $this->assertSame('existing_unapproved', \App\Modules\Finance\Support\RequisitionAdvanceControl::for('employee')['basis']);
    }

    // ── Part-paid exposure ───────────────────────────────────────────────────

    public function test_advance_totals_and_overdue_checks_use_what_is_actually_out(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, 'web');
        $lines = $this->lines();
        $exposure = fn () => (string) (PettyCashRequisition::query()->outstandingAdvances()->whereKey($this->requisition->id)->value('advance_exposure') ?? 'none');
        $overview = fn () => $this->actingAs($this->finance, 'sanctum')->getJson('/api/finance/petty-cash/finance/overview')->assertOk()->json('data.outstanding_advances');
        $list = fn () => $this->getJson('/api/finance/petty-cash/advances/outstanding')->assertOk()->json('data');

        // Approved 75,000, paid 40,000: 40,000 is out, not 75,000.
        $this->payReceiver([$lines['Site facilitation'], $lines['Casual support']], '20000');
        $this->payReceiver([$lines['Transport']], '20000');
        $this->assertSame('40000.00', $exposure());
        $this->assertSame(['count' => 1, 'amount' => '40000.00'], array_intersect_key($overview(), ['count' => 1, 'amount' => 1]));
        $rows = $list();
        $this->assertSame(['40000.00', '75000.00', '40000.00'], [$rows['advances'][0]['amount'], $rows['advances'][0]['approved_amount'], $rows['summary']['amount']]);
        $this->assertSame($this->controls()['to_account'], $exposure(), 'One figure: the SQL total is the position\'s "to account".');

        // Accounting for it reduces what is out; a submitted surrender does not, until reconciled.
        $this->confirm('Steve');
        $surrender = $this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]);
        $this->assertSame('40000.00', $exposure());
        $this->reconcile($surrender);
        $this->assertSame('20000.00', $exposure());
        $this->assertSame($this->controls()['to_account'], $exposure());

        // Overdue: a later requisition by the same requester is checked against what is really out.
        DB::table('petty_cash_requisitions')->where('id', $this->requisition->id)->update(['surrender_due_at' => now()->subDays(3)->toDateString()]);
        $overdue = fn () => PettyCashRequisition::query()->where('user_id', $this->creator->id)->outstandingAdvances()
            ->whereDate('surrender_due_at', '<', now()->toDateString())->count();
        $this->assertSame(1, $overdue(), 'Timothy still holds 20,000 past the due date.');

        $this->confirm('Timothy');
        $this->reconcile($this->account('Timothy', ['Transport' => '20000']));
        // Everything paid is accounted for: nothing is out, so nothing is overdue — though 35,000 is still unpaid.
        $this->assertSame('none', $exposure());
        $this->assertSame(0, $overdue());
        $this->assertSame(0, $overview()['count']);
        $this->assertSame([], $list()['advances']);
        $this->assertSame('35000.00', $this->controls()['to_disburse']);
    }

    // ── Legacy and existing data ─────────────────────────────────────────────

    public function test_a_single_payment_requisition_is_untouched_by_receiver_accountability(): void
    {
        // One typed payee, paid as one payment: the long-standing form.
        $legacy = PettyCashRequisition::create(['requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
            'user_id' => $this->creator->id, 'responsible_verifier_id' => $this->verifier->id, 'department_id' => $this->departmentId,
            'category' => 'Operations', 'purpose' => 'Office supplies', 'total_amount' => '4000.00', 'status' => 'pending', 'payee_name' => 'Sam']);
        $legacy->items()->create(['description' => 'Office supplies', 'amount' => '4000.00', 'payee_name' => 'Sam']);
        $verification = app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class);
        $verification->submit($legacy, $this->creator->id);
        $verification->review($legacy->id, $this->verifier, 'verified', null);
        $legacy->refresh()->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->approver->id])->save();

        $this->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/disburse', [
            'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->expenseCodeId, 'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'bank_transfer', 'external_reference' => 'TRX-LEGACY', 'amount' => '4000.00', 'payee_name' => 'Sam',
            'description' => 'Office supplies', 'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
        ])->assertOk();

        $payment = Payment::where('requisition_id', $legacy->id)->firstOrFail();
        $this->assertNull($payment->requisition_child_reference);
        $controls = app(\App\Modules\Finance\PettyCash\Services\RequisitionControlProjection::class)->forRequisition($legacy->fresh());
        $this->assertSame(['single_payment', 'awaiting_receipt_confirmation', [], 'not_evaluated'],
            [$controls['payment_mode'], $controls['control_state'], $controls['closure_blockers'], $controls['closure_status']]);
        $this->assertNull($controls['receivers'][0]['paid'], 'A single payment is not apportioned to receivers.');
        $this->assertFalse($controls['can_close']);

        // The receiver actions are not for it; its own receipt and surrender still are.
        $this->refused(fn () => $this->accountability()->confirmReceipt($legacy->id, $this->creator, ['receiver_key' => 'employee:1', 'evidence_reference' => 'X']), 'receiver_key');
        $this->refused(fn () => app(RequisitionClosureService::class)->close($legacy->id, $this->finance), 'requisition');
        $this->actingAs($this->creator, 'sanctum')->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/confirm-receipt', ['signature' => 'data:signed'])->assertOk();
        $this->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/surrender', [
            'items' => [['expense_code_id' => $this->expenseCodeId, 'amount' => '3500', 'receipt_type' => 'none', 'description' => 'Stationery']],
            'cash_returned_amount' => '500',
        ])->assertOk();
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/finance/petty-cash/requisitions/'.$legacy->id.'/reconcile')->assertOk();
        $this->assertSame('surrendered', $legacy->fresh()->status);
        // Its outstanding-advance figure is still the whole amount until reconciled, and gone after.
        $this->assertSame(0, PettyCashRequisition::query()->outstandingAdvances()->whereKey($legacy->id)->count());
    }

    public function test_single_payment_readers_show_every_receiver_payment(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, 'web');
        $payments = $this->payEveryone($this->secondSourceId);
        app(PaymentReversalService::class)->reverse($payments['D04'], $this->reverser->id, 'Sent to the wrong supplier');

        // Finance workspace detail: the total and every payment, not D01 standing for all.
        $detail = $this->getJson('/api/finance/petty-cash/finance/requisitions/'.$this->requisition->id)->assertOk()->json('data');
        $this->assertSame('50000.00', number_format((float) $detail['disbursement']['amount'], 2, '.', ''));
        $this->assertSame('3 payments', $detail['disbursement']['reference']);
        $this->assertSame('2 paying accounts', $detail['disbursement']['source']['name']);
        $this->assertCount(3, $detail['disbursements']);
        $this->assertSame(['D01', 'D02', 'D03'], array_map(fn ($p) => substr($p['child_reference'], -3), $detail['disbursements']));
        $this->assertSame('50000.00', number_format((float) $detail['surrender']['advance'], 2, '.', ''));
        $this->assertSame(3, $detail['disbursed']['payments']);

        // Voucher: every payment, the reversed one marked, and the total against approved.
        $requisition = PettyCashRequisition::with(['requester', 'department', 'approver', 'payee', 'project.enquiry', 'enquiry', 'items.payee',
            'disbursement', 'disbursements' => fn ($q) => $q->orderBy('id')])->findOrFail($this->requisition->id);
        $html = view('reports.finance.requisition-voucher', compact('requisition'))->render();
        foreach (['Paid D01', 'Paid D02', 'Paid D03', 'Reversed D04', $payments['D03']->payment_no, 'KES 50,000.00 of KES 75,000.00 approved'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_existing_ambiguous_receivers_are_reported_and_never_guessed(): void
    {
        $make = function (string $purpose, array $lines, array $attributes = []) {
            $r = PettyCashRequisition::create(['requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
                'user_id' => $this->creator->id, 'department_id' => $this->departmentId, 'category' => 'Operations', 'purpose' => $purpose,
                'total_amount' => number_format(array_sum(array_column($lines, 'amount')), 2, '.', ''), 'status' => 'pending'] + $attributes);
            $r->items()->createMany($lines);

            return $r;
        };
        // As they exist in old data: typed names, no identities, no verification.
        $twoNames = $make('Casuals', [['description' => 'Day 1', 'amount' => 1000, 'payee_name' => 'John Kamau'], ['description' => 'Day 2', 'amount' => 1000, 'payee_name' => 'John Kamau Jr']]);
        $oneName = $make('Fuel', [['description' => 'Fuel', 'amount' => 2000, 'payee_name' => 'Sam']], ['payee_name' => 'Sam']);
        $mismatch = $make('Odd', [['description' => 'Odd', 'amount' => 500, 'payee_name' => 'Sam']]);
        DB::table('petty_cash_requisitions')->where('id', $mismatch->id)->update(['total_amount' => '900.00']);
        $before = DB::table('petty_cash_requisition_items')->whereIn('requisition_id', [$twoNames->id, $oneName->id, $mismatch->id])->orderBy('id')->get()->toJson();

        $report = collect(app(\App\Modules\Finance\PettyCash\Services\RequisitionReceiverCompatibility::class)->report())->keyBy('id');
        $this->assertSame('REQUIRES RECEIVER IDENTIFICATION', $report[$twoNames->id]['classification']);
        $this->assertSame(['John Kamau', 'John Kamau Jr'], $report[$twoNames->id]['receivers_named_only'], 'Similar names stay two names.');
        $this->assertSame('REQUIRES RE-VERIFICATION', $report[$oneName->id]['classification']);
        $this->assertSame('MANUAL REVIEW', $report[$mismatch->id]['classification']);
        $this->assertSame('READY FOR PAYMENT BY RECEIVER', $report[$this->requisition->id]['classification']);

        // Verified but not approved, then approved.
        $verification = app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class);
        $oneName->forceFill(['responsible_verifier_id' => $this->verifier->id])->save();
        $verification->submit($oneName->fresh(), $this->creator->id);
        $verification->review($oneName->id, $this->verifier, 'verified', null);
        $classify = fn () => app(\App\Modules\Finance\PettyCash\Services\RequisitionReceiverCompatibility::class)->classify($oneName->fresh()->load('items'))['classification'];
        $this->assertSame('REQUIRES RE-APPROVAL', $classify());
        $oneName->refresh()->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $this->assertSame('READY FOR LEGACY SINGLE PAYMENT', $classify());

        // Read-only, through the command and the endpoint as well.
        $this->artisan('finance:requisition-receiver-compatibility')->assertSuccessful();
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, 'web');
        $this->getJson('/api/finance/petty-cash/finance/receiver-compatibility')->assertOk()->assertJsonPath('summary.MANUAL REVIEW', 1);
        $this->assertSame($before, DB::table('petty_cash_requisition_items')->whereIn('requisition_id', [$twoNames->id, $oneName->id, $mismatch->id])->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('petty_cash_requisition_items')->whereIn('requisition_id', [$twoNames->id])->whereNotNull('other_recipient_reference')->count());
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    public function test_reports_answer_who_holds_what_and_what_blocks_closure(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, 'web');
        $payments = $this->payEveryone($this->secondSourceId);
        $this->confirm('Steve');
        $this->reconcile($this->account('Steve', ['Site facilitation' => '14500', 'Casual support' => '5000'], [['Site facilitation', '500']]));
        $this->confirm('Timothy');
        $this->reconcile($this->account('Timothy', ['Transport' => '18000']));

        $register = collect($this->getJson('/api/finance/petty-cash/finance/requisition-payments?requisition_id='.$this->requisition->id)->assertOk()->json('data'))->keyBy('child_reference');
        $d = fn (string $n) => $register[$this->requisition->requisition_number.'-'.$n];
        $this->assertSame(['Steve Otieno', 'Finance Cashier', '20000.00', '500.00', '0.00'],
            [$d('D01')['receiver']['name'], $d('D01')['paid_by'], $d('D01')['accounted'], $d('D01')['returned'], $d('D01')['to_account']]);
        $this->assertSame('Rita Creator', $d('D01')['receipt']['confirmed_by']);
        $this->assertSame(['18000.00', '2000.00'], [$d('D02')['accounted'], $d('D02')['to_account']]);
        $this->assertSame(['0.00', '10000.00'], [$d('D03')['accounted'], $d('D03')['to_account']]);
        $this->assertNotSame($d('D02')['source']['id'], $d('D03')['source']['id'], 'Each payment names the account it left.');
        $this->assertNull($d('D04')['receipt']);
        $this->assertCount(1, $this->getJson('/api/finance/petty-cash/finance/requisition-payments?receipt=unconfirmed')->json('data'));
        $this->assertCount(3, $this->getJson('/api/finance/petty-cash/finance/requisition-payments?receipt=confirmed')->json('data'));

        $row = $this->getJson('/api/finance/petty-cash/finance/receiver-balances')->assertOk()->json('data.0');
        $this->assertSame(['75000.00', '75000.00', '50000.00', '37500.00', '500.00', '0.00', '37000.00', 'awaiting_receipt_confirmation'], [
            $row['approved'], $row['disbursed'], $row['confirmed_received'], $row['accounted'], $row['returned'],
            $row['released_unused'], $row['to_account'], $row['control_state'],
        ]);
        $this->assertSame(['Winnie Verifier', 'Finance Approver'], [$row['verified_by'], $row['approved_by']]);
        $this->assertSame(['complete', 'partially_accounted', 'awaiting_receipt_confirmation'], array_column($row['receivers'], 'accountability_state'));
        $holding = collect($row['closure_blockers'])->pluck('receiver')->filter()->unique()->values()->all();
        $this->assertSame(['Timothy Mwangi', 'Winnie Supplies'], $holding, 'The receivers holding up closure are named; Steve is not.');
        $this->assertNotNull($payments);
    }

    private function url(string $suffix): string
    {
        return '/api/finance/petty-cash/requisitions/'.$this->requisition->id.$suffix;
    }
}
