<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CashReportingFixtures;
use Tests\TestCase;

class BankCashReportTest extends TestCase
{
    use RefreshDatabase, CashReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCashReporting();
    }

    private function position(string $date = '2026-09-30')
    {
        return $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/reports/bank-cash-position?as_at='.$date);
    }

    public function test_permission_and_date_validation(): void
    {
        $this->actingAs($this->outsider, 'sanctum')->getJson('/api/finance/reports/bank-cash-position')->assertForbidden();
        $this->position('invalid')->assertUnprocessable();
        $this->position('2026-09-31')->assertUnprocessable();
    }

    public function test_configured_accounts_only_and_unconfigured_channels_have_no_fabricated_balances(): void
    {
        PaymentSource::query()->whereIn('code', ['MPESA', 'CARD'])->update(['gl_account_id' => null]);
        $uncounted = ChartOfAccount::create(['code' => 'TEST-CASH', 'name' => 'Cash bank M-Pesa name only', 'category' => 'asset', 'account_type' => 'balance_sheet', 'normal_balance' => 'debit', 'is_active' => true, 'is_postable' => true]);
        $this->cashJournal('2026-09-15', [['TEST-CASH', 'debit', '99.00'], ['2100', 'credit', '99.00']]);
        $data = $this->position()->assertOk()->json('data');
        $ids = array_column($data['accounts'], 'account_id');
        $this->assertContains($this->account('1010')->id, $ids);
        $this->assertContains($this->account('1030')->id, $ids);
        $this->assertNotContains($this->account('1040')->id, $ids);
        $this->assertNotContains($uncounted->id, $ids);
        $channels = collect($data['configuration'])->keyBy('source_code');
        $this->assertSame('NOT_CONFIGURED', $channels['MPESA']['status']);
        $this->assertSame('NOT_CONFIGURED', $channels['CARD']['status']);
        $this->assertNull($channels['MPESA']['account_id']);
        $this->assertSame('0.00', $data['total_cash_and_bank']);
    }

    public function test_debits_credits_cutoff_drafts_duplicates_and_petty_cash_scope(): void
    {
        $this->cashJournal('2026-09-10', [['1010', 'debit', '100.00'], ['2100', 'credit', '100.00']]);
        $this->cashJournal('2026-09-20', [['1010', 'credit', '30.00'], ['2100', 'debit', '30.00']]);
        $this->cashJournal('2026-09-21', [['1020', 'debit', '50.00'], ['2100', 'credit', '50.00']]);
        $this->cashJournal('2026-09-21', [['1030', 'debit', '10.00'], ['2100', 'credit', '10.00']]);
        $this->cashJournal('2026-09-25', [['1010', 'debit', '999.00'], ['2100', 'credit', '999.00']], 'draft');
        $data = $this->position()->assertOk()->json('data');
        $bank = collect($data['accounts'])->firstWhere('account_code', '1010');
        $this->assertSame('100.00', $bank['debits_to_date']);
        $this->assertSame('30.00', $bank['credits_to_date']);
        $this->assertSame('70.00', $bank['closing_balance']);
        $this->assertSame('130.00', $data['total_cash_and_bank']);
        $this->assertCount(count(array_unique(array_column($data['accounts'], 'account_id'))), $data['accounts']);
        $this->assertSame('100.00', $this->position('2026-09-15')->assertOk()->json('data.total_cash_and_bank'));
    }

    public function test_real_reversal_preserves_original_at_earlier_cutoff(): void
    {
        $original = $this->cashJournal('2026-09-10', [['1010', 'debit', '100.00'], ['2100', 'credit', '100.00']]);
        app(JournalPostingService::class)->reverseEntry($original, $this->reader->id, 'Correction');
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('100.00', $this->position('2026-09-15')->assertOk()->json('data.total_cash_and_bank'));
        $this->assertSame('0.00', $this->position()->assertOk()->json('data.total_cash_and_bank'));
    }

    public function test_asset_sign_is_debit_minus_credit_even_if_normal_balance_is_credit(): void
    {
        $this->account('1010')->update(['normal_balance' => 'credit']);
        $this->cashJournal('2026-09-10', [['1010', 'credit', '25.00'], ['2100', 'debit', '25.00']]);
        $data = $this->position()->assertOk()->json('data');
        $this->assertSame('-25.00', $data['total_cash_and_bank']);
        $this->assertSame('HISTORICAL_DATA_INCOMPLETE', $data['readiness']);
        $this->assertFalse($data['historical_data_complete']);
        $this->assertSame('NOT_CONFIGURED', $data['opening_balance_status']);
        $this->assertNull($data['accounts'][0]['opening_or_brought_forward_position']);
    }

    public function test_function_mapping_and_invalid_source_accounts_are_respected(): void
    {
        $mapped = ChartOfAccount::create(['code' => 'LOCAL-BANK', 'name' => 'Mapped asset', 'category' => 'asset', 'account_type' => 'balance_sheet', 'normal_balance' => 'debit', 'is_active' => true, 'is_postable' => true]);
        config(['finance_accounts.map.1010' => 'LOCAL-BANK']);
        PaymentSource::query()->where('code', 'BANK-MAIN')->update(['gl_account_id' => $this->account('2100')->id]);
        $data = $this->position()->assertOk()->json('data');
        $this->assertContains($mapped->id, array_column($data['accounts'], 'account_id'));
        $this->assertNotContains($this->account('2100')->id, array_column($data['accounts'], 'account_id'));
        $this->assertSame('INVALID_ACCOUNT', collect($data['configuration'])->firstWhere('source_code', 'BANK-MAIN')['status']);
    }

    public function test_csv_uses_same_position_and_discloses_readiness(): void
    {
        $this->cashJournal('2026-09-15', [['1010', 'debit', '321.45'], ['2100', 'credit', '321.45']]);
        $response = $this->actingAs($this->reader, 'sanctum')->get('/api/finance/reports/bank-cash-position?as_at=2026-09-30&format=csv')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($this->position()->json('data.total_cash_and_bank'), $csv);
        $this->assertStringContainsString('HISTORICAL_DATA_INCOMPLETE', $csv);
        $this->assertStringContainsString('NOT AVAILABLE', $csv);
    }

    public function test_inactive_and_nonpostable_channel_accounts_are_excluded(): void
    {
        PaymentSource::query()->where('code', 'BANK-ALT')->update(['is_active' => false]);
        $this->account('1040')->update(['is_postable' => false]);
        $data = $this->position()->assertOk()->json('data');
        $this->assertNotContains($this->account('1020')->id, array_column($data['accounts'], 'account_id'));
        $this->assertNotContains($this->account('1040')->id, array_column($data['accounts'], 'account_id'));
        $this->assertSame('INACTIVE', collect($data['configuration'])->firstWhere('source_code', 'BANK-ALT')['status']);
        $this->assertSame('INVALID_ACCOUNT', collect($data['configuration'])->firstWhere('source_code', 'MPESA')['status']);
    }

    public function test_large_decimal_amounts_preserve_cents_without_float_rounding(): void
    {
        // Stay within the existing DECIMAL(14,2) journal schema.
        $this->cashJournal('2026-09-10', [['1010', 'debit', '999999999999.91'], ['2100', 'credit', '999999999999.91']]);
        $this->cashJournal('2026-09-15', [['1010', 'credit', '0.01'], ['2100', 'debit', '0.01']]);
        $this->assertSame('999999999999.90', $this->position()->assertOk()->json('data.total_cash_and_bank'));
    }
}
