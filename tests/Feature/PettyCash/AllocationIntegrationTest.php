<?php

namespace Tests\Feature\PettyCash;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use Illuminate\Support\Facades\DB;

class AllocationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_topup_allocation_persists_and_updates_remaining_balances()
    {
        $this->seed(FinanceReferenceSeeder::class);
        $user = User::factory()->create();

        // Create two top-ups: older 50, newer 100
        $t1 = PettyCashTopUp::create([
            'amount' => 50.00,
            'date_topped_up' => now()->subDays(10)->toDateString(),
            'payment_method' => 'cash',
            'created_by' => $user->id,
        ]);

        $t2 = PettyCashTopUp::create([
            'amount' => 100.00,
            'date_topped_up' => now()->subDays(1)->toDateString(),
            'payment_method' => 'cash',
            'created_by' => $user->id,
        ]);

        PettyCashBalance::current()->update(['current_balance' => 150.00]);

        $service = app(\App\Modules\Finance\PettyCash\Services\PettyCashService::class);

        $res = $service->createDisbursement([
            'expense_code_id' => DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id'),
            'payment_source_id' => DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id'),
            'amount' => 120.00,
            'transaction_cost' => 3.00,
            'receiver' => 'Integration Test',
            'account' => 'acct',
            'description' => 'Allocation split test',
            'classification' => 'other',
            'payment_method' => 'cash',
            'created_by' => $user->id,
        ]);

        $this->assertIsArray($res);
        $this->assertTrue($res['success'] ?? false, 'Disbursement creation failed: ' . json_encode($res));

        $disbursement = $res['data'];

        $allocs = PettyCashDisbursementAllocation::where('disbursement_id', $disbursement->id)->get();

        $this->assertGreaterThanOrEqual(1, $allocs->count(), 'No allocation rows persisted.');

        $totalAllocated = $allocs->sum(function ($a) { return (float)$a->amount + (float)$a->transaction_cost; });

        $this->assertEquals(123.00, round($totalAllocated, 2), 'Allocated total does not match amount+cost');

        $t1->refresh();
        $t2->refresh();

        // Old top-up should be exhausted (50 -> 0)
        $this->assertEquals(0.00, round($t1->remaining_balance, 2));

        // New top-up should have remaining 27.00 (100 - 73)
        $this->assertEquals(27.00, round($t2->remaining_balance, 2));
    }
}
