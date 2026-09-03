<?php

namespace Tests\Feature\PettyCash;

use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\FundCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundCustodyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_direct_split_voided_and_archived_payments(): void
    {
        $user = User::factory()->create();
        $first = $this->topUp($user, 100, 2);
        $second = $this->topUp($user, 100, 1);

        $direct = $this->payment($user, $first, 30, 'active');
        $archived = $this->payment($user, $first, 10, 'active', true);
        $split = $this->payment($user, $first, 80, 'active');
        PettyCashDisbursementAllocation::create(['disbursement_id' => $split->id, 'top_up_id' => $first->id, 'amount' => 60, 'transaction_cost' => 0]);
        PettyCashDisbursementAllocation::create(['disbursement_id' => $split->id, 'top_up_id' => $second->id, 'amount' => 20, 'transaction_cost' => 0]);
        $voided = $this->payment($user, $second, 50, 'voided');
        PettyCashDisbursementAllocation::create(['disbursement_id' => $voided->id, 'top_up_id' => $second->id, 'amount' => 50, 'transaction_cost' => 0]);
        PettyCashBalance::current()->update(['current_balance' => 80]);

        $report = app(FundCustodyService::class)->overview(now()->startOfMonth(), now());

        $this->assertSame(120.0, $report['summary']['funds_consumed_all_time']);
        $this->assertSame(80.0, $report['summary']['funds_remaining']);
        $this->assertSame(0.0, $report['summary']['reconciliation_difference']);
        $this->assertCount(now()->startOfMonth()->diffInDays(now()) + 1, $report['daily']);
        $this->assertSame(0.0, (float) $report['batches']->firstWhere('id', $first->id)['remaining']);
        $this->assertSame(80.0, (float) $report['batches']->firstWhere('id', $second->id)['remaining']);
    }

    private function topUp(User $user, float $amount, int $daysAgo): PettyCashTopUp
    {
        return PettyCashTopUp::create(['amount' => $amount, 'payment_method' => 'cash', 'date_topped_up' => now()->subDays($daysAgo), 'created_by' => $user->id]);
    }

    private function payment(User $user, PettyCashTopUp $topUp, float $amount, string $status, bool $archived = false): PettyCashDisbursement
    {
        return PettyCashDisbursement::create([
            'top_up_id' => $topUp->id, 'receiver' => 'Recipient', 'account' => 'Expense', 'amount' => $amount,
            'description' => 'Custody test', 'classification' => 'admin', 'payment_method' => 'cash', 'status' => $status,
            'created_by' => $user->id, 'date_disbursed' => now()->toDateString(), 'is_archived' => $archived,
        ]);
    }
}
