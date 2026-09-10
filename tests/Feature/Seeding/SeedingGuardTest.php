<?php

namespace Tests\Feature\Seeding;

use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two things that must hold for `db:seed` to be safe to type on a server.
 *
 * Demo data must refuse to run outside local and testing, and the reference
 * chart of accounts must not be planted on an installation that keeps its own.
 * Both were true of no environment before: nothing checked, so a production
 * seed would have created `superadmin@company.com` with the password
 * `password` and stood 88 numeric accounts beside WNG's real chart.
 */
class SeedingGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run one seeder without going through the `db:seed` command.
     *
     * `$this->seed()` dispatches the command, and the command carries Laravel's
     * own production confirmation — which is a second, welcome rail, but it
     * prompts before the guard under test is ever reached. A deploy script types
     * `--force` and walks past it, so the guard has to hold on its own.
     */
    private function runSeeder(string $class): void
    {
        $this->app->make($class)->setContainer($this->app)->__invoke();
    }

    public function test_demo_data_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->runSeeder(DemoDataSeeder::class);

        $this->assertSame(0, DB::table('users')->where('email', 'like', '%@company.com')->count());
        $this->assertSame(0, DB::table('employees')->count());
        $this->assertSame(0, DB::table('clients')->count());
    }

    public function test_each_demo_seeder_refuses_on_its_own(): void
    {
        // `--class=` addresses a seeder directly, so a guard only on the
        // aggregate would be walked straight past.
        $this->app->detectEnvironment(fn () => 'production');

        $this->runSeeder(SuperAdminUserSeeder::class);
        $this->runSeeder(EmployeeSeeder::class);

        $this->assertSame(0, DB::table('users')->where('email', 'superadmin@company.com')->count());
        $this->assertSame(0, DB::table('employees')->count());
    }

    public function test_demo_data_still_seeds_in_testing(): void
    {
        // Departments and roles first: the demo employees hang off both.
        $this->runSeeder(ReferenceDataSeeder::class);

        $this->runSeeder(DemoDataSeeder::class);

        $this->assertSame(1, DB::table('users')->where('email', 'superadmin@company.com')->count());
        $this->assertGreaterThan(0, DB::table('employees')->count());
    }

    public function test_the_reference_chart_is_left_alone_where_the_company_keeps_its_own(): void
    {
        config(['finance_accounts.seed_reference_chart' => false]);

        // Stand in for a chart the ERP did not write — WNG's is mnemonic.
        DB::table('chart_of_accounts')->insert([
            'code' => 'AR-001', 'name' => 'Accounts Receivable', 'category' => 'asset',
            'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runSeeder(ChartOfAccountSeeder::class);

        // Not a total: three numeric codes (2160, 7150, 7550) are inserted by
        // migrations, so the chart is never empty. What matters is that the
        // seeder added none of its own and touched nothing that was there.
        $this->assertSame(1, DB::table('chart_of_accounts')->where('code', 'AR-001')->where('is_active', true)->count());
        $this->assertSame(0, DB::table('chart_of_accounts')->where('code', '1030')->count());
        $this->assertSame(0, DB::table('chart_of_accounts')->where('code', '5100')->count());
    }

    public function test_the_reference_chart_is_seeded_where_this_installation_keeps_it(): void
    {
        config(['finance_accounts.seed_reference_chart' => true]);

        $this->runSeeder(ChartOfAccountSeeder::class);

        $this->assertGreaterThan(80, DB::table('chart_of_accounts')->count());
        $this->assertSame(1, DB::table('chart_of_accounts')->where('code', '1030')->count());
    }

    public function test_seeding_the_chart_twice_leaves_a_foreign_account_untouched(): void
    {
        // The purge pass this replaces deleted every account outside its own
        // list, and only checked four of the eight columns pointing at the
        // chart before doing it.
        config(['finance_accounts.seed_reference_chart' => true]);
        $this->runSeeder(ChartOfAccountSeeder::class);

        DB::table('chart_of_accounts')->insert([
            'code' => 'COS-001', 'name' => 'Cost of Sales – imported', 'category' => 'expense',
            'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runSeeder(ChartOfAccountSeeder::class);

        $foreign = DB::table('chart_of_accounts')->where('code', 'COS-001')->first();
        $this->assertNotNull($foreign, 'an account outside the reference chart was deleted');
        $this->assertTrue((bool) $foreign->is_active, 'an account outside the reference chart was deactivated');
    }
}
