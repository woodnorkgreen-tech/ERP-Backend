<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two top-up endpoints that moved cash with no authorization, and deleted
 * with no ledger reversal (audit BE1/BE2).
 *
 * The invariant these pin down is the one the whole module rests on: the cached
 * balance must always equal the ledger it claims to summarise.
 */
class TopUpIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $custodian;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            Permissions::FINANCE_PETTY_CASH_EDIT_TOP_UP,
            Permissions::FINANCE_PETTY_CASH_DELETE_TOP_UP,
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->custodian = User::factory()->create(['is_active' => true]);
        $this->custodian->givePermissionTo([
            Permissions::FINANCE_PETTY_CASH_EDIT_TOP_UP,
            Permissions::FINANCE_PETTY_CASH_DELETE_TOP_UP,
        ]);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function topUp(float $amount = 50000): PettyCashTopUp
    {
        $topUp = PettyCashTopUp::create([
            'amount' => $amount,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subDay()->toDateString(),
            'created_by' => $this->custodian->id,
        ]);

        app(LedgerService::class)->post(
            \App\Modules\Finance\PettyCash\Services\LedgerEntry::creditForTopUp($topUp),
        );

        return $topUp;
    }

    private function derivedBalance(): string
    {
        $credits = DB::table('petty_cash_ledger_entries')->where('type', 'credit')->sum('amount');
        $debits = DB::table('petty_cash_ledger_entries')->where('type', 'debit')->sum('amount');

        return bcsub((string) $credits, (string) $debits, 2);
    }

    public function test_deleting_a_top_up_reverses_its_ledger_entry(): void
    {
        $topUp = $this->topUp(50000);

        $this->assertSame('50000.00', $this->derivedBalance());

        $this->actingAs($this->custodian, 'sanctum')
            ->deleteJson("/api/finance/petty-cash/top-ups/{$topUp->id}")
            ->assertOk();

        // The credit is backed out, not merely orphaned.
        $this->assertSame('0.00', $this->derivedBalance());
        $this->assertSame(
            '0.00',
            number_format((float) PettyCashBalance::find(LedgerService::BALANCE_ID)->current_balance, 2, '.', ''),
        );
        $this->assertDatabaseMissing('petty_cash_top_ups', ['id' => $topUp->id]);
    }

    public function test_the_cached_balance_still_equals_its_ledger_after_a_delete(): void
    {
        $this->topUp(50000);
        $doomed = $this->topUp(20000);

        $this->actingAs($this->custodian, 'sanctum')
            ->deleteJson("/api/finance/petty-cash/top-ups/{$doomed->id}")
            ->assertOk();

        $cached = number_format(
            (float) PettyCashBalance::find(LedgerService::BALANCE_ID)->current_balance, 2, '.', '',
        );

        $this->assertSame('50000.00', $cached);
        $this->assertSame($cached, $this->derivedBalance());
    }

    public function test_deleting_a_top_up_requires_permission(): void
    {
        $topUp = $this->topUp();

        $this->actingAs($this->outsider, 'sanctum')
            ->deleteJson("/api/finance/petty-cash/top-ups/{$topUp->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('petty_cash_top_ups', ['id' => $topUp->id]);
        $this->assertSame('50000.00', $this->derivedBalance());
    }

    public function test_editing_a_top_up_requires_permission(): void
    {
        $topUp = $this->topUp();

        // This endpoint posts an adjustment entry for the amount delta, so an
        // unauthorised caller reaching it moves real cash.
        $this->actingAs($this->outsider, 'sanctum')
            ->putJson("/api/finance/petty-cash/top-ups/{$topUp->id}", [
                'amount' => 999999,
                'payment_method' => 'cash',
                'date_topped_up' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame('50000.00', $this->derivedBalance());
    }
}
