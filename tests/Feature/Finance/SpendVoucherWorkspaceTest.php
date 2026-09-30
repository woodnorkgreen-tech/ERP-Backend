<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stream E — W4 Payment vouchers workspace (Report 63).
 *
 * The read projection, the backend action eligibility it carries, and the
 * closure proofs the brief makes mandatory: maker / checker / poster
 * segregation, a role name granting nothing, and the single economic cost for
 * a project and an overhead voucher, posting idempotency and reversal.
 */
class SpendVoucherWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;
    private User $approver;
    private User $poster;
    private User $senior;
    private User $reverser;
    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        foreach ([
            Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_POST,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR, Permissions::FINANCE_PAYMENTS_REVERSE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $read = Permissions::FINANCE_SPEND_VOUCHERS_READ;
        $this->requester = $this->user('Rita Requester', [Permissions::FINANCE_SPEND_VOUCHERS_CREATE, $read]);
        $this->approver = $this->user('Abel Approver', [Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, $read]);
        $this->poster = $this->user('Pat Poster', [Permissions::FINANCE_SPEND_VOUCHERS_POST, $read]);
        $this->senior = $this->user('Sam Senior', [Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR, $read]);
        $this->reverser = $this->user('Rae Reverser', [Permissions::FINANCE_PAYMENTS_REVERSE, $read]);

        $this->bank = PaymentSource::create([
            'name' => 'Operating Bank', 'code' => 'BANK-SE', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);
    }

    private function user(string $name, array $permissions): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function liability(string $ref, string $amount, string $code, ?string $job = null): CostLine
    {
        $line = CostLine::create([
            'ref' => $ref, 'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => $amount, 'tax_amount' => '0.00', 'net_amount' => $amount, 'base_net_amount' => $amount,
            'fx_rate' => '1.00', 'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'submitted_by_user_id' => $this->requester->id, 'description' => "Liability {$ref}",
            'expense_code_id' => ExpenseCode::where('code', $code)->value('id'), 'job_number' => $job,
        ]);
        app(JournalPostingService::class)->postCostLine($line);

        return $line->fresh();
    }

    private function createVoucher(CostLine $line, string $amount, ?string $notes = null): int
    {
        return $this->actingAs($this->requester, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Supplier', 'total_amount' => (float) $amount,
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id, 'notes' => $notes,
            'allocations' => [['cost_line_id' => $line->id, 'amount' => (float) $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function detail(User $as, int $id): array
    {
        return $this->actingAs($as, 'sanctum')->getJson("/api/finance/spend-vouchers/{$id}/detail")->assertOk()->json('data');
    }

    /** Debits to every account a cost line recognised its cost in. */
    private function costDebits(): string
    {
        $accounts = DB::table('journal_lines as l')->join('cost_lines as c', 'c.journal_entry_id', '=', 'l.journal_entry_id')
            ->where('l.entry_type', 'debit')->distinct()->pluck('l.account_id');

        return number_format((float) DB::table('journal_lines')->whereIn('account_id', $accounts)
            ->where('entry_type', 'debit')->sum('amount'), 2, '.', '');
    }

    // ── Read projection ─────────────────────────────────────────────────────

    public function test_the_register_paginates_filters_and_keeps_the_three_facts_apart(): void
    {
        $project = $this->liability('CL-SE-P', '12000.00', 'DM-WD-001', 'WNG-2026-001');
        $overhead = $this->liability('CL-SE-O', '3000.00', 'OE-OFF-001');
        $projectVoucher = $this->createVoucher($project, '12000.00', 'Boards for the Safaricom stand');
        $this->createVoucher($overhead, '3000.00');

        $page = $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register?per_page=1')->assertOk();
        $page->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
        $page->assertJsonPath('summary.counts.awaiting_approval', 2);
        $page->assertJsonPath('senior_policy.state', 'not_configured');

        $row = $this->actingAs($this->approver, 'sanctum')
            ->getJson('/api/finance/spend-vouchers/register?classification=project')->assertOk()->json('data');
        $this->assertCount(1, $row);
        $this->assertSame($projectVoucher, $row[0]['id']);
        $this->assertSame('project', $row[0]['classification']);
        $this->assertSame(['WNG-2026-001'], $row[0]['job_numbers']);
        $this->assertSame('Boards for the Safaricom stand', $row[0]['purpose']);
        $this->assertSame('awaiting_approval', $row[0]['state']);
        $this->assertSame(['approval' => 'pending', 'posting' => 'not_posted', 'payment' => 'unpaid'], $row[0]['facets']);
        $this->assertSame(['id' => $this->requester->id, 'name' => 'Rita Requester'], $row[0]['requester']);
        $this->assertTrue($row[0]['actions']['approve']['allowed']);
        $this->assertSame('approve', $row[0]['next_action']);

        $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register?classification=overhead')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.classification', 'overhead');
        $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register?job_number=2026-001')
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register?search=Safaricom')
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register?state=posted')
            ->assertJsonCount(0, 'data');
    }

    public function test_identities_are_id_and_name_only(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-ID', '500.00', 'OE-OFF-001'), '500.00');

        $body = $this->actingAs($this->approver, 'sanctum')->getJson("/api/finance/spend-vouchers/{$id}/detail")->assertOk()->getContent();

        $this->assertStringNotContainsString($this->requester->email, $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertSame(['id' => $this->requester->id, 'name' => 'Rita Requester'], $this->detail($this->approver, $id)['workflow']['requested_by']);
    }

    // ── Workflow ────────────────────────────────────────────────────────────

    public function test_the_correction_path_keeps_one_voucher_and_its_whole_history(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-FIX', '4000.00', 'OE-OFF-001'), '4000.00');

        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/return", ['reason' => 'Attach the eTIMS invoice'])->assertOk();
        $this->assertSame('returned_for_correction', $this->detail($this->requester, $id)['state']);
        $this->assertTrue($this->detail($this->requester, $id)['actions']['correct']['allowed']);
        $this->assertFalse($this->detail($this->approver, $id)['actions']['approve']['allowed']);

        // A second return while it is with the requester is refused, not stacked.
        $this->assertFalse($this->detail($this->approver, $id)['actions']['return']['allowed']);
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/return", ['reason' => 'Again, please'])->assertStatus(422);

        $this->actingAs($this->requester, 'sanctum')->putJson("/api/finance/spend-vouchers/{$id}/correction", ['etims_invoice_no' => 'KRA-123'])->assertOk();
        $this->actingAs($this->requester, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/resubmit")->assertOk();
        $this->assertSame('resubmitted', $this->detail($this->approver, $id)['state']);
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();

        $detail = $this->detail($this->approver, $id);
        $this->assertSame(['created', 'returned', 'corrected', 'resubmitted', 'approved'], array_column($detail['history'], 'event'));
        $this->assertSame('Attach the eTIMS invoice', $detail['history'][1]['reason']);
        $this->assertSame('Abel Approver', $detail['history'][1]['by']['name']);
        $this->assertSame('KRA-123', $detail['voucher']['etims_invoice_no']);
        $this->assertSame(1, SpendVoucher::count());
    }

    public function test_reject_and_cancel_are_shown_apart(): void
    {
        $rejected = $this->createVoucher($this->liability('CL-SE-REJ', '900.00', 'OE-OFF-001'), '900.00');
        $cancelled = $this->createVoucher($this->liability('CL-SE-CAN', '800.00', 'OE-OFF-001'), '800.00');

        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$rejected}/reject", ['reason' => 'Not a company expense'])->assertOk();
        $this->actingAs($this->requester, 'sanctum')->postJson("/api/finance/spend-vouchers/{$cancelled}/cancel")->assertOk();

        $this->assertSame('rejected', $this->detail($this->approver, $rejected)['state']);
        $this->assertSame('Not a company expense', $this->detail($this->approver, $rejected)['workflow']['rejection_reason']);
        $this->assertSame('cancelled', $this->detail($this->approver, $cancelled)['state']);
        $this->assertSame('cancelled', $this->detail($this->approver, $cancelled)['facets']['approval']);
        foreach (['approve', 'return', 'reject', 'post', 'correct', 'resubmit'] as $action) {
            $this->assertFalse($this->detail($this->approver, $rejected)['actions'][$action]['allowed'], $action);
        }
    }

    // ── Segregation (mandatory closure proofs) ──────────────────────────────

    public function test_a_maker_cannot_approve_their_own_voucher(): void
    {
        $this->requester->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $id = $this->createVoucher($this->liability('CL-SE-MK', '700.00', 'OE-OFF-001'), '700.00');

        $action = $this->detail($this->requester, $id)['actions']['approve'];
        $this->assertFalse($action['allowed']);
        $this->assertStringContainsString('someone else has to approve', $action['reason']);
        $this->actingAs($this->requester, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertStatus(422);
    }

    public function test_the_approver_does_not_become_the_poster(): void
    {
        $this->approver->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_POST);
        $id = $this->createVoucher($this->liability('CL-SE-CK', '650.00', 'OE-OFF-001'), '650.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();

        $action = $this->detail($this->approver, $id)['actions']['post'];
        $this->assertFalse($action['allowed']);
        $this->assertSame('The requester and approver cannot post this voucher.', $action['reason']);
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertStatus(422);

        $this->assertTrue($this->detail($this->poster, $id)['actions']['post']['allowed']);
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();
    }

    public function test_posting_needs_its_own_permission(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-PP', '600.00', 'OE-OFF-001'), '600.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();

        // The senior approver is independent of requester and approver, yet
        // holds no posting permission.
        $this->assertFalse($this->detail($this->senior, $id)['actions']['post']['allowed']);
        $this->actingAs($this->senior, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertForbidden();
    }

    public function test_a_role_name_alone_grants_nothing(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-ROLE', '550.00', 'OE-OFF-001'), '550.00');
        $named = User::factory()->create(['is_active' => true]);
        foreach (['Admin', 'Accounts', 'Finance', 'Accountant', 'Manager'] as $name) {
            $named->assignRole(Role::findOrCreate($name, 'web'));
        }

        $this->actingAs($named, 'sanctum')->getJson('/api/finance/spend-vouchers/register')->assertForbidden();
        $this->actingAs($named, 'sanctum')->getJson("/api/finance/spend-vouchers/{$id}/detail")->assertForbidden();
        $this->actingAs($named, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertForbidden();
        $this->actingAs($named, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertForbidden();
    }

    /**
     * The one deliberate exception, pinned so a change to it is visible: the
     * global Gate::before in AppServiceProvider passes Super Admin through
     * every ability, self-approval included. It is system-wide and documented
     * there and on APPROVALS_SELF_APPROVE; W4 does not change it. What W4
     * guarantees is that its use is recorded, not hidden.
     */
    public function test_super_admin_is_the_documented_bypass_and_its_use_is_recorded(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $line = $this->liability('CL-SE-SA', '450.00', 'OE-OFF-001');
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Supplier', 'total_amount' => 450.0,
            'payment_method' => 'bank_transfer', 'payment_source_id' => $this->bank->id,
            'allocations' => [['cost_line_id' => $line->id, 'amount' => 450.0]],
        ])->assertCreated()->json('data.id');

        $this->assertTrue($this->detail($admin, $id)['actions']['approve']['allowed']);
        $this->actingAs($admin, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();

        $this->assertTrue(DB::table('hr_audit_logs')->where('action', 'spend_voucher_posted')->where('model_id', $id)
            ->where('message', 'like', '%Separation-of-duties override used%')->exists());
    }

    // ── Accounting (cases A–D) ──────────────────────────────────────────────

    public function test_case_a_a_project_voucher_pays_its_cost_without_recognising_it_again(): void
    {
        $line = $this->liability('CL-SE-A', '15000.00', 'DM-WD-001', 'WNG-2026-002');
        $costLines = CostLine::count();
        $projectCost = CostLine::where('job_number', 'WNG-2026-002')->sum('amount');
        $this->assertSame('15000.00', $this->costDebits());

        $id = $this->createVoucher($line, '15000.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();

        $this->assertSame($costLines, CostLine::count(), 'Payment created a cost line.');
        $this->assertEquals($projectCost, CostLine::where('job_number', 'WNG-2026-002')->sum('amount'));
        $this->assertSame('15000.00', $this->costDebits(), 'Payment recognised the project cost again.');

        $detail = $this->detail($this->poster, $id);
        $this->assertSame(['approval' => 'approved', 'posting' => 'posted', 'payment' => 'paid'], $detail['facets']);
        $this->assertSame('project', $detail['allocations'][0]['cost_line']['classification']);
        $this->assertSame('DM-WD-001', $detail['allocations'][0]['cost_line']['expense_code']['code']);
        $this->assertNotNull($detail['allocations'][0]['cost_line']['recognised_by']);
        $this->assertSame('15000.00', $detail['payment']['amount']);
        $this->assertCount(2, $detail['accounting']['entry']['lines']);
    }

    public function test_case_b_an_overhead_voucher_relieves_the_liability_and_debits_no_expense(): void
    {
        $line = $this->liability('CL-SE-B', '2500.00', 'OE-OFF-001');
        $expenseAccount = ExpenseCode::where('code', 'OE-OFF-001')->value('default_debit_account_id');
        $recognised = DB::table('journal_lines')->where('journal_entry_id', $line->journal_entry_id)
            ->where('entry_type', 'debit')->value('account_id');
        $this->assertSame($expenseAccount, $recognised, 'The cost was not recognised on its expense code\'s account.');

        $id = $this->createVoucher($line, '2500.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();

        $voucherEntry = JournalEntry::where('spend_voucher_id', $id)->firstOrFail();
        $this->assertFalse(DB::table('journal_lines')->where('journal_entry_id', $voucherEntry->id)
            ->where('account_id', $expenseAccount)->exists(), 'The payment journal touched the expense account.');
        $this->assertSame('2500.00', $this->costDebits());
        $this->assertSame('overhead', $this->detail($this->poster, $id)['allocations'][0]['cost_line']['classification']);
    }

    public function test_case_c_a_repeated_post_does_not_duplicate_the_payment_or_journal(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-C', '1800.00', 'OE-OFF-001'), '1800.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertStatus(422);

        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertStatus(422);

        $this->assertSame(1, JournalEntry::where('spend_voucher_id', $id)->count());
        $this->assertSame(1, Payment::where('spend_voucher_id', $id)->count());
        $this->assertFalse($this->detail($this->poster, $id)['actions']['post']['allowed']);
    }

    public function test_case_d_reversal_adds_a_reversing_entry_and_keeps_the_original(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-D', '2200.00', 'OE-OFF-001'), '2200.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertOk();

        $this->assertTrue($this->detail($this->reverser, $id)['actions']['reverse']['allowed']);
        $this->assertFalse($this->detail($this->poster, $id)['actions']['reverse']['allowed']);
        $payment = $this->detail($this->reverser, $id)['payment']['id'];
        $this->actingAs($this->reverser, 'sanctum')->postJson("/api/finance/payments/{$payment}/reverse", ['reason' => 'Paid to the wrong account'])->assertOk();

        $detail = $this->detail($this->reverser, $id);
        $this->assertSame('reversed', $detail['state']);
        $this->assertSame(['approval' => 'approved', 'posting' => 'reversed', 'payment' => 'voided'], $detail['facets']);
        $this->assertNotNull($detail['accounting']['entry'], 'The original entry must be kept, not deleted.');
        $this->assertNotNull($detail['accounting']['reversal']);
        $this->assertSame('Paid to the wrong account', collect($detail['history'])->firstWhere('event', 'reversed')['reason']);
        $this->assertFalse($detail['actions']['reverse']['allowed']);
        $this->assertSame(1, SpendVoucher::count(), 'Reversal must not delete or replace the voucher.');
    }

    // ── Procurement boundary ────────────────────────────────────────────────

    /**
     * Report 63: the voucher-first direction of the GRN double payment. A
     * goods-received accrual is paid through its supplier bill; a voucher that
     * paid it first left the bill free to clear it again. Refused at every
     * layer, including a stale allocation that reaches the posting funnel.
     */
    public function test_a_goods_received_accrual_is_never_paid_by_a_voucher(): void
    {
        $period = AccountingPeriod::forDate(now());
        $accrual = CostLine::create([
            'ref' => 'CL-SE-GRN', 'nature' => CostLine::NATURE_ACCRUED, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => '5000.00', 'net_amount' => '5000.00', 'tax_amount' => '0.00', 'base_net_amount' => '5000.00', 'fx_rate' => '1.00',
            'accounting_period_id' => $period->id, 'submitted_by_user_id' => $this->requester->id,
            'source_type' => CostLine::GRN_ACCRUAL_SOURCE, 'source_id' => 1, 'source_ref' => 'accrual', 'description' => 'Accepted goods: GRN-SE',
        ]);
        $entry = JournalEntry::create([
            'entry_no' => 'JE-SE-GRN', 'posting_date' => now()->toDateString(), 'accounting_period_id' => $period->id,
            'cost_line_id' => $accrual->id, 'source_type' => CostLine::class, 'source_id' => $accrual->id, 'source_ref' => $accrual->ref,
            'description' => 'GRN accrual', 'total_debit' => '5000.00', 'total_credit' => '5000.00', 'status' => 'posted',
            'created_by' => $this->requester->id, 'posted_at' => now(),
        ]);
        foreach ([['1300', 'debit'], ['2150', 'credit']] as [$code, $side]) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $entry->id, 'account_id' => ChartOfAccount::where('code', $code)->value('id'),
                'entry_type' => $side, 'amount' => '5000.00', 'base_amount' => '5000.00', 'currency' => 'KES', 'fx_rate' => 1,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $accrual->forceFill(['journal_entry_id' => $entry->id, 'posted_at' => now()])->save();

        // Layer 1: not offered.
        $eligible = $this->actingAs($this->requester, 'sanctum')->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk()->json('data');
        $this->assertFalse(collect($eligible)->contains('id', $accrual->id));

        // Layer 2: refused at creation.
        $this->actingAs($this->requester, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Supplier', 'total_amount' => 5000.0, 'payment_method' => 'bank_transfer',
            'payment_source_id' => $this->bank->id, 'allocations' => [['cost_line_id' => $accrual->id, 'amount' => 5000.0]],
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'paid through its supplier bill'));

        // Layer 3: a stale allocation, as if made before this guard, cannot post.
        $voucher = SpendVoucher::create([
            'voucher_no' => 'SV-SE-STALE', 'type' => 'payment', 'status' => 'approved', 'review_state' => 'approved',
            'transacted_at' => now(), 'posting_date' => now()->toDateString(), 'accounting_period_id' => $period->id,
            'payment_source_id' => $this->bank->id, 'requester_user_id' => $this->requester->id, 'approved_by' => $this->approver->id,
            'approved_at' => now(), 'payee_name' => 'Supplier', 'currency' => 'KES', 'fx_rate' => 1, 'total_amount' => '5000.00',
            'base_total_amount' => '5000.00', 'net_amount' => '5000.00', 'net_cash_paid' => '5000.00',
        ]);
        DB::table('spend_voucher_allocations')->insert(['spend_voucher_id' => $voucher->id, 'cost_line_id' => $accrual->id,
            'amount' => '5000.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$voucher->id}/post")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'paid through its supplier bill'));
        $this->assertSame(0, Payment::where('spend_voucher_id', $voucher->id)->count(), 'The rolled-back post left a payment.');
        $this->assertSame(0, JournalEntry::where('spend_voucher_id', $voucher->id)->count());
        $this->assertSame('approved', $voucher->fresh()->status);
    }

    // ── Senior approval (W4-2) ──────────────────────────────────────────────

    public function test_senior_policy_is_reported_as_it_stands_and_enforced_only_when_approved(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-S1', '60000.00', 'OE-OFF-001'), '60000.00');
        $this->assertSame('not_configured', $this->detail($this->approver, $id)['senior']['policy']);

        // A proposed threshold nobody signed off is shown, not enforced.
        DB::table('finance_settings')->updateOrInsert(
            ['key' => 'spend_voucher_senior_approval_threshold', 'effective_from' => '2020-01-01'],
            ['value' => '50000', 'label' => 'Senior threshold', 'created_at' => now(), 'updated_at' => now()],
        );
        $policy = $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/spend-vouchers/register')->json('senior_policy');
        $this->assertSame(['state' => 'awaiting_sign_off', 'threshold' => null, 'proposed' => '50000.00', 'approvers' => 1], $policy);
        $this->assertFalse($this->detail($this->approver, $id)['senior']['would_require']);

        DB::table('finance_settings')->where('key', 'spend_voucher_senior_approval_threshold')
            ->update(['approved_by' => $this->senior->id, 'approved_at' => now()]);
        $this->assertTrue($this->detail($this->approver, $id)['senior']['would_require']);

        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        $detail = $this->detail($this->poster, $id);
        $this->assertSame('awaiting_senior_approval', $detail['state']);
        $this->assertFalse($detail['actions']['post']['allowed']);
        $this->assertStringContainsString('senior approval', $detail['actions']['post']['reason']);
        $this->assertTrue($this->detail($this->senior, $id)['actions']['senior_approve']['allowed']);

        $this->actingAs($this->senior, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/senior-approve")->assertOk();
        $this->assertTrue($this->detail($this->poster, $id)['actions']['post']['allowed']);
        $this->assertSame('Sam Senior', $this->detail($this->poster, $id)['workflow']['senior_approved_by']['name']);
    }

    public function test_a_closed_period_is_named_before_posting_is_attempted(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-PER', '400.00', 'OE-OFF-001'), '400.00');
        $this->actingAs($this->approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/approve")->assertOk();
        AccountingPeriod::whereKey(SpendVoucher::findOrFail($id)->accounting_period_id)->update(['status' => 'closed']);

        $action = $this->detail($this->poster, $id)['actions']['post'];
        $this->assertFalse($action['allowed']);
        $this->assertStringContainsString('not open', $action['reason']);
        $this->actingAs($this->poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/post")->assertStatus(422);
        $this->assertSame(0, JournalEntry::where('spend_voucher_id', $id)->count());
    }

    // ── Evidence and My Actions ─────────────────────────────────────────────

    public function test_evidence_is_added_by_the_right_people_and_never_exposes_a_path(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-EV', '300.00', 'OE-OFF-001'), '300.00');

        $this->actingAs($this->requester, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/attachments", [
            'evidence_type' => 'invoice', 'reference' => 'INV-4471',
        ])->assertCreated()->assertJsonMissingPath('data.file_path');
        $this->actingAs($this->reverser, 'sanctum')->postJson("/api/finance/spend-vouchers/{$id}/attachments", [
            'evidence_type' => 'other', 'reference' => 'nope',
        ])->assertForbidden();

        $attachments = $this->detail($this->approver, $id)['attachments'];
        $this->assertCount(1, $attachments);
        $this->assertSame('INV-4471', $attachments[0]['reference']);
        $this->assertSame('Rita Requester', $attachments[0]['uploader']['name']);
    }

    public function test_my_actions_links_to_the_voucher_itself(): void
    {
        $id = $this->createVoucher($this->liability('CL-SE-WQ', '350.00', 'OE-OFF-001'), '350.00');

        $items = $this->actingAs($this->approver, 'sanctum')->getJson('/api/finance/work-queue?per_page=50')->assertOk()->json('data.items');
        $item = collect($items)->firstWhere('key', "spend_voucher:{$id}");
        $this->assertNotNull($item);
        $this->assertSame("/finance/payment-vouchers/{$id}", $item['target_url']);
    }
}
