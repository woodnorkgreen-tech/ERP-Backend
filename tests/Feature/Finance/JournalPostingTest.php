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
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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
            'name' => 'Main Safe',
            'code' => 'SAFE-01',
            'type' => 'petty_cash',
            'gl_account_id' => ChartOfAccount::where('code', '1030')->value('id'),
            'is_active' => true,
        ]);

        $this->actingAs($this->user, 'sanctum');

        // Create Voucher
        $response = $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Test Supplier',
            'total_amount' => 15000.00,
            'payment_source_id' => $source->id,
        ]);

        $response->assertStatus(201);
        $voucherId = $response->json('data.id');

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
            'total_debit' => '15000.00',
            'total_credit' => '15000.00',
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

    /** Every column the table requires, so callers name only what they vary. */
    private function voucher(array $overrides = []): SpendVoucher
    {
        return SpendVoucher::create(array_merge([
            'voucher_no' => 'SV-' . uniqid(),
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
        ], $overrides));
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

        // Must resolve to its own action rather than being read as a voucher id.
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/finance/spend-vouchers/payment-sources')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Main Safe', $names);
        $this->assertNotContains('Retired Account', $names);
    }

    public function test_payment_sources_require_the_voucher_read_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/finance/spend-vouchers/payment-sources')
            ->assertForbidden();
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
        $this->withoutExceptionHandling();
        ChartOfAccount::query()->update(['is_postable' => false]);

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
        ]);

        try {
            $this->actingAs($this->poster, 'sanctum')
                ->postJson("/api/finance/spend-vouchers/{$voucher->id}/post");
            $this->fail('Posting succeeded without a debit and credit account.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('No complete posting rule', $e->getMessage());
        }

        $this->assertSame('approved', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->posted_at);
        $this->assertDatabaseMissing('journal_entries', ['spend_voucher_id' => $voucher->id]);
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
}
