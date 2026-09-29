<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Support\FinanceReadiness;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production ran with 2 of 76 purchase categories after a migrations-only
 * install (2026-09-29). These pin the guard that makes that visible at deploy
 * time, and the seeder rules that let it be repaired without undoing Finance's
 * own edits.
 */
class FinanceReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_migrations_only_install_fails_readiness_as_production_did(): void
    {
        // The exact production state: the migration's own 7150 account, and two
        // active codes (the ones that post to it).
        $this->assertSame(2, ExpenseCode::active()->where('is_procurable', true)->count());

        $checks = collect(app(FinanceReadiness::class)->checks())->keyBy('check');

        $this->assertFalse($checks['Expense-code accounts']['ok']);
        $this->assertStringContainsString('missing from the chart', $checks['Expense-code accounts']['detail']);
        $this->assertStringContainsString('FINANCE_SEED_REFERENCE_CHART=true', $checks['Expense-code accounts']['fix']);
        $this->assertFalse($checks['Roles']['ok']);

        $this->artisan('finance:readiness')
            ->expectsOutputToContain('readiness check(s) failed')
            ->assertFailed();
    }

    public function test_reference_data_makes_the_install_ready(): void
    {
        config(['finance_accounts.seed_reference_chart' => true]);
        $this->seed(ReferenceDataSeeder::class);

        $failed = collect(app(FinanceReadiness::class)->checks())->reject(fn ($check) => $check['ok']);
        $this->assertSame([], $failed->values()->all());
        $this->assertGreaterThan(2, ExpenseCode::active()->where('is_procurable', true)->count());

        $this->artisan('finance:readiness')
            ->expectsOutputToContain('Finance reference data is ready.')
            ->assertSuccessful();
    }

    public function test_a_code_the_seeder_switched_off_comes_back_once_its_account_exists(): void
    {
        $this->assertFalse((bool) ExpenseCode::where('code', 'DM-WD-001')->value('is_active'));

        config(['finance_accounts.seed_reference_chart' => true]);
        $this->seed(ReferenceDataSeeder::class);

        $code = ExpenseCode::where('code', 'DM-WD-001')->first();
        $this->assertTrue((bool) $code->is_active);
        $this->assertNotNull($code->default_debit_account_id);
    }

    public function test_a_re_run_keeps_a_code_finance_switched_off(): void
    {
        config(['finance_accounts.seed_reference_chart' => true]);
        $this->seed(ReferenceDataSeeder::class);
        ExpenseCode::where('code', 'OE-OFF-002')->update(['is_active' => false]);

        $this->seed(ExpenseCodeSeeder::class);

        $this->assertFalse((bool) ExpenseCode::where('code', 'OE-OFF-002')->value('is_active'));
    }

    public function test_a_re_run_keeps_an_account_link_the_chart_can_no_longer_resolve(): void
    {
        config(['finance_accounts.seed_reference_chart' => true]);
        $this->seed(ReferenceDataSeeder::class);
        $linked = ExpenseCode::where('code', 'OE-OFF-001')->value('default_debit_account_id');
        $this->assertNotNull($linked);

        // A company chart without the reference 7150: resolution now yields null.
        config(['finance_accounts.map' => ['7150' => 'NOT-IN-CHART']]);
        $this->seed(ExpenseCodeSeeder::class);

        $code = ExpenseCode::where('code', 'OE-OFF-001')->first();
        $this->assertSame($linked, $code->default_debit_account_id);
        $this->assertTrue((bool) $code->is_active);
    }

    public function test_the_seeder_names_the_codes_it_leaves_inactive(): void
    {
        // Migrations-only chart: nearly every account is missing.
        $this->artisan('db:seed', ['--class' => ExpenseCodeSeeder::class])
            ->expectsOutputToContain('expense codes left inactive')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('expense_codes')->where('is_active', true)->whereNull('default_debit_account_id')->count());
    }
}
