<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DirectDisbursementApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_payment_moves_no_cash_until_an_independent_checker_approves_it(): void
    {
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_APPROVE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $maker = User::factory()->create(['is_active' => true]);
        $maker->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $checker = User::factory()->create(['is_active' => true]);
        $checker->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        $topUp = PettyCashTopUp::create([
            'amount' => 10000, 'payment_method' => 'cash',
            'date_topped_up' => now()->toDateString(), 'created_by' => $maker->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 10000]);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'top_up_id' => $topUp->id,
            'receiver' => 'Office Supplier',
            'expense_code_id' => DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id'),
            'payment_source_id' => DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id'),
            'amount' => 1500,
            'transaction_cost' => 0,
            'description' => 'Urgent office consumables',
            'direct_payment_reason' => 'Operational emergency outside the requisition window.',
            'date_disbursed' => now()->toDateString(),
            'receipt_type' => 'none',
            'tax_amount' => 0,
        ];

        $submission = $this->actingAs($maker, 'sanctum')
            ->postJson('/api/finance/petty-cash/disbursements', $payload)
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $requestId = $submission->json('data.id');
        $this->assertSame(0, PettyCashDisbursement::count());
        $this->assertSame(0, DB::table('petty_cash_ledger_entries')->where('type', 'debit')->count());

        $this->actingAs($maker, 'sanctum')
            ->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve")
            ->assertForbidden();

        $this->actingAs($checker, 'sanctum')
            ->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertSame(1, PettyCashDisbursement::count());
        $this->assertSame(1, DB::table('petty_cash_ledger_entries')->where('type', 'debit')->count());
        $this->assertDatabaseHas('direct_disbursement_requests', [
            'id' => $requestId, 'status' => 'approved', 'approved_by' => $checker->id,
        ]);
    }
}
