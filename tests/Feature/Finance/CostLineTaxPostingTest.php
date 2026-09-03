<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tax reaching the ledger.
 *
 * Verification has always priced recoverable VAT and withholding onto the cost
 * line; posting then wrote two legs of `net_amount` and discarded both. These
 * tests pin the shape of the entry rather than only its balance, because the
 * old one balanced perfectly and was still wrong — the credit to cash was short
 * by the VAT, and no tax account was touched at all.
 */
class CostLineTaxPostingTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $posting;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = $this->app->make(JournalPostingService::class);

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        $this->verifier = User::factory()->create(['is_active' => true]);
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::where('code', $code)->value('id');
    }

    /** A verified line as `CostVerificationService` would leave it. */
    private function line(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '11600.00',
            'tax_amount' => '0.00',
            'net_amount' => '11600.00',
            'base_net_amount' => '11600.00',
            'wht_amount' => '0.00',
            'fx_rate' => '1',
            'verified_by' => $this->verifier->id,
            'verified_at' => now(),
        ], $overrides));
    }

    /** @return array<string, array{0:string,1:string}> account code => [type, amount] */
    private function legsByAccount(JournalEntry $entry): array
    {
        $codes = ChartOfAccount::pluck('code', 'id');

        return $entry->lines->mapWithKeys(fn ($leg) => [
            $codes[$leg->account_id] => [$leg->entry_type, (string) $leg->amount],
        ])->all();
    }

    public function test_recoverable_vat_is_debited_to_the_input_vat_account(): void
    {
        $line = $this->line([
            'amount' => '11600.00',
            'tax_amount' => '1600.00',
            'net_amount' => '10000.00',
            'base_net_amount' => '10000.00',
            'vat_treatment_id' => VatTreatment::where('code', 'STD16-REC')->value('id'),
        ]);

        $entry = $this->posting->postCostLine($line);

        $this->assertNotNull($entry);
        $this->assertCount(3, $entry->lines);

        // The whole point: cash is credited the gross that actually left, not
        // the net that reached project cost.
        $legs = $this->legsByAccount($entry);
        $this->assertSame(['debit', '1600.00'], $legs['1330']);

        $this->assertEquals('11600.00', $entry->total_debit);
        $this->assertEquals('11600.00', $entry->total_credit);

        $credits = $entry->lines->where('entry_type', 'credit');
        $this->assertCount(1, $credits);
        $this->assertEquals('11600.00', $credits->first()->amount);
    }

    public function test_withholding_is_credited_to_the_wht_payable_account(): void
    {
        $line = $this->line([
            'amount' => '10000.00',
            'tax_amount' => '0.00',
            'net_amount' => '10000.00',
            'base_net_amount' => '10000.00',
            'wht_amount' => '500.00',
            'wht_category_id' => WhtCategory::where('code', 'PROF-RES')->value('id'),
        ]);

        $entry = $this->posting->postCostLine($line);

        $legs = $this->legsByAccount($entry);
        $this->assertSame(['credit', '500.00'], $legs['2120']);

        // The supplier is owed the fee less what was retained from them.
        $settlement = $entry->lines
            ->where('entry_type', 'credit')
            ->where('account_id', '!=', $this->accountId('2120'))
            ->first();
        $this->assertEquals('9500.00', $settlement->amount);

        $this->assertEquals('10000.00', $entry->total_debit);
        $this->assertEquals('10000.00', $entry->total_credit);
    }

    public function test_vat_and_withholding_post_on_the_same_entry(): void
    {
        $line = $this->line([
            'amount' => '11600.00',
            'tax_amount' => '1600.00',
            'net_amount' => '10000.00',
            'base_net_amount' => '10000.00',
            'wht_amount' => '500.00',
            'vat_treatment_id' => VatTreatment::where('code', 'STD16-REC')->value('id'),
            'wht_category_id' => WhtCategory::where('code', 'PROF-RES')->value('id'),
        ]);

        $entry = $this->posting->postCostLine($line);

        $this->assertCount(4, $entry->lines);

        $legs = $this->legsByAccount($entry);
        $this->assertSame(['debit', '1600.00'], $legs['1330']);
        $this->assertSame(['credit', '500.00'], $legs['2120']);

        // Withholding is computed on the net fee, never on the VAT, so the
        // supplier is settled at gross less the retention: 11,600 − 500.
        $settlement = $entry->lines->where('entry_type', 'credit')
            ->where('account_id', '!=', $this->accountId('2120'))->first();
        $this->assertEquals('11100.00', $settlement->amount);

        $debits = $entry->lines->where('entry_type', 'debit')->sum(fn ($l) => (float) $l->amount);
        $credits = $entry->lines->where('entry_type', 'credit')->sum(fn ($l) => (float) $l->amount);
        $this->assertSame($debits, $credits);
        $this->assertEquals('11600.00', $entry->total_debit);
    }

    public function test_a_tax_free_cost_still_posts_two_legs(): void
    {
        $entry = $this->posting->postCostLine($this->line());

        $this->assertCount(2, $entry->lines);
        $this->assertEquals('11600.00', $entry->total_debit);
    }

    public function test_reversal_mirrors_every_leg_including_the_tax_ones(): void
    {
        $line = $this->line([
            'amount' => '11600.00', 'tax_amount' => '1600.00',
            'net_amount' => '10000.00', 'base_net_amount' => '10000.00',
            'wht_amount' => '500.00',
            'vat_treatment_id' => VatTreatment::where('code', 'STD16-REC')->value('id'),
            'wht_category_id' => WhtCategory::where('code', 'PROF-RES')->value('id'),
        ]);

        $original = $this->posting->postCostLine($line);
        $line->refresh();

        $reversal = $this->posting->reverseCostLine($line, $this->verifier->id, 'Duplicate claim.');

        $this->assertCount(4, $reversal->lines);
        $this->assertEquals($original->total_debit, $reversal->total_credit);

        // Input VAT recoverable must be given back, not left as a claim against
        // KRA for a cost that no longer exists.
        $vat = $reversal->lines->firstWhere('account_id', $this->accountId('1330'));
        $this->assertSame('credit', $vat->entry_type);
        $this->assertEquals('1600.00', $vat->amount);

        $wht = $reversal->lines->firstWhere('account_id', $this->accountId('2120'));
        $this->assertSame('debit', $wht->entry_type);
        $this->assertEquals('500.00', $wht->amount);
    }

    public function test_base_amounts_restate_each_leg_at_the_line_fx_rate(): void
    {
        $line = $this->line([
            'currency' => 'USD', 'fx_rate' => '130.00000000',
            'amount' => '116.00', 'tax_amount' => '16.00',
            'net_amount' => '100.00', 'base_net_amount' => '13000.00',
        ]);

        $entry = $this->posting->postCostLine($line);

        $vat = $entry->lines->firstWhere('account_id', $this->accountId('1330'));
        $this->assertEquals('2080.00', $vat->base_amount);   // 16 × 130
    }

    public function test_withholding_larger_than_the_cost_is_refused(): void
    {
        $line = $this->line([
            'amount' => '1000.00', 'tax_amount' => '0.00',
            'net_amount' => '1000.00', 'base_net_amount' => '1000.00',
            'wht_amount' => '1500.00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->posting->postCostLine($line);
    }

    public function test_a_zero_value_line_posts_nothing(): void
    {
        $line = $this->line([
            'amount' => '0.00', 'tax_amount' => '0.00',
            'net_amount' => '0.00', 'base_net_amount' => '0.00',
        ]);

        $this->assertNull($this->posting->postCostLine($line));
        $this->assertNull($line->fresh()->posted_at);
    }
}
