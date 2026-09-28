<?php

namespace Tests\Feature\PettyCash;

use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Critical Risk C6 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * the console command that replaced the `DELETE /finance/petty-cash/clear-all`
 * endpoint. It must never run in production, must ask before deleting
 * anything (unless explicitly forced), and must actually do what it says
 * when allowed to proceed.
 */
class ClearAllPettyCashDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $user = User::factory()->create();
        $topUp = PettyCashTopUp::create([
            'amount' => 1000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->toDateString(), 'created_by' => $user->id,
        ]);

        app()->detectEnvironment(fn () => 'production');

        $this->artisan('petty-cash:clear-all-non-production', ['--force' => true])
            ->assertExitCode(1);

        app()->detectEnvironment(fn () => 'testing');

        $this->assertDatabaseHas('petty_cash_top_ups', ['id' => $topUp->id]);
    }

    public function test_forced_run_wipes_history_and_rebuilds_the_balance(): void
    {
        $user = User::factory()->create();
        PettyCashTopUp::create([
            'amount' => 5000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->toDateString(), 'created_by' => $user->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 5000.00]);

        $this->artisan('petty-cash:clear-all-non-production', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('petty_cash_top_ups', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', (string) PettyCashBalance::current()->fresh()->current_balance);
        $this->assertDatabaseHas('petty_cash_activity_logs', ['action' => 'cleared']);
    }

    public function test_without_force_it_asks_first_and_does_nothing_on_no(): void
    {
        $user = User::factory()->create();
        PettyCashTopUp::create([
            'amount' => 2500.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->toDateString(), 'created_by' => $user->id,
        ]);

        $this->artisan('petty-cash:clear-all-non-production')
            ->expectsConfirmation(
                'This will permanently delete every petty cash disbursement, top-up, allocation and ledger entry in the testing database. This cannot be undone. Continue?',
                'no',
            )
            ->assertExitCode(0);

        $this->assertDatabaseCount('petty_cash_top_ups', 1);
    }
}
