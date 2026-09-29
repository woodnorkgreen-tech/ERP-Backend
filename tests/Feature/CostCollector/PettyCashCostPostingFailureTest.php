<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Events\PettyCashDisbursementPaid;
use App\Listeners\RecordPettyCashCost;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostPoster;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Wave 1 Closure Gate §3.D / §5: a direct petty-cash disbursement's cost/GL
 * entry is posted a moment later by a QUEUED listener, after the cash has
 * already left the float. Before this, a posting failure there was only a
 * log line — the same shape of problem STAB-4 solved for requisition
 * advances. These tests pin the same, reused pattern: the disbursement is
 * visibly flagged, Finance is alerted, and a controlled retry exists that
 * cannot double-post.
 */
class PettyCashCostPostingFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $topUpId;
    private int $overheadExpenseCodeId;
    private int $overheadAccountId;
    private int $paymentSourceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceReferenceSeeder::class);

        $this->overheadExpenseCodeId = (int) DB::table('expense_codes')
            ->where('job_id_rule', 'not_allowed')->whereNotNull('default_debit_account_id')->value('id');
        $this->overheadAccountId = (int) DB::table('expense_codes')
            ->where('id', $this->overheadExpenseCodeId)->value('default_debit_account_id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_UPDATE, 'web');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);
        $this->actingAs($this->user);

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->user->id,
        ])->id;
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    private function disbursement(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'top_up_id' => $this->topUpId,
            'amount' => 4500.00,
            'payee_name' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'description' => 'Office transport',
            'classification' => 'admin',
            'payment_method' => 'cash',
            'status' => 'active',
            'job_number' => null,
            'date_disbursed' => now()->subDays(1)->toDateString(),
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_a_gl_posting_failure_is_flagged_and_alerts_finance(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_UPDATE, 'web');
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);

        // Simulate the chart becoming unable to post this expense account —
        // exactly the shape of failure the GL-mapping guard exists to catch.
        ChartOfAccount::whereKey($this->overheadAccountId)->update(['is_postable' => false]);

        $disbursement = $this->disbursement();
        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));

        $disbursement->refresh();
        $this->assertNotNull($disbursement->cost_gl_posting_failed_at);
        $this->assertNotEmpty($disbursement->cost_gl_posting_error);
        $this->assertSame(0, CostLine::where('source_id', $disbursement->id)->count());

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $reviewer->id,
            'type' => 'petty_cash_cost_posting_failed',
        ]);
    }

    public function test_retrying_after_the_chart_is_fixed_posts_and_clears_the_flag(): void
    {
        ChartOfAccount::whereKey($this->overheadAccountId)->update(['is_postable' => false]);
        $disbursement = $this->disbursement();
        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));
        $this->assertNotNull($disbursement->fresh()->cost_gl_posting_failed_at);

        ChartOfAccount::whereKey($this->overheadAccountId)->update(['is_postable' => true]);

        $retry = $this->postJson("/api/finance/petty-cash/disbursements/{$disbursement->id}/retry-cost-posting");
        $retry->assertStatus(200)->assertJsonPath('success', true);

        $disbursement->refresh();
        $this->assertNull($disbursement->cost_gl_posting_failed_at);
        $this->assertNull($disbursement->cost_gl_posting_error);

        $entry = JournalEntry::where(
            'entry_no', 'JE-PAY-'.str_pad((string) $disbursement->id, 7, '0', STR_PAD_LEFT),
        )->firstOrFail();
        $this->assertSame('posted', $entry->status);
    }

    public function test_retrying_an_already_posted_disbursement_does_not_duplicate_the_entry(): void
    {
        $disbursement = $this->disbursement();
        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));
        $this->assertNull($disbursement->fresh()->cost_gl_posting_failed_at);

        $entryNo = 'JE-PAY-'.str_pad((string) $disbursement->id, 7, '0', STR_PAD_LEFT);
        $this->assertSame(1, JournalEntry::where('entry_no', $entryNo)->count());

        // A second attempt — whether via the retry route or the poster
        // directly — must confirm, not duplicate.
        app(PettyCashCostPoster::class)->attempt($disbursement->fresh());

        $this->assertSame(1, JournalEntry::where('entry_no', $entryNo)->count());
    }

    public function test_retry_still_fails_visibly_if_the_underlying_problem_is_not_yet_fixed(): void
    {
        ChartOfAccount::whereKey($this->overheadAccountId)->update(['is_postable' => false]);
        $disbursement = $this->disbursement();
        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));

        $retry = $this->postJson("/api/finance/petty-cash/disbursements/{$disbursement->id}/retry-cost-posting");

        $retry->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotNull($disbursement->fresh()->cost_gl_posting_failed_at);
    }

    public function test_a_second_queued_attempt_after_success_posts_nothing_twice(): void
    {
        // The idempotency the integration doc requires of every producer,
        // now proven through the failure-handling poster too, not just the
        // producer directly (PettyCashCostListenerTest already covers that
        // path without the poster in front of it).
        $disbursement = $this->disbursement(['job_number' => 'WNG-01-2026-004']);
        DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(),
            'client_id' => DB::table('clients')->insertGetId([
                'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
                'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
                'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
                'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]),
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => 'WNG-01-2026-004',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));
        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));

        $this->assertSame(1, CostLine::where('source_type', Payment::class)
            ->where('source_id', $disbursement->id)->count());
    }
}
