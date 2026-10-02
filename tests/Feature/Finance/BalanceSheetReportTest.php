<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BalanceSheetReportTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 30)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);
        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    /** @param array<int, array{0:ChartOfAccount,1:string,2:string}> $legs */
    private function createPostedEntry(array $legs): JournalEntry
    {
        $debit = collect($legs)->where(fn (array $leg) => $leg[2] === 'debit')->reduce(fn (string $sum, array $leg) => bcadd($sum, $leg[1], 2), '0.00');
        $credit = collect($legs)->where(fn (array $leg) => $leg[2] === 'credit')->reduce(fn (string $sum, array $leg) => bcadd($sum, $leg[1], 2), '0.00');
        $entry = JournalEntry::create([
            'entry_no' => 'JE-BSR-' . uniqid(),
            'posting_date' => '2026-09-15',
            'accounting_period_id' => AccountingPeriod::forDate(\Carbon\Carbon::parse('2026-09-15'))->id,
            'source_type' => 'BalanceSheetReportTest',
            'source_id' => 0,
            'description' => 'Balance sheet report test',
            'total_debit' => $debit,
            'total_credit' => $credit,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        foreach ($legs as [$account, $amount, $side]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'entry_type' => $side,
                'amount' => $amount,
                'base_amount' => $amount,
                'currency' => 'KES',
                'fx_rate' => 1,
            ]);
        }
        return $entry;
    }

    private function report()
    {
        return $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/balance-sheet?as_at=2026-09-30');
    }

    public function test_permission_is_required(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/reports/balance-sheet?as_at=2026-09-30')
            ->assertForbidden();
    }

    public function test_classifies_posted_asset_liability_and_equity_and_calculates_equation_without_balancing(): void
    {
        $asset = ChartOfAccount::query()->where('category', 'asset')->firstOrFail();
        $liability = ChartOfAccount::query()->where('category', 'liability')->firstOrFail();
        $equity = ChartOfAccount::query()->where('category', 'equity')->firstOrFail();
        $this->createPostedEntry([[$asset, '150.00', 'debit'], [$liability, '100.00', 'credit'], [$equity, '50.00', 'credit']]);

        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('150.00', $data['totals']['total_assets']);
        $this->assertSame('100.00', $data['totals']['total_liabilities']);
        $this->assertSame('50.00', $data['totals']['total_equity']);
        $this->assertSame('0.00', $data['totals']['difference']);
        $this->assertTrue($data['totals']['balanced']);
        $this->assertSame('asset', $data['sections']['asset'][0]['category']);
        $this->assertSame('liability', $data['sections']['liability'][0]['category']);
        $this->assertSame('equity', $data['sections']['equity'][0]['category']);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_equation_difference_does_not_create_a_balancing_equity_entry(): void
    {
        $asset = ChartOfAccount::query()->where('category', 'asset')->firstOrFail();
        $liability = ChartOfAccount::query()->where('category', 'liability')->firstOrFail();
        $revenue = ChartOfAccount::query()->where('category', 'revenue')->firstOrFail();
        $this->createPostedEntry([
            [$asset, '150.00', 'debit'],
            [$liability, '100.00', 'credit'],
            [$revenue, '50.00', 'credit'],
        ]);

        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('50.00', $data['totals']['difference']);
        $this->assertFalse($data['totals']['balanced']);
        $this->assertSame('0.00', $data['totals']['equity']);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_credit_normal_contra_asset_reduces_assets_in_the_equation(): void
    {
        $asset = ChartOfAccount::query()->where('category', 'asset')->firstOrFail();
        $equity = ChartOfAccount::query()->where('category', 'equity')->firstOrFail();
        $contra = ChartOfAccount::create([
            'name' => 'Accumulated depreciation', 'code' => 'BS-CONTRA',
            'category' => 'asset', 'account_type' => 'balance_sheet',
            'normal_balance' => 'credit', 'is_postable' => true, 'is_active' => true,
        ]);
        $this->createPostedEntry([[$asset, '150.00', 'debit'], [$contra, '25.00', 'credit'], [$equity, '125.00', 'credit']]);

        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('-25.00', collect($data['sections']['asset'])->firstWhere('account_id', $contra->id)['balance']);
        $this->assertSame('125.00', $data['totals']['total_assets']);
        $this->assertSame('0.00', $data['totals']['difference']);
        $this->assertTrue($data['totals']['balanced']);
    }

    public function test_unclassified_posted_balance_sheet_accounts_are_preserved_for_review(): void
    {
        $asset = ChartOfAccount::query()->where('category', 'asset')->firstOrFail();
        $unknown = ChartOfAccount::create([
            'name' => 'Unclassified balance account', 'code' => 'BS-UNKNOWN', 'category' => 'revenue',
            'account_type' => 'balance_sheet', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
        ]);
        $this->createPostedEntry([[$asset, '25.00', 'debit'], [$unknown, '25.00', 'credit']]);

        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('BS-UNKNOWN', $data['sections']['unclassified'][0]['account_code']);
        $this->assertSame('REQUIRES_ACCOUNTANT_REVIEW', $data['sections']['unclassified'][0]['review_status']);
    }

    public function test_missing_opening_balances_and_retained_earnings_are_reported_as_readiness_not_created(): void
    {
        $data = $this->report()->assertOk()->json('data');

        $this->assertSame('NOT_CONFIGURED', $data['readiness']['opening_balance_status']);
        $this->assertFalse($data['readiness']['historical_data_complete']);
        $this->assertSame('HISTORICAL_DATA_INCOMPLETE', $data['readiness']['statement_readiness']);
        $this->assertFalse($data['readiness']['equity_complete']);
        $this->assertStringContainsString('retained earnings are not generated', $data['readiness']['reason']);
    }
}
