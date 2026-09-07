<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\LedgerExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 5)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);
        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
        $this->actingAs($reader, 'sanctum');
    }

    private function cost(string $date, string $nature = CostLine::NATURE_ACTUAL): CostLine
    {
        return CostLine::create([
            'ref' => 'CL-INTEGRITY-'.CostLine::count(),
            'nature' => $nature,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'base_net_amount' => '100.00',
            'tax_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => $date,
        ]);
    }

    public function test_same_month_reversal_nets_to_zero_in_every_account(): void
    {
        $cost = $this->cost('2026-09-01');
        $posting = app(JournalPostingService::class);
        $posting->postCostLine($cost);
        $posting->reverseCostLine($cost, auth()->id(), 'Duplicate receipt');

        $summary = $this->getJson('/api/finance/journals/trial-balance?from=2026-09-01&to=2026-09-30')
            ->assertOk()->json('data');
        $this->assertNotEmpty($summary['accounts']);
        foreach ($summary['accounts'] as $account) {
            $this->assertSame('0.00', $account['balance']);
        }

        $export = app(LedgerExportService::class)->documentJournals('2026-09-01', '2026-09-30');
        $this->assertSame(2, $export['totals']['source_entry_count']);
        $rows = collect($export['documents'])->flatMap(fn ($document) => $document['rows']);
        foreach ($rows->groupBy('account_code') as $legs) {
            $this->assertEquals($legs->sum('debit'), $legs->sum('credit'));
        }
    }

    public function test_later_reversal_preserves_the_original_month_and_export(): void
    {
        $cost = $this->cost('2026-08-10');
        $posting = app(JournalPostingService::class);
        $posting->postCostLine($cost);
        $path = '/api/finance/journals/trial-balance?from=2026-08-01&to=2026-08-31';
        $before = $this->getJson($path)->assertOk()->json('data');
        $exporter = app(LedgerExportService::class);
        $exportBefore = $exporter->documentJournals('2026-08-01', '2026-08-31');

        $posting->reverseCostLine($cost, auth()->id(), 'September correction');

        $this->assertSame($before, $this->getJson($path)->assertOk()->json('data'));
        $this->assertSame($exportBefore, $exporter->documentJournals('2026-08-01', '2026-08-31'));
        $this->assertSame(1, $exporter->documentJournals('2026-09-01', '2026-09-30')['totals']['source_entry_count']);
    }

    public function test_commitments_are_not_reported_as_missing_journals(): void
    {
        $this->cost('2026-09-01', CostLine::NATURE_COMMITTED);
        $this->cost('2026-09-01', CostLine::NATURE_PLANNED);
        $this->getJson('/api/finance/readiness')->assertOk()
            ->assertJsonPath('data.integrity.verified_costs_without_journal', 0);
        $this->cost('2026-09-01');
        $this->getJson('/api/finance/readiness')->assertOk()
            ->assertJsonPath('data.integrity.verified_costs_without_journal', 1);
    }

    public function test_inactive_control_accounts_block_readiness(): void
    {
        ChartOfAccount::where('code', '1030')->update(['is_active' => false]);
        $data = $this->getJson('/api/finance/readiness')->assertOk()->json('data');
        $this->assertFalse($data['ready']);
        $checks = collect($data['checks'])->keyBy('key');
        $this->assertFalse($checks['required_accounts']['ready']);
        $this->assertFalse($checks['payment_sources']['ready']);
    }

    public function test_inactive_expense_account_blocks_readiness(): void
    {
        ChartOfAccount::where('code', '1211')->update(['is_active' => false]);
        $checks = collect($this->getJson('/api/finance/readiness')->assertOk()->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['expense_codes']['ready']);
    }

    public function test_inventory_and_input_vat_control_accounts_are_required(): void
    {
        ChartOfAccount::whereIn('code', ['1200', '1330'])->update(['is_postable' => false]);
        $checks = collect($this->getJson('/api/finance/readiness')->assertOk()->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['required_accounts']['ready']);
        $this->assertStringContainsString('1200', $checks['required_accounts']['message']);
        $this->assertStringContainsString('1330', $checks['required_accounts']['message']);
    }

    public function test_an_explicit_payment_source_cannot_post_to_an_inactive_account(): void
    {
        $cost = $this->cost('2026-09-01');
        $cost->update(['details' => [
            'payment_source_id' => \App\Modules\Finance\Models\PaymentSource::where('code', 'PC-MAIN')->value('id'),
        ]]);
        ChartOfAccount::where('code', '1030')->update(['is_active' => false]);

        try {
            app(JournalPostingService::class)->postCostLine($cost);
            $this->fail('An inactive cash account accepted a posting.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('inactive or non-postable', $exception->getMessage());
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertNull($cost->fresh()->journal_entry_id);
    }

    public function test_a_cash_balance_that_disagrees_with_its_ledger_blocks_readiness(): void
    {
        \Illuminate\Support\Facades\DB::table('petty_cash_balances')->updateOrInsert(
            ['id' => 1], ['current_balance' => '100.00']
        );
        $this->getJson('/api/finance/readiness')->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.integrity.petty_cash_balance_mismatch', 1);
    }
}
