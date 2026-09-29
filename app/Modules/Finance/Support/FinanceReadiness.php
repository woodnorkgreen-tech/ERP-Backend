<?php

namespace App\Modules\Finance\Support;

use App\Constants\RolePermissions;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Whether this installation holds the reference data Finance cannot run without.
 *
 * Written after production served a purchase-category picker with 2 of 76
 * categories. Migrations had run and ExpenseCodeSeeder had run inside one of
 * them, but the chart of accounts held only the one account that migration
 * creates. The seeder deactivates any code whose debit account is missing, so
 * 74 purchase categories disappeared without an error. The app looked healthy
 * and the deploy was green; users found out first.
 *
 * Every check here reads data and nothing else. None of them seeds. Re-seeding
 * on every deploy is not the answer: most reference seeders overwrite rows
 * that Finance and HR edit in the app. Each failure names its own remedy
 * instead, so fixing it stays a step somebody takes deliberately.
 */
class FinanceReadiness
{
    private const SEED = 'FINANCE_SEED_REFERENCE_CHART=true php artisan db:seed --class=ReferenceDataSeeder --force'
        .' (after php artisan config:clear; see docs/seeding-in-production.md)';

    /**
     * @return list<array{check: string, ok: bool, detail: string, fix: ?string}>
     */
    public function checks(): array
    {
        return [
            $this->chart(),
            $this->expenseCodeAccounts(),
            $this->purchaseCategories(),
            $this->payingAccounts(),
            $this->currentPeriod(),
            $this->roles(),
        ];
    }

    public function passes(): bool
    {
        return collect($this->checks())->every(fn (array $check) => $check['ok']);
    }

    private function chart(): array
    {
        $count = DB::table('chart_of_accounts')->where('is_postable', true)->count();

        return $this->result('Chart of accounts', $count > 0, "{$count} postable accounts", self::SEED);
    }

    /**
     * Codes whose catalogue text names a concrete account this chart cannot supply.
     *
     * Codes that name an account indirectly ("receiving bank account") resolve
     * to no code, and codes that name a header ("relevant 1400 PPE account")
     * wait for a person to pick a child. Both are inactive by design and are
     * not counted.
     */
    private function expenseCodeAccounts(): array
    {
        $postable = DB::table('chart_of_accounts')->pluck('is_postable', 'code');
        $missing = [];
        $unlinked = [];

        foreach (ExpenseCode::query()->get(['code', 'default_debit_gl', 'default_debit_account_id']) as $code) {
            $local = ChartAccountMap::localFromGl($code->default_debit_gl);
            if ($local === null || ($postable->has($local) && ! $postable[$local])) {
                continue;
            }
            if (! $postable->has($local)) {
                $missing[$local][] = $code->code;
            } elseif ($code->default_debit_account_id === null) {
                $unlinked[] = $code->code;
            }
        }

        if ($missing === [] && $unlinked === []) {
            return $this->result('Expense-code accounts', true, 'every code resolves its debit account', null);
        }

        $parts = [];
        if ($missing !== []) {
            $codes = array_sum(array_map('count', $missing));
            $parts[] = "{$codes} codes name accounts missing from the chart (".implode(', ', array_slice(array_keys($missing), 0, 8))
                .(count($missing) > 8 ? ', …' : '').')';
        }
        if ($unlinked !== []) {
            $parts[] = count($unlinked).' codes are not linked to an account the chart now has ('
                .implode(', ', array_slice($unlinked, 0, 5)).(count($unlinked) > 5 ? ', …' : '').')';
        }

        return $this->result('Expense-code accounts', false, implode('; ', $parts),
            $missing !== [] ? self::SEED : 'php artisan db:seed --class="App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder" --force');
    }

    private function purchaseCategories(): array
    {
        $count = ExpenseCode::active()->where('is_procurable', true)->count();
        $families = ExpenseCode::active()->where('is_procurable', true)->distinct()->count('expense_family');

        return $this->result('Purchase categories', $count > 0, "{$count} active in {$families} families", self::SEED);
    }

    private function payingAccounts(): array
    {
        $count = DB::table('payment_sources')->where('is_active', true)->where('can_make_payment', true)->count();

        return $this->result('Paying accounts', $count > 0, "{$count} active", self::SEED);
    }

    private function currentPeriod(): array
    {
        $today = now()->toDateString();
        $exists = DB::table('accounting_periods')->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->exists();

        return $this->result('Accounting period', $exists, $exists ? "covers {$today}" : "none covers {$today}", self::SEED);
    }

    /** Permission migrations grant to these by name and skip the ones that do not exist. */
    private function roles(): array
    {
        $expected = array_keys(RolePermissions::matrix());
        $missing = array_values(array_diff($expected, Role::query()->whereIn('name', $expected)->pluck('name')->all()));

        return $this->result('Roles', $missing === [],
            $missing === [] ? count($expected).' present' : 'missing: '.implode(', ', $missing), self::SEED);
    }

    private function result(string $check, bool $ok, string $detail, ?string $fix): array
    {
        return ['check' => $check, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok ? null : $fix];
    }
}
