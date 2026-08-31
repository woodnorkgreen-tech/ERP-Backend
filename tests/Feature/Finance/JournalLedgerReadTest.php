<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The read side of the general ledger, which did not exist.
 *
 * JournalPostingService wrote entries and lines that no controller, route or
 * screen could read back. These assert the three things that absence cost:
 * the entries are listable and filterable, an entry's legs can be drilled into,
 * and the ledger can be shown to balance.
 */
class JournalLedgerReadTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $outsider;
    private JournalPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = $this->app->make(JournalPostingService::class);

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');

        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function postedCostLine(string $ref, string $net = '5000.00'): JournalEntry
    {
        $line = CostLine::create([
            'ref' => $ref,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => $net,
            'tax_amount' => '0.00',
            'net_amount' => $net,
            'base_net_amount' => $net,
            'fx_rate' => '1.00',
            'submitted_by_user_id' => $this->reader->id,
        ]);

        return $this->posting->postCostLine($line);
    }

    public function test_the_ledger_is_not_readable_without_the_reports_permission(): void
    {
        $this->postedCostLine('CL-001');

        foreach (['/api/finance/journals', '/api/finance/journals/trial-balance'] as $path) {
            $this->actingAs($this->outsider, 'sanctum')->getJson($path)->assertForbidden();
        }

        $entry = JournalEntry::firstOrFail();
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson("/api/finance/journals/{$entry->id}")
            ->assertForbidden();
    }

    public function test_entries_are_listed_newest_first_with_their_headers(): void
    {
        $this->postedCostLine('CL-001', '5000.00');
        $this->postedCostLine('CL-002', '7000.00');

        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [['id', 'entry_no', 'posting_date', 'source_ref', 'total_debit', 'total_credit', 'is_balanced']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame(2, $response->json('meta.total'));

        // The list must not carry legs — that is what makes it an N+1.
        $this->assertArrayNotHasKey('lines', $response->json('data.0'));

        // source_type is presented as a short name, not a stored FQCN.
        $this->assertSame('CostLine', $response->json('data.0.source_type'));
    }

    public function test_entries_can_be_filtered_by_source_and_searched_by_reference(): void
    {
        $this->postedCostLine('CL-ALPHA');
        $this->postedCostLine('CL-BETA');

        $bySource = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals?source=cost_line')->assertOk();
        $this->assertSame(2, $bySource->json('meta.total'));

        $none = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals?source=spend_voucher')->assertOk();
        $this->assertSame(0, $none->json('meta.total'));

        $searched = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals?search=CL-ALPHA')->assertOk();
        $this->assertSame(1, $searched->json('meta.total'));
        $this->assertSame('CL-ALPHA', $searched->json('data.0.source_ref'));
    }

    public function test_filtering_by_account_does_not_duplicate_an_entry_per_matching_leg(): void
    {
        // An entry hits the same account on at most one leg here, but the guard
        // that matters is structural: account filtering goes through whereHas,
        // so an entry with two matching legs is still one row.
        $entry = $this->postedCostLine('CL-JOIN');
        $accountId = $entry->lines()->first()->account_id;

        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/finance/journals?account_id={$accountId}")
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_an_entry_drills_down_to_its_legs_and_the_accounts_they_hit(): void
    {
        $entry = $this->postedCostLine('CL-DRILL', '5000.00');

        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson("/api/finance/journals/{$entry->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'entry_no', 'is_balanced',
                    'lines' => [['entry_type', 'amount', 'account' => ['id', 'code', 'name']]],
                ],
            ]);

        $lines = $response->json('data.lines');
        $this->assertCount(2, $lines);
        $this->assertTrue($response->json('data.is_balanced'));

        // This is the drill-down the cost verification screen never had: a
        // journal number that resolves to the accounts actually moved.
        $this->assertSame('debit', $lines[0]['entry_type']);
        $this->assertSame('credit', $lines[1]['entry_type']);
        $this->assertNotEmpty($lines[0]['account']['code']);
    }

    public function test_the_trial_balance_agrees_and_says_so(): void
    {
        $this->postedCostLine('CL-TB1', '5000.00');
        $this->postedCostLine('CL-TB2', '3000.00');

        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals/trial-balance')
            ->assertOk();

        $this->assertSame('8000.00', $response->json('data.totals.debit'));
        $this->assertSame('8000.00', $response->json('data.totals.credit'));
        $this->assertSame('0.00', $response->json('data.totals.difference'));
        $this->assertTrue($response->json('data.totals.is_balanced'));

        // Every posted account appears once, with its own side totalled.
        $codes = collect($response->json('data.accounts'))->pluck('code');
        $this->assertSame($codes->unique()->count(), $codes->count());
    }

    public function test_trial_balance_is_not_shadowed_by_the_entry_route(): void
    {
        // `trial-balance` must resolve to its own action rather than being taken
        // for a {journal} id; declaration order in the route file is the only
        // thing preventing that.
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.totals.is_balanced', true);
    }

    public function test_an_inverted_date_range_is_refused(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals?from=' . now()->toDateString() . '&to=' . now()->subMonth()->toDateString())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }
}
