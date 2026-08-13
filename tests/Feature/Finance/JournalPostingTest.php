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
