<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CashReportingFixtures;
use Tests\TestCase;

class CashMovementReportTest extends TestCase
{
    use RefreshDatabase, CashReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCashReporting();
    }

    private function movement(string $from = '2026-09-01', string $to = '2026-09-30')
    {
        return $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/reports/cash-movement?from='.$from.'&to='.$to);
    }

    public function test_permission_missing_dates_invalid_dates_and_inverted_range(): void
    {
        $this->actingAs($this->outsider, 'sanctum')->getJson('/api/finance/reports/cash-movement?from=2026-09-01&to=2026-09-30')->assertForbidden();
        $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/reports/cash-movement')->assertUnprocessable();
        $this->movement('2026-09-30', '2026-09-01')->assertUnprocessable();
        $this->movement('2026-09-01', '2026-09-31')->assertUnprocessable();
    }

    public function test_period_activity_reconciles_to_recorded_opening_and_closing_without_claiming_approved_history(): void
    {
        $this->cashJournal('2026-08-31', [['1010', 'debit', '100.00'], ['2100', 'credit', '100.00']]);
        $this->cashJournal('2026-09-01', [['1010', 'debit', '50.00'], ['2100', 'credit', '50.00']]);
        $this->cashJournal('2026-09-30', [['1010', 'credit', '20.00'], ['2100', 'debit', '20.00']]);
        $this->cashJournal('2026-10-01', [['1010', 'debit', '999.00'], ['2100', 'credit', '999.00']]);
        $this->cashJournal('2026-09-15', [['1010', 'debit', '999.00'], ['2100', 'credit', '999.00']], 'draft');
        $data = $this->movement()->assertOk()->json('data');
        $this->assertNull($data['opening_cash_position']);
        $this->assertSame('100.00', $data['ledger_opening_cash_position']);
        $this->assertSame('50.00', $data['cash_inflows']);
        $this->assertSame('20.00', $data['cash_outflows']);
        $this->assertSame('30.00', $data['net_cash_movement']);
        $this->assertSame('130.00', $data['closing_cash_position']);
        $this->assertSame($data['closing_cash_position'], $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/reports/bank-cash-position?as_at=2026-09-30')->assertOk()->json('data.total_cash_and_bank'));
        $bank = collect($data['accounts'])->firstWhere('account_code', '1010');
        $this->assertNull($bank['opening_balance']);
        $this->assertSame('100.00', $bank['ledger_brought_forward_balance']);
        $this->assertSame('130.00', $bank['closing_balance']);
        $this->assertSame('HISTORICAL_DATA_INCOMPLETE', $data['readiness']);
        $this->assertFalse($data['historical_data_complete']);
    }

    public function test_internal_bank_and_petty_cash_transfers_are_eliminated_only_at_company_level(): void
    {
        $this->cashJournal('2026-09-10', [['1020', 'debit', '100.00'], ['1010', 'credit', '100.00']]);
        $this->cashJournal('2026-09-11', [['1030', 'debit', '25.00'], ['1020', 'credit', '25.00']]);
        $data = $this->movement()->assertOk()->json('data');
        $this->assertSame('0.00', $data['cash_inflows']);
        $this->assertSame('0.00', $data['cash_outflows']);
        $this->assertSame('0.00', $data['net_cash_movement']);
        $this->assertSame('0.00', $data['closing_cash_position']);
        $this->assertSame('125.00', $data['internal_transfers_eliminated']);
        $this->assertSame(2, $data['internal_transfer_journals']);
        $rows = collect($data['accounts'])->keyBy('account_code');
        $this->assertSame('100.00', $rows['1010']['period_credits']);
        $this->assertSame('100.00', $rows['1020']['period_debits']);
        $this->assertSame('25.00', $rows['1030']['period_debits']);
        $this->assertSame('-100.00', $rows['1010']['net_movement']);
        $this->assertSame('25.00', $rows['1030']['net_movement']);
        $this->assertSame($this->account('1030')->id, $rows['1030']['drill_down']['account_id']);
    }

    public function test_mixed_cash_and_fee_journals_are_not_guessed_to_be_pure_transfers(): void
    {
        $this->cashJournal('2026-09-10', [['1020', 'debit', '100.00'], ['7800', 'debit', '5.00'], ['1010', 'credit', '105.00']]);
        $data = $this->movement()->assertOk()->json('data');
        $this->assertSame('0.00', $data['internal_transfers_eliminated']);
        $this->assertSame('100.00', $data['cash_inflows']);
        $this->assertSame('105.00', $data['cash_outflows']);
        $this->assertSame('-5.00', $data['net_cash_movement']);
        $this->assertStringContainsString('Mixed journals retain gross activity', $data['transfer_method']);
    }

    public function test_original_and_real_reversal_remain_in_period_activity(): void
    {
        $original = $this->cashJournal('2026-09-10', [['1010', 'debit', '100.00'], ['2100', 'credit', '100.00']]);
        app(JournalPostingService::class)->reverseEntry($original, $this->reader->id, 'Correction');
        $data = $this->movement()->assertOk()->json('data');
        $this->assertSame('100.00', $data['cash_inflows']);
        $this->assertSame('100.00', $data['cash_outflows']);
        $this->assertSame('0.00', $data['net_cash_movement']);
        $this->assertSame('100.00', $this->movement('2026-09-01', '2026-09-15')->json('data.net_cash_movement'));
    }

    public function test_reversed_transfer_and_its_compensation_do_not_inflate_company_flows(): void
    {
        $original = $this->cashJournal('2026-09-10', [['1020', 'debit', '100.00'], ['1010', 'credit', '100.00']]);
        app(JournalPostingService::class)->reverseEntry($original, $this->reader->id, 'Transfer correction');
        $data = $this->movement()->assertOk()->json('data');
        $this->assertSame('200.00', $data['internal_transfers_eliminated']);
        $this->assertSame('0.00', $data['cash_inflows']);
        $this->assertSame('0.00', $data['cash_outflows']);
        $this->assertSame('0.00', $data['closing_cash_position']);
    }

    public function test_csv_matches_the_same_net_movement_and_discloses_transfer_elimination(): void
    {
        $this->cashJournal('2026-09-10', [['1020', 'debit', '100.00'], ['1010', 'credit', '100.00']]);
        $this->cashJournal('2026-09-11', [['1010', 'credit', '12.34'], ['2100', 'debit', '12.34']]);
        $data = $this->movement()->assertOk()->json('data');
        $csv = $this->actingAs($this->reader, 'sanctum')->get('/api/finance/reports/cash-movement?from=2026-09-01&to=2026-09-30&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($data['net_cash_movement'], $csv);
        $this->assertStringContainsString('TRANSFERS', $csv);
        $this->assertStringContainsString('NOT AVAILABLE', $csv);
        $this->assertStringContainsString('HISTORICAL_DATA_INCOMPLETE', $csv);
    }
}
