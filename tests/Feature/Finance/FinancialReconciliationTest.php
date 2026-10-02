<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\FinancialReconciliationService;
use App\Modules\Finance\Services\BankCashReportingService;
use App\Modules\ProcurementStores\Services\StoresValuationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinancialReconciliationTest extends TestCase
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

    public function test_endpoint_requires_finance_reports_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30')
            ->assertForbidden();
    }

    public function test_returns_all_seven_control_families_with_canonical_contract_and_statuses(): void
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30')
            ->assertOk();

        $this->assertSame(['AR', 'AP', 'INVENTORY', 'PAYROLL', 'PETTY_CASH', 'WIP', 'BANK_CASH'], array_column($response->json('data.controls'), 'control_key'));
        foreach ($response->json('data.controls') as $control) {
            $this->assertArrayHasKey('control_name', $control);
            $this->assertArrayHasKey('subledger_balance', $control);
            $this->assertArrayHasKey('gl_balance', $control);
            $this->assertArrayHasKey('difference', $control);
            $this->assertArrayHasKey('as_of_date', $control);
            $this->assertArrayHasKey('readiness', $control);
            $this->assertArrayHasKey('reason', $control);
            $this->assertArrayHasKey('drill_down', $control);
            $this->assertContains($control['status'], [
                FinancialReconciliationService::RECONCILED,
                FinancialReconciliationService::DIFFERENCE,
                FinancialReconciliationService::NOT_READY,
                FinancialReconciliationService::POLICY_BLOCKED,
                FinancialReconciliationService::DATA_INCOMPLETE,
            ]);
        }
    }

    public function test_authoritative_empty_ar_computes_zero_difference_and_unavailable_controls_are_gated(): void
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30')
            ->assertOk();
        $controls = collect($response->json('data.controls'))->keyBy('control_key');

        $this->assertSame('0.00', $controls['AR']['subledger_balance']);
        $this->assertSame('0.00', $controls['AR']['gl_balance']);
        $this->assertSame('0.00', $controls['AR']['difference']);
        $this->assertSame(FinancialReconciliationService::RECONCILED, $controls['AR']['status']);
        $this->assertSame(FinancialReconciliationService::NOT_READY, $controls['AP']['status']);
        $this->assertSame(FinancialReconciliationService::POLICY_BLOCKED, $controls['WIP']['status']);
        $this->assertSame(FinancialReconciliationService::NOT_READY, $controls['PAYROLL']['status']);
        $this->assertSame(FinancialReconciliationService::NOT_READY, $controls['BANK_CASH']['status']);
        $this->assertNull($controls['AP']['difference']);
    }

    public function test_control_filter_returns_one_control(): void
    {
        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30&control=WIP')
            ->assertOk();

        $this->assertCount(1, $response->json('data.controls'));
        $this->assertSame('WIP', $response->json('data.controls.0.control_key'));
    }

    public function test_bank_cash_consumes_the_canonical_position_without_reconciling_gl_to_itself(): void
    {
        $this->mock(BankCashReportingService::class, function ($mock) {
            $mock->shouldReceive('position')->once()->with('2026-09-30')->andReturn([
                'total_cash_and_bank' => '-123.45', 'accounts' => [['account_id' => 1]],
                'configuration' => [], 'readiness' => 'HISTORICAL_DATA_INCOMPLETE',
                'opening_balance_status' => 'NOT_CONFIGURED',
            ]);
        });
        $control = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30&control=BANK_CASH')
            ->assertOk()->json('data.controls.0');
        $this->assertSame('-123.45', $control['gl_balance']);
        $this->assertNull($control['subledger_balance']);
        $this->assertNull($control['difference']);
        $this->assertSame(FinancialReconciliationService::NOT_READY, $control['status']);
        $this->assertStringContainsString('BankCashReportingService', $control['evidence']['gl_source']);
        $this->assertFalse($control['evidence']['external_statement_data_available']);
    }

    public function test_ar_difference_is_subledger_minus_posted_gl(): void
    {
        $receivable = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $payable = ChartOfAccount::query()->where('code', '2100')->firstOrFail();
        $entry = JournalEntry::create([
            'entry_no' => 'JE-RECON-'.uniqid(),
            'posting_date' => '2026-09-30',
            'accounting_period_id' => AccountingPeriod::forDate(\Carbon\Carbon::parse('2026-09-30'))->id,
            'source_type' => 'FinancialReconciliationTest',
            'source_id' => 0,
            'description' => 'AR reconciliation difference fixture',
            'total_debit' => '100.00',
            'total_credit' => '100.00',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        foreach ([[$receivable, 'debit'], [$payable, 'credit']] as [$account, $side]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'entry_type' => $side,
                'amount' => '100.00',
                'base_amount' => '100.00',
                'currency' => 'KES',
                'fx_rate' => 1,
            ]);
        }

        $response = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30&control=AR')
            ->assertOk();

        $this->assertSame('0.00', $response->json('data.controls.0.subledger_balance'));
        $this->assertSame('100.00', $response->json('data.controls.0.gl_balance'));
        $this->assertSame('-100.00', $response->json('data.controls.0.difference'));
        $this->assertSame(FinancialReconciliationService::DIFFERENCE, $response->json('data.controls.0.status'));
    }

    public function test_incomplete_stores_valuation_gates_inventory_difference(): void
    {
        $this->mock(StoresValuationReadinessService::class, function ($mock) {
            $mock->shouldReceive('project')->once()->andReturn([
                'summary' => ['materials' => 1, 'valued' => 0, 'unvalued' => 1, 'requires_review' => 0, 'authoritative_inventory_value' => 0],
                'data' => [],
            ]);
        });

        $control = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/finance/reports/reconciliations?as_at=2026-09-30&control=INVENTORY')
            ->assertOk()
            ->json('data.controls.0');

        $this->assertSame(FinancialReconciliationService::DATA_INCOMPLETE, $control['status']);
        $this->assertNull($control['difference']);
        $this->assertStringContainsString('no variance is asserted', $control['reason']);
    }
}
