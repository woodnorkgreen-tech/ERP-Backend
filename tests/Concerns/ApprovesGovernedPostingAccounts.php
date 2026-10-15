<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\GovernedPostingAccount;

/**
 * Test fixture: an approved, ACTIVE governed posting account.
 *
 * Since Report 76A-3 a stock count, an opening balance or a stock adjustment
 * posts only to an account Finance has approved — the reference chart no longer
 * answers. Tests that are about something else state the approval here, the way
 * they state any other precondition. This writes the active version directly;
 * the approval workflow itself is tested through its API in Report76APart2Test.
 */
trait ApprovesGovernedPostingAccounts
{
    protected function activateGovernedAccount(string $function, string $accountCode): void
    {
        $proposer = User::query()->value('id') ?? User::factory()->create(['is_active' => true])->id;
        $key = GovernedPostingAccount::item($function);

        FinanceConfigVersion::create([
            'item_key' => $key, 'domain' => 'policies',
            'version' => (int) FinanceConfigVersion::where('item_key', $key)->max('version') + 1,
            'value' => ['account_code' => $accountCode], 'status' => FinanceConfigVersion::ACTIVE,
            'proposed_by' => $proposer, 'in_force_from' => now()->subYear()->toDateString(),
        ]);
        GovernanceRuntime::flush();
    }

    /** Inventory adjustments and opening stock, on the accounts the seeded chart gives them. */
    protected function activateStockPostingAccounts(): void
    {
        foreach ([
            GovernedPostingAccount::STOCK_INVENTORY_ADJUSTMENT => FinanceAccountFunctions::INVENTORY_ADJUSTMENTS,
            GovernedPostingAccount::STOCK_OPENING_BALANCE => FinanceAccountFunctions::OPENING_BALANCE_EQUITY,
        ] as $function => $reference) {
            $code = ChartAccountMap::local($reference);
            if (ChartOfAccount::where('code', $code)->exists()) {
                $this->activateGovernedAccount($function, $code);
            }
        }
    }

    /**
     * Everything a Stores movement can need: adjustments and opening stock, plus
     * company use, damage and waste. For suites about stock handling, where the
     * choice of account is not what is being tested, all three losses are put on
     * the chart's inventory-adjustments account.
     */
    protected function activateStoresPostingAccounts(): void
    {
        $this->activateStockPostingAccounts();
        $code = ChartAccountMap::local(FinanceAccountFunctions::INVENTORY_ADJUSTMENTS);
        if (! ChartOfAccount::where('code', $code)->exists()) {
            return;
        }
        foreach ([GovernedPostingAccount::STOCK_INTERNAL_CONSUMPTION, GovernedPostingAccount::STOCK_DAMAGE_LOSS, GovernedPostingAccount::STOCK_WASTE_LOSS] as $function) {
            $this->activateGovernedAccount($function, $code);
        }
    }
}
