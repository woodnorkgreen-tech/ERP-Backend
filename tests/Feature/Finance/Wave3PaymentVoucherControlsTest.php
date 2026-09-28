<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 2B Wave 3 — W4 Payment Vouchers: Return for Correction and Reject
 * (W4-1), the value-based senior approval mechanism (W4-2), and the
 * settlement invariant that paying a cost never recognises it a second time.
 */
class Wave3PaymentVoucherControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;
    private User $approver;
    private User $poster;
    private User $senior;
    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);

        foreach ([
            Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_POST,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->requester = User::factory()->create(['is_active' => true]);
        $this->requester->givePermissionTo([Permissions::FINANCE_SPEND_VOUCHERS_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_READ]);
        $this->approver = User::factory()->create(['is_active' => true]);
        $this->approver->givePermissionTo([Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_READ]);
        $this->poster = User::factory()->create(['is_active' => true]);
        $this->poster->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_POST);
        $this->senior = User::factory()->create(['is_active' => true]);
        $this->senior->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR);

        $this->bank = PaymentSource::create([
            'name' => 'Operating Bank', 'code' => 'BANK-W3', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);
    }

    private function liability(string $ref, string $amount): CostLine
    {
        $line = CostLine::create([
            'ref' => $ref, 'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => $amount, 'tax_amount' => '0.00', 'net_amount' => $amount, 'base_net_amount' => $amount,
            'fx_rate' => '1.00', 'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'submitted_by_user_id' => $this->requester->id,
        ]);
        app(JournalPostingService::class)->postCostLine($line);

        return $line;
    }

    private function createVoucher(CostLine $line, string $amount): int
    {
        return $this->actingAs($this->requester, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Supplier', 'total_amount' => (float) $amount,
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id,
            'allocations' => [['cost_line_id' => $line->id, 'amount' => (float) $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function approveAndPost(int $voucherId): void
    {
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")->assertOk();
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertOk();
    }

    /**
     * Debits to the account(s) the cost lines recognised their cost in — the
     * ledger's own measure. A payment debits the payable, never these.
     */
    private function expenseDebits(): string
    {
        $costAccounts = DB::table('journal_lines as l')
            ->join('cost_lines as c', 'c.journal_entry_id', '=', 'l.journal_entry_id')
            ->where('l.entry_type', 'debit')->distinct()->pluck('l.account_id');

        return number_format((float) DB::table('journal_lines')->whereIn('account_id', $costAccounts)
            ->where('entry_type', 'debit')->sum('amount'), 2, '.', '');
    }

    private function approveSeniorThreshold(string $amount): void
    {
        DB::table('finance_settings')->updateOrInsert(
            ['key' => 'spend_voucher_senior_approval_threshold', 'effective_from' => '2020-01-01'],
            ['value' => $amount, 'label' => 'Senior threshold', 'approved_by' => $this->senior->id,
                'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    // ── W4-1 ────────────────────────────────────────────────────────────────

    public function test_returned_voucher_is_corrected_resubmitted_and_approved_with_full_history(): void
    {
        $voucherId = $this->createVoucher($this->liability('CL-W4-RET', '5000.00'), '5000.00');

        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/return", ['reason' => 'Invoice number missing'])
            ->assertOk()->assertJsonPath('data.review_state', 'returned_for_correction');

        // Returned is with the requester: it cannot be approved meanwhile.
        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")->assertStatus(422);

        // Only the requester corrects it.
        $this->actingAs($this->approver, 'sanctum')
            ->putJson("/api/finance/spend-vouchers/{$voucherId}/correction", ['supplier_invoice_no' => 'INV-9'])
            ->assertStatus(403);
        $this->actingAs($this->requester, 'sanctum')
            ->putJson("/api/finance/spend-vouchers/{$voucherId}/correction", ['supplier_invoice_no' => 'INV-9'])
            ->assertOk();
        $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/resubmit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->approveAndPost($voucherId);

        $voucher = SpendVoucher::with('reviews')->findOrFail($voucherId);
        $this->assertSame('posted', $voucher->status);
        $this->assertSame('INV-9', $voucher->supplier_invoice_no);
        $this->assertSame('Invoice number missing', $voucher->return_reason);
        $this->assertSame((int) $this->approver->id, (int) $voucher->returned_by);
        $this->assertSame((int) $this->requester->id, (int) $voucher->resubmitted_by);
        $this->assertSame(['returned', 'corrected', 'resubmitted', 'approved'], $voucher->reviews->pluck('action')->all());
        // The snapshot taken at return still holds what was originally submitted.
        $this->assertNull($voucher->reviews->first()->snapshot['supplier_invoice_no']);
    }

    public function test_reject_is_a_reviewers_refusal_and_releases_the_liability(): void
    {
        $line = $this->liability('CL-W4-REJ', '3000.00');
        $voucherId = $this->createVoucher($line, '3000.00');

        // The requester cannot reject (they cancel); a reason is mandatory.
        $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/reject", ['reason' => 'Not needed'])->assertStatus(403);
        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/reject", [])->assertStatus(422);

        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/reject", ['reason' => 'Already paid by EFT'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Already paid by EFT');

        // Rejected vouchers stop reserving the liability — a fresh voucher may pay it in full.
        $this->createVoucher($line, '3000.00');
    }

    // ── W4-2 ────────────────────────────────────────────────────────────────

    public function test_senior_approval_gate_is_inactive_until_a_threshold_is_approved(): void
    {
        // A proposed-but-unapproved threshold is no policy at all.
        DB::table('finance_settings')->updateOrInsert(
            ['key' => 'spend_voucher_senior_approval_threshold', 'effective_from' => '2020-01-01'],
            ['value' => '1000', 'label' => 'Senior threshold', 'approved_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );

        $voucherId = $this->createVoucher($this->liability('CL-W4-NOGATE', '50000.00'), '50000.00');
        $this->approveAndPost($voucherId);

        $this->assertSame('posted', SpendVoucher::findOrFail($voucherId)->status);
    }

    public function test_a_voucher_above_an_approved_threshold_needs_independent_senior_approval(): void
    {
        $this->approveSeniorThreshold('10000');

        $voucherId = $this->createVoucher($this->liability('CL-W4-GATE', '50000.00'), '50000.00');
        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")
            ->assertOk()->assertJsonPath('data.review_state', 'awaiting_senior_approval');

        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertStatus(422);

        // The ordinary approver cannot also be the senior approver.
        $this->approver->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR);
        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/senior-approve")->assertStatus(422);

        $this->actingAs($this->senior, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/senior-approve")->assertOk();
        $this->actingAs($this->poster, 'sanctum')
            ->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertOk();

        $voucher = SpendVoucher::findOrFail($voucherId);
        $this->assertSame('posted', $voucher->status);
        $this->assertSame((int) $this->senior->id, (int) $voucher->senior_approved_by);
    }

    public function test_a_voucher_at_or_below_the_threshold_posts_on_ordinary_approval(): void
    {
        $this->approveSeniorThreshold('10000');
        $voucherId = $this->createVoucher($this->liability('CL-W4-LOW', '10000.00'), '10000.00');
        $this->approveAndPost($voucherId);

        $this->assertSame('posted', SpendVoucher::findOrFail($voucherId)->status);
    }

    // ── Settlement invariants ───────────────────────────────────────────────

    public function test_paying_a_25000_cost_does_not_recognise_it_a_second_time(): void
    {
        $line = $this->liability('CL-W4-25K', '25000.00');
        $costLinesBefore = CostLine::count();
        $expenseBefore = $this->expenseDebits();
        $this->assertSame('25000.00', $expenseBefore);

        $this->approveAndPost($this->createVoucher($line, '25000.00'));

        $this->assertSame($costLinesBefore, CostLine::count(), 'Payment created a new cost line.');
        $this->assertSame('25000.00', $this->expenseDebits(), 'Payment recognised the cost again.');
        $this->assertSame('25000.00', number_format((float) CostLine::where('status', CostLine::STATUS_VERIFIED)
            ->where('nature', CostLine::NATURE_ACTUAL)->sum('amount'), 2, '.', ''));
    }

    public function test_a_reversed_payment_frees_the_liability_to_be_paid_again(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PAYMENTS_REVERSE, 'web');
        $reverser = User::factory()->create(['is_active' => true]);
        $reverser->givePermissionTo(Permissions::FINANCE_PAYMENTS_REVERSE);

        $line = $this->liability('CL-W4-REPAY', '8000.00');
        $first = $this->createVoucher($line, '8000.00');
        $this->approveAndPost($first);
        $this->assertNotNull($line->fresh()->settled_by_payment_id);

        $paymentId = SpendVoucher::findOrFail($first)->petty_cash_disbursement_id;
        $this->actingAs($reverser, 'sanctum')
            ->postJson("/api/finance/payments/{$paymentId}/reverse", ['reason' => 'Paid to the wrong account'])
            ->assertOk();
        $this->assertNull($line->fresh()->settled_by_payment_id);

        $this->approveAndPost($this->createVoucher($line, '8000.00'));
        $this->assertNotNull($line->fresh()->settled_by_payment_id);
        $this->assertSame('8000.00', $this->expenseDebits());
    }

    public function test_partial_settlements_pay_exactly_the_cost_and_no_more(): void
    {
        $line = $this->liability('CL-W4-100K', '100000.00');

        $this->approveAndPost($this->createVoucher($line, '40000.00'));
        $this->assertNull($line->fresh()->settled_by_payment_id, 'Part-paid is not settled.');
        $this->approveAndPost($this->createVoucher($line, '60000.00'));

        $paid = number_format((float) DB::table('spend_voucher_allocations as a')
            ->join('spend_vouchers as v', 'v.id', '=', 'a.spend_voucher_id')
            ->where('a.cost_line_id', $line->id)->where('v.status', 'posted')->sum('a.amount'), 2, '.', '');
        $this->assertSame('100000.00', $paid);
        $this->assertSame('100000.00', $this->expenseDebits());
        $this->assertNotNull($line->fresh()->settled_by_payment_id, 'Fully settled after the second instalment.');

        // A further KES 1 has nothing left to settle.
        $this->actingAs($this->requester, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Supplier', 'total_amount' => 1.00,
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id,
            'allocations' => [['cost_line_id' => $line->id, 'amount' => 1.00]],
        ])->assertStatus(422);
    }
}
