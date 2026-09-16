<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\SpendVoucherAllocation;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $approver;
    private User $poster;
    private JournalPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingService = $this->app->make(JournalPostingService::class);

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);

        foreach ([
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
            Permissions::FINANCE_SPEND_VOUCHERS_POST,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user->givePermissionTo([Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_COSTS_READ]);
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_CREATE);

        $this->approver = User::factory()->create(['is_active' => true]);
        $this->approver->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        $this->poster = User::factory()->create(['is_active' => true]);
        $this->poster->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_POST);
    }

    public function test_journal_posting_service_posts_cost_line(): void
    {
        $line = CostLine::create([
            'ref' => 'CL-999',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '5000.00',
            'tax_amount' => '0.00',
            'net_amount' => '5000.00',
            'base_net_amount' => '5000.00',
            'fx_rate' => '1.00',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'submitted_by_user_id' => $this->user->id,
        ]);

        $entry = $this->postingService->postCostLine($line);

        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->status);
        $this->assertEquals('5000.00', $entry->total_debit);
        $this->assertEquals('5000.00', $entry->total_credit);

        // Check journal lines
        $lines = $entry->lines;
        $this->assertCount(2, $lines);
        $this->assertSame('debit', $lines[0]->entry_type);
        $this->assertSame('credit', $lines[1]->entry_type);

        $this->assertEquals('5000.00', $lines[0]->amount);
        $this->assertEquals('5000.00', $lines[1]->amount);
    }

    public function test_spend_voucher_endpoints_flow(): void
    {
        $source = PaymentSource::create([
            'name' => 'Operating Bank',
            'code' => 'BANK-TEST',
            'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($this->user, 'sanctum');

        $liability = CostLine::create([
            'ref' => 'CL-PAY-001',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '15000.00',
            'tax_amount' => '0.00',
            'net_amount' => '15000.00',
            'base_net_amount' => '15000.00',
            'fx_rate' => '1.00',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'submitted_by_user_id' => $this->user->id,
        ]);
        $this->postingService->postCostLine($liability);

        $this->getJson('/api/finance/spend-vouchers/eligible-liabilities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $liability->id)
            ->assertJsonPath('data.0.payable_amount', '15000.00');

        // Create Voucher
        $response = $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 6000.00,
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 6000.00]],
        ]);

        $response->assertStatus(201);
        $voucherId = $response->json('data.id');
        $this->assertSame('pending_approval', $response->json('data.status'));

        $this->getJson('/api/finance/spend-vouchers/eligible-liabilities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $liability->id)
            ->assertJsonPath('data.0.allocated_amount', '6000.00')
            ->assertJsonPath('data.0.remaining_amount', '9000.00');

        $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 10000.00,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 10000.00]],
        ])->assertStatus(422)->assertJsonPath('message', 'Allocation 10000 exceeds the remaining balance 9000.00 on CL-PAY-001.');

        // Approve Voucher
        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve");
        $response->assertOk();
        $this->assertSame('approved', $response->json('data.status'));

        // Post Voucher to GL
        $response = $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post");
        $response->assertOk();
        $this->assertSame('posted', $response->json('data.voucher.status'));

        $this->assertDatabaseHas('journal_entries', [
            'spend_voucher_id' => $voucherId,
            'status' => 'posted',
            'total_debit' => '6000.00',
            'total_credit' => '6000.00',
        ]);
        $this->assertDatabaseHas('spend_voucher_allocations', [
            'spend_voucher_id' => $voucherId,
            'cost_line_id' => $liability->id,
            'amount' => '6000.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => JournalEntry::where('spend_voucher_id', $voucherId)->value('id'),
            'account_id' => ChartOfAccount::where('code', '2100')->value('id'),
            'entry_type' => 'debit',
            'amount' => '6000.00',
        ]);

        // Cash fact: posting mints one Payment linked both ways, without a
        // second project cost.
        $this->assertDatabaseHas('payments', [
            'spend_voucher_id' => $voucherId,
            'payment_source_id' => $source->id,
            'amount' => '6000.00',
            'status' => 'active',
        ]);
        $this->assertNotNull($response->json('data.payment.payment_no'));
        $this->assertDatabaseHas('spend_vouchers', [
            'id' => $voucherId,
            'petty_cash_disbursement_id' => $response->json('data.payment.id'),
        ]);

        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'spend_voucher_created',
            'model_type' => SpendVoucher::class,
            'model_id' => $voucherId,
        ]);

        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'spend_voucher_approved',
            'model_type' => SpendVoucher::class,
            'model_id' => $voucherId,
        ]);

        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'spend_voucher_posted',
            'model_type' => SpendVoucher::class,
            'model_id' => $voucherId,
        ]);
    }

    /**
     * The generic journal-reversal screen must refuse a voucher's journal and
     * point at the atomic path instead — PaymentReversalService is what
     * actually owns reversing a voucher's Payment, journal, status and (for
     * petty cash) float together. This proves that path is real, not just
     * plausible-looking: reversing the Payment must flip the voucher back to
     * `reversed`, void the Payment, reverse its journal, and free the
     * liability for a fresh voucher — all in one call, nothing left dangling.
     */
    public function test_reversing_a_posted_vouchers_payment_restores_everything_atomically(): void
    {
        $source = PaymentSource::create([
            'name' => 'Reversal Test Bank', 'code' => 'BANK-REVERSAL', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);
        $liability = $this->verifiedLiability('CL-REVERSE-001', '1000.00');

        $voucherId = $this->actingAs($this->user, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 1000.00,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 1000.00]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")->assertOk();
        $posted = $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertOk();
        $paymentId = $posted->json('data.payment.id');

        // The generic journal-reversal endpoint must refuse this journal.
        $journalId = JournalEntry::where('spend_voucher_id', $voucherId)->value('id');
        Permission::findOrCreate(Permissions::FINANCE_JOURNALS_REVERSE, 'web');
        $this->user->givePermissionTo(Permissions::FINANCE_JOURNALS_REVERSE);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/finance/journals/{$journalId}/reverse", ['reason' => 'Attempting the wrong path.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This journal belongs to a Payment. Reverse the Payment so its liability, voucher, cashbook and audit trail are corrected atomically.');

        // Fully allocated: invisible to a new voucher until reversed.
        $before = $this->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk();
        $this->assertFalse(collect($before->json('data'))->contains('id', $liability->id));

        Permission::findOrCreate(Permissions::FINANCE_PAYMENTS_REVERSE, 'web');
        $reverser = User::factory()->create(['is_active' => true]);
        $reverser->givePermissionTo(Permissions::FINANCE_PAYMENTS_REVERSE);

        $this->actingAs($reverser, 'sanctum')
            ->postJson("/api/finance/payments/{$paymentId}/reverse", [
                'reason' => 'Posted against the wrong liability, correcting.',
            ])->assertOk();

        $this->assertSame('reversed', SpendVoucher::find($voucherId)->status);
        $this->assertSame('voided', \App\Modules\Finance\Models\Payment::find($paymentId)->status);
        $this->assertSame('reversed', JournalEntry::find($journalId)->status);

        // The liability is payable again, for a full KES 1,000 — nothing was
        // left partially allocated by the reversed voucher.
        $after = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk();
        $line = collect($after->json('data'))->firstWhere('id', $liability->id);
        $this->assertNotNull($line, 'The liability must become payable again once the voucher that claimed it is reversed.');
        $this->assertSame('1000.00', $line['remaining_amount']);
    }

    /**
     * A verified, posted cost line — the only kind a payment/reimbursement
     * voucher can allocate against, now that those are the only two types a
     * voucher can be created as.
     */
    private function verifiedLiability(string $ref, string $amount, ?int $userId = null): CostLine
    {
        $liability = CostLine::create([
            'ref' => $ref,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => $amount,
            'tax_amount' => '0.00',
            'net_amount' => $amount,
            'base_net_amount' => $amount,
            'fx_rate' => '1.00',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'submitted_by_user_id' => $userId ?? $this->user->id,
        ]);
        $this->postingService->postCostLine($liability);

        return $liability;
    }

    /** Every column the table requires, so callers name only what they vary. */
    private function voucher(array $overrides = []): SpendVoucher
    {
        return SpendVoucher::create(array_merge([
            'voucher_no' => 'SV-' . uniqid(),
            'type' => 'retirement',
            'status' => 'pending_approval',
            'transacted_at' => now(),
            'posting_date' => now()->toDateString(),
            'payee_name' => 'Test Supplier',
            'requester_user_id' => $this->user->id,
            'total_amount' => '1000.00',
            'base_total_amount' => '1000.00',
            'net_amount' => '1000.00',
            'net_cash_paid' => '1000.00',
        ], $overrides));
    }

    /**
     * Recording the fee on the Payment row isn't posting it — the only other
     * caller of postPaymentFee() is a queued listener on an event vouchers
     * deliberately never fire. Without wiring it into post() directly, a
     * voucher's transaction fee would sit as data forever, debited from the
     * bank in reality but never reaching 7800 in the GL.
     */
    public function test_posting_a_voucher_with_a_transaction_fee_debits_bank_charges(): void
    {
        $source = PaymentSource::create([
            'name' => 'Fee Test Bank', 'code' => 'BANK-FEE', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);
        $liability = $this->verifiedLiability('CL-FEE-001', '1000.00');

        $voucherId = $this->actingAs($this->user, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 1000.00,
            'transaction_cost' => 25.00,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 1000.00]],
        ])->assertStatus(201)->json('data.id');

        $this->assertSame('25.00', (string) \App\Modules\Finance\Models\SpendVoucher::find($voucherId)->transaction_cost);

        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")->assertOk();
        $posted = $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertOk();

        $paymentId = $posted->json('data.payment.id');
        $payment = \App\Modules\Finance\Models\Payment::find($paymentId);
        $this->assertSame('25.00', (string) $payment->transaction_cost);

        $feeEntry = JournalEntry::where('entry_no', 'JE-PFEE-'.str_pad((string) $paymentId, 7, '0', STR_PAD_LEFT))->first();
        $this->assertNotNull($feeEntry, 'The transaction fee must post its own journal entry.');
        $this->assertSame('25.00', (string) $feeEntry->total_debit);

        $chargesAccountId = ChartOfAccount::where('code', '7800')->value('id');
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $feeEntry->id,
            'account_id' => $chargesAccountId,
            'entry_type' => 'debit',
            'amount' => '25.00',
        ]);
    }

    public function test_listing_vouchers_does_not_blow_up_once_one_exists(): void
    {
        // The regression this file did not have. index() eager-loaded a
        // `costLines` relation pointing at `cost_lines.spend_voucher_id`, a
        // column that does not exist, so the endpoint returned 500 the moment a
        // voucher was present. Every existing test posted or approved a voucher
        // by id and never listed them, and the client logged the failure to the
        // console, so nothing surfaced it.
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);
        $this->voucher();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_showing_a_voucher_does_not_blow_up(): void
    {
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);
        $voucher = $this->voucher();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/finance/spend-vouchers/{$voucher->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $voucher->id);
    }

    public function test_the_index_summary_counts_every_voucher_not_just_the_page(): void
    {
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);

        foreach (range(1, 30) as $i) {
            $this->voucher(['voucher_no' => 'SV-BULK-' . $i, 'payee_name' => 'Supplier ' . $i]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers')
            ->assertOk();

        // One page of 25 came back...
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(30, $response->json('meta.total'));

        // ...but the headline figures describe all 30. The client used to reduce
        // the page it happened to receive, so this read 25.
        $this->assertSame(30, $response->json('summary.total'));
        $this->assertSame(30, $response->json('summary.pending_approval'));
        $this->assertSame(30, $response->json('summary.draft'));
    }

    public function test_the_index_filters_server_side(): void
    {
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);

        $this->voucher(['voucher_no' => 'SV-FIND-ME', 'payee_name' => 'Findable Supplier']);
        $this->voucher(['voucher_no' => 'SV-OTHER', 'type' => 'advance', 'status' => 'approved']);

        $searched = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers?search=FIND-ME')->assertOk();
        $this->assertSame(1, $searched->json('meta.total'));

        $byStatus = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers?status=approved')->assertOk();
        $this->assertSame(1, $byStatus->json('meta.total'));
        $this->assertSame('SV-OTHER', $byStatus->json('data.0.voucher_no'));
    }

    public function test_payment_sources_are_listed_for_the_voucher_form(): void
    {
        PaymentSource::create([
            'name' => 'Main Safe', 'code' => 'SAFE-01', 'type' => 'petty_cash',
            'gl_account_id' => ChartOfAccount::where('code', '1030')->value('id'),
            'is_active' => true,
        ]);
        PaymentSource::create([
            'name' => 'Retired Account', 'code' => 'OLD-01', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1030')->value('id'),
            'is_active' => false,
        ]);

        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/payment-sources')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Main Safe', $names);
        $this->assertNotContains('Retired Account', $names);
    }

    /**
     * Reading the paying accounts needs no finance permission.
     *
     * It used to need finance.spend_vouchers.read, and three other copies of the
     * same list each needed a different one — which is why procurement's bill
     * payment screen had its own endpoint. Anyone recording a payment has to be
     * able to name the account it left. Opening one is still gated.
     */
    public function test_any_authenticated_user_may_read_the_paying_accounts(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/finance/payment-sources')
            ->assertOk()
            ->assertJsonPath('meta.can_manage', false);
    }

    public function test_payment_vouchers_are_listed_at_the_spend_vouchers_url(): void
    {
        // "Payment Voucher" is the UI label only; there is one implementation
        // and one API path (spend-vouchers) behind it — see
        // erp-payment-voucher-naming-collapse.
        $this->user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers')
            ->assertOk();
    }

    public function test_payment_context_excludes_liabilities_and_inactive_sources(): void
    {
        $accountId = ChartOfAccount::where('code', '1030')->value('id');

        PaymentSource::create([
            'name' => 'Operational Bank', 'code' => 'BANK-USABLE', 'type' => 'bank',
            'gl_account_id' => $accountId, 'is_active' => true,
        ]);
        PaymentSource::create([
            'name' => 'Supplier Credit', 'code' => 'AP-NOT-CASH', 'type' => 'payable',
            'gl_account_id' => $accountId, 'is_active' => true,
        ]);
        PaymentSource::create([
            'name' => 'Closed Bank', 'code' => 'BANK-CLOSED', 'type' => 'bank',
            'gl_account_id' => $accountId, 'is_active' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/payment-sources?for=payment')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertContains('BANK-USABLE', $codes);
        $this->assertNotContains('AP-NOT-CASH', $codes);
        $this->assertNotContains('BANK-CLOSED', $codes);
    }

    public function test_a_voucher_carries_the_period_of_its_posting_date(): void
    {
        // Left null on every voucher until now, which meant voucher journals
        // belonged to no period and could not be swept up by a period close.
        $this->actingAs($this->user, 'sanctum');
        $source = PaymentSource::create([
            'name' => 'Period Test Bank', 'code' => 'BANK-PERIOD', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);

        $liability = $this->verifiedLiability('CL-PERIOD-001', '1000.00');

        $response = $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 1000.00,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 1000.00]],
        ]);

        $response->assertStatus(201);

        $period = AccountingPeriod::forDate(now());
        $this->assertNotNull($period);
        $this->assertDatabaseHas('spend_vouchers', [
            'id' => $response->json('data.id'),
            'accounting_period_id' => $period->id,
        ]);
    }

    public function test_a_voucher_cannot_be_posted_into_a_locked_period(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $source = PaymentSource::create([
            'name' => 'Locked Period Bank', 'code' => 'BANK-LOCKED', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);

        $liability = $this->verifiedLiability('CL-LOCKED-001', '1000.00');

        $voucherId = $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 1000.00,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $liability->id, 'amount' => 1000.00]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")
            ->assertOk();

        AccountingPeriod::forDate(now())->update(['status' => AccountingPeriod::STATUS_LOCKED]);

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post")
            ->assertStatus(422);

        // Nothing reached the ledger, and the voucher is still postable once the
        // period is reopened.
        $this->assertDatabaseMissing('journal_entries', ['spend_voucher_id' => $voucherId]);
        $this->assertDatabaseHas('spend_vouchers', ['id' => $voucherId, 'status' => 'approved']);
    }

    public function test_a_draft_voucher_cannot_be_posted(): void
    {
        $voucher = SpendVoucher::create([
            'voucher_no' => 'SV-TEST-DRAFT',
            'type' => 'payment',
            'status' => 'draft',
            'transacted_at' => now(),
            'posting_date' => now()->toDateString(),
            'payee_name' => 'Test Supplier',
            'requester_user_id' => $this->user->id,
            'total_amount' => '1000.00',
            'base_total_amount' => '1000.00',
            'net_amount' => '1000.00',
            'net_cash_paid' => '1000.00',
        ]);

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertStatus(422);

        $this->assertSame('draft', $voucher->fresh()->status);
        $this->assertDatabaseMissing('journal_entries', ['spend_voucher_id' => $voucher->id]);
    }

    public function test_posting_rolls_back_when_no_gl_accounts_can_be_resolved(): void
    {
        $bankAccountId = ChartOfAccount::where('code', '1010')->value('id');
        $source = PaymentSource::create([
            'name' => 'Unmapped Test Bank', 'code' => 'BANK-NO-GL', 'type' => 'bank',
            'gl_account_id' => $bankAccountId, 'is_active' => true,
        ]);

        $liability = $this->verifiedLiability('CL-NO-GL-001', '1000.00');

        $voucher = SpendVoucher::create([
            'voucher_no' => 'SV-TEST-NO-GL',
            'type' => 'payment',
            'status' => 'approved',
            'transacted_at' => now(),
            'posting_date' => now()->toDateString(),
            'payee_name' => 'Test Supplier',
            'requester_user_id' => $this->user->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'total_amount' => '1000.00',
            'base_total_amount' => '1000.00',
            'net_amount' => '1000.00',
            'net_cash_paid' => '1000.00',
            'payment_source_id' => $source->id,
        ]);
        SpendVoucherAllocation::create([
            'spend_voucher_id' => $voucher->id,
            'cost_line_id' => $liability->id,
            'amount' => '1000.00',
        ]);

        // No control account remains postable, so the liability this voucher
        // settles can no longer be traced to one — resolveVerifiedLiabilityAccount's
        // reconciliation guard is what now catches an unresolvable GL setup,
        // in place of the old types' "return an empty debit-leg array" path.
        ChartOfAccount::query()->update(['is_postable' => false]);

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                "Spend voucher {$voucher->voucher_no}: cost line {$liability->ref} does not reconcile to its payable journal.",
            );

        $this->assertSame('approved', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->posted_at);
        $this->assertDatabaseMissing('journal_entries', ['spend_voucher_id' => $voucher->id]);
    }

    /**
     * SpendVoucherController::store() refuses a `payable`-type payment source
     * at request time (see CostSettlementModeTest), but this voucher is built
     * directly on the model the way a pre-existing row or a future caller
     * could, to prove the settlement service itself — not just the request
     * validation — refuses to mint a Payment against a liability account.
     */
    public function test_posting_refuses_a_payment_source_that_is_itself_a_liability(): void
    {
        $apSource = PaymentSource::create([
            'name' => 'Supplier Credit (Payable)', 'code' => 'AP-TEST', 'type' => 'payable',
            'gl_account_id' => ChartOfAccount::where('code', '2100')->value('id'), 'is_active' => true,
        ]);

        $voucher = SpendVoucher::create([
            'voucher_no' => 'SV-TEST-AP-SOURCE',
            'type' => 'advance',
            'status' => 'approved',
            'transacted_at' => now(),
            'posting_date' => now()->toDateString(),
            'payee_name' => 'Test Supplier',
            'requester_user_id' => $this->user->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'total_amount' => '1000.00',
            'base_total_amount' => '1000.00',
            'net_amount' => '1000.00',
            'net_cash_paid' => '1000.00',
            'payment_source_id' => $apSource->id,
        ]);

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Supplier Credit is a liability account, not a paying account. Select the bank, float, mobile money or card the money actually left from.',
            );

        $this->assertSame('approved', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->posted_at);
        $this->assertDatabaseMissing('payments', ['spend_voucher_id' => $voucher->id]);
        $this->assertDatabaseMissing('journal_entries', ['spend_voucher_id' => $voucher->id]);
    }

    /**
     * Brief §6.3's petty-cash cap, approved. Unapproved it is a no-op — see
     * PettyCashCap — so a voucher of any size posts exactly as it always has
     * while the seeded default sits unsigned; this pins the enforced side.
     */
    public function test_posting_refuses_a_petty_cash_voucher_over_the_approved_cap(): void
    {
        $float = PaymentSource::create([
            'name' => 'Main Petty Cash Float', 'code' => 'PC-CAP-TEST', 'type' => 'petty_cash',
            'gl_account_id' => ChartOfAccount::where('code', '1030')->value('id'), 'is_active' => true,
        ]);
        \App\Modules\Finance\PettyCash\Models\PettyCashBalance::current()
            ->update(['current_balance' => 100000.00]);

        $approver = User::factory()->create(['is_active' => true]);
        DB::table('finance_settings')->insert([
            'key' => 'petty_cash_max_per_transaction', 'value' => '20000', 'label' => 'Petty cash cap',
            'effective_from' => '2020-01-01', 'approved_by' => $approver->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $voucher = $this->voucher([
            'type' => 'advance',
            'status' => 'approved',
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'total_amount' => '30000.00',
            'base_total_amount' => '30000.00',
            'net_amount' => '30000.00',
            'net_cash_paid' => '30000.00',
            'payment_source_id' => $float->id,
        ]);

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertUnprocessable();

        $this->assertSame('approved', $voucher->fresh()->status);
        $this->assertDatabaseMissing('payments', ['spend_voucher_id' => $voucher->id]);
        $this->assertSame(
            '100000.00',
            (string) \App\Modules\Finance\PettyCash\Models\PettyCashBalance::current()->current_balance,
        );
    }

    public function test_spend_voucher_actions_require_their_own_permissions(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/finance/spend-vouchers', [
                'type' => 'payment',
                'payee_name' => 'Test Supplier',
                'total_amount' => 1000,
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_post_a_voucher_they_requested_or_approved(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $superAdmin = User::factory()->create(['is_active' => true]);
        $superAdmin->assignRole('Super Admin');

        $source = PaymentSource::create([
            'name' => 'Admin Bank',
            'code' => 'BANK-ADMIN',
            'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'),
            'is_active' => true,
        ]);
        $liability = $this->verifiedLiability('CL-ADMIN-001', '1000.00', $superAdmin->id);

        $voucher = $this->voucher([
            'voucher_no' => 'SV-SUPER-POST',
            'type' => 'payment',
            'status' => 'approved',
            'payment_source_id' => $source->id,
            'requester_user_id' => $superAdmin->id,
            'approved_by' => $superAdmin->id,
            'approved_at' => now(),
        ]);
        SpendVoucherAllocation::create([
            'spend_voucher_id' => $voucher->id,
            'cost_line_id' => $liability->id,
            'amount' => '1000.00',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertOk()
            ->assertJsonPath('data.voucher.status', 'posted');

        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'spend_voucher_posted',
            'model_id' => $voucher->id,
        ]);
        $this->assertStringContainsString(
            'Separation-of-duties override used.',
            (string) DB::table('hr_audit_logs')->where('action', 'spend_voucher_posted')
                ->where('model_id', $voucher->id)->value('message'),
        );
    }
}
