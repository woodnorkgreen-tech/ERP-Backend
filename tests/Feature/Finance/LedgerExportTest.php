<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\LedgerExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Collapsing the ledger to something a bookkeeper can key.
 *
 * Internally each cost line posts its own journal, which is right for
 * traceability and wrong as a hand-off: a delivery of fourteen materials
 * becomes fourteen journals for one delivery note. This pins that the export
 * batches by source document and that the batching is a pure sum — the collapse
 * must never change a total, or the external books and this subledger diverge
 * on the first import.
 */
class LedgerExportTest extends TestCase
{
    use RefreshDatabase;

    private LedgerExportService $exporter;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        $this->exporter = $this->app->make(LedgerExportService::class);

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::where('code', $code)->value('id');
    }

    /**
     * One cost line and the journal it posted, as the producers leave them.
     */
    private function postedCost(string $grn, string $amount, string $description): void
    {
        $line = CostLine::create([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'job_number' => 'WNG-01-2026-004',
            'amount' => $amount,
            'tax_amount' => '0.00',
            'net_amount' => $amount,
            'base_net_amount' => $amount,
            'fx_rate' => '1',
            'incurred_at' => '2026-03-10 09:00:00',
            'description' => $description,
            'details' => ['grn_number' => $grn],
            'posted_at' => now(),
        ]);

        $entry = JournalEntry::create([
            'entry_no' => 'JE-CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT),
            'posting_date' => '2026-03-10',
            'cost_line_id' => $line->id,
            'source_type' => CostLine::class,
            'source_id' => $line->id,
            'source_ref' => $line->ref,
            'description' => $description,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        foreach ([['1211', 'debit'], ['2150', 'credit']] as [$code, $side]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $this->accountId($code),
                'entry_type' => $side,
                'amount' => $amount,
                'base_amount' => $amount,
                'currency' => 'KES',
                'fx_rate' => 1,
                'description' => $description,
                'project_enquiry_id' => null,
            ]);
        }

        $line->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    public function test_one_delivery_note_becomes_one_journal_however_many_materials_it_carried(): void
    {
        $this->postedCost('GRN-0042', '4000.00', 'Plywood 18mm');
        $this->postedCost('GRN-0042', '2500.00', 'Timber batten');
        $this->postedCost('GRN-0043', '1000.00', 'Paint');

        $export = $this->exporter->documentJournals('2026-03-01', '2026-03-31');

        $this->assertSame(2, $export['totals']['document_count']);
        $this->assertSame(3, $export['totals']['source_entry_count']);

        $grn42 = collect($export['documents'])->firstWhere('journal_no', 'GRN-0042');

        // Two accounts, not four legs: the batching sums within an account.
        $this->assertCount(2, $grn42['rows']);
        $this->assertSame('6500.00', $grn42['total_debit']);
        $this->assertSame('6500.00', $grn42['total_credit']);
    }

    public function test_the_collapse_never_changes_a_total(): void
    {
        $this->postedCost('GRN-0042', '4000.00', 'Plywood 18mm');
        $this->postedCost('GRN-0042', '2500.00', 'Timber batten');
        $this->postedCost('GRN-0043', '1000.00', 'Paint');

        $export = $this->exporter->documentJournals('2026-03-01', '2026-03-31');

        $this->assertSame('7500.00', $export['totals']['total_debit']);
        $this->assertSame('7500.00', $export['totals']['total_credit']);
        $this->assertTrue($export['totals']['is_balanced']);
    }

    public function test_a_multi_line_document_is_described_by_what_it_is_not_by_its_first_line(): void
    {
        $this->postedCost('GRN-0042', '4000.00', 'Plywood 18mm');
        $this->postedCost('GRN-0042', '2500.00', 'Timber batten');

        $document = collect($this->exporter->documentJournals('2026-03-01', '2026-03-31')['documents'])
            ->firstWhere('journal_no', 'GRN-0042');

        // "Plywood 18mm" as the label for a two-material delivery is misleading.
        $this->assertSame('2 cost lines, job WNG-01-2026-004', $document['description']);
    }

    public function test_a_single_line_document_keeps_its_own_description(): void
    {
        $this->postedCost('GRN-0043', '1000.00', 'Paint');

        $document = collect($this->exporter->documentJournals('2026-03-01', '2026-03-31')['documents'])
            ->firstWhere('journal_no', 'GRN-0043');

        $this->assertSame('Paint', $document['description']);
    }

    public function test_the_export_states_that_it_is_the_cost_side_only(): void
    {
        $export = $this->exporter->documentJournals('2026-03-01', '2026-03-31');

        // The one way this file causes harm is by being imported as a complete
        // set of journals, so the warning travels in the payload.
        $this->assertStringContainsString('subledger hand-off', $export['coverage']['warning']);
        $this->assertContains('Revenue, client invoices and receipts', $export['coverage']['excludes']);
    }

    public function test_the_csv_carries_one_row_per_account_per_document(): void
    {
        $this->postedCost('GRN-0042', '4000.00', 'Plywood 18mm');
        $this->postedCost('GRN-0042', '2500.00', 'Timber batten');

        $csv = $this->actingAs($this->reader, 'sanctum')
            ->get('/api/finance/journals/export?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->streamedContent();

        $rows = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $rows, 'One header plus two account rows for the one delivery note.');
        $this->assertStringContainsString('GRN-0042', $rows[1]);
        $this->assertStringContainsString('6500.00', $csv);
    }

    public function test_the_export_is_closed_to_anyone_without_the_reports_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum')
            ->getJson('/api/finance/journals/export?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();
    }
}
