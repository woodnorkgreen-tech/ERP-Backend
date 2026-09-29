<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Support\FinanceChartProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionPurchaseCategoryHotfixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FinanceChartProfile::flush();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('expense_codes')->update(['default_debit_account_id' => null]);
        DB::table('chart_of_accounts')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/wng_chart_of_accounts.json')),
            true,
        );

        foreach ($fixture['accounts'] as $account) {
            DB::table('chart_of_accounts')->insert($account + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_chart_completion_is_registered_and_dry_run_writes_nothing(): void
    {
        $before = DB::table('chart_of_accounts')->count();

        $this->artisan('finance:complete-chart', [
            '--profile' => 'wng',
            '--wip-policy' => FinanceChartProfile::WIP_CAPITALISE,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Functions resolved: 37 / 37')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('chart_of_accounts')->count());
    }

    public function test_completion_and_expense_seed_expose_procurement_purchase_categories(): void
    {
        $database = (string) DB::selectOne('SELECT DATABASE() AS db')->db;

        $this->artisan('finance:complete-chart', [
            '--profile' => 'wng',
            '--wip-policy' => FinanceChartProfile::WIP_CAPITALISE,
            '--execute' => true,
            '--confirm' => $database,
        ])->assertSuccessful();

        config([
            'finance_accounts.profile' => 'wng',
            'finance_accounts.wip_policy' => FinanceChartProfile::WIP_CAPITALISE,
            'finance_accounts.map' => FinanceChartProfile::map(
                'wng',
                FinanceChartProfile::WIP_CAPITALISE,
            ),
        ]);

        $this->seed(ExpenseCodeSeeder::class);

        $visible = DB::table('expense_codes')
            ->where('is_active', true)
            ->where('is_procurable', true);

        $this->assertGreaterThan(2, $visible->count());
        $this->assertTrue((clone $visible)->where('code', 'DM-WD-001')->exists());
        $this->assertTrue((clone $visible)->where('code', 'OE-OFF-001')->exists());
        $this->assertTrue((clone $visible)->where('code', 'TL-HIR-001')->exists());

        $unresolved = DB::table('expense_codes')
            ->where('default_debit_gl', 'REGEXP', '^[0-9]{4}')
            ->whereNull('default_debit_account_id')
            ->pluck('default_debit_gl')
            ->map(fn ($gl) => substr($gl, 0, 4))
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['2300'], $unresolved);
    }

    public function test_execution_refuses_an_existing_conflicting_account(): void
    {
        DB::table('chart_of_accounts')->insert([
            'code' => 'VAT-001',
            'name' => 'Wrong VAT account',
            'category' => 'liability',
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $database = (string) DB::selectOne('SELECT DATABASE() AS db')->db;

        $this->artisan('finance:complete-chart', [
            '--profile' => 'wng',
            '--wip-policy' => FinanceChartProfile::WIP_CAPITALISE,
            '--execute' => true,
            '--confirm' => $database,
        ])->expectsOutputToContain('REFUSED')->assertFailed();

        $this->assertFalse(
            DB::table('chart_of_accounts')->where('code', 'WIP-002')->exists(),
        );
    }

    public function test_unknown_wip_policy_is_refused_before_any_write(): void
    {
        $database = (string) DB::selectOne('SELECT DATABASE() AS db')->db;
        $before = DB::table('chart_of_accounts')->count();

        $this->artisan('finance:complete-chart', [
            '--profile' => 'wng',
            '--wip-policy' => 'not-an-accounting-policy',
            '--execute' => true,
            '--confirm' => $database,
        ])->expectsOutputToContain('is not defined')
            ->assertFailed();

        $this->assertSame($before, DB::table('chart_of_accounts')->count());
        $this->assertFalse(
            DB::table('chart_of_accounts')->where('code', 'WIP-002')->exists(),
        );
    }
}
