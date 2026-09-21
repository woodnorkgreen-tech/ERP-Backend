<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Models\VatTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Profit and Loss endpoint: revenue minus Cost of Sales minus overheads,
 * read off the same ledger Stages 1 and 2 of the general ledger plan already
 * post to. Nothing here re-tests Stage 1/2's own accounting — that is
 * ReceivablesPostingTest and WorkInProgressReleaseTest's job. These assert
 * the REPORT built on top of that posting: correct section totals, that an
 * account with no `account_type` degrades gracefully instead of corrupting
 * the total, and that an empty period is zero rather than an error.
 */
class ProfitAndLossReportTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        Permission::findOrCreate(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS, 'web');

        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo([
            Permissions::FINANCE_REPORTS_VIEW,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        ]);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function enquiry(float $agreedPrice): ProjectEnquiry
    {
        $client = Client::firstWhere('email', 'pnl@test.local')
            ?? Client::factory()->create(['email' => 'pnl@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Profit and loss report test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-PNL-' . uniqid(),
            'created_by' => $this->accountant->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved', 'approved_by' => $this->accountant->id,
            'approval_date' => '2026-09-01', 'quote_amount' => $agreedPrice,
            'quote_data' => json_encode(['grandTotal' => $agreedPrice]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $enquiry;
    }

    /** One balanced journal entry, posted directly the way a producer would. */
    private function postJournal(string $debitCode, string $creditCode, string $amount, ?int $enquiryId = null): JournalEntry
    {
        $entry = JournalEntry::create([
            'entry_no' => 'JE-PNL-' . uniqid(),
            'posting_date' => '2026-09-02',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'source_type' => 'Test', 'source_id' => $enquiryId ?? 0,
            'description' => 'Profit and loss test posting',
            'total_debit' => $amount, 'total_credit' => $amount,
            'status' => 'posted', 'posted_at' => now(),
        ]);

        foreach ([[$debitCode, 'debit'], [$creditCode, 'credit']] as [$code, $type]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', $code)->value('id'),
                'entry_type' => $type, 'amount' => $amount, 'base_amount' => $amount,
                'currency' => 'KES', 'fx_rate' => 1,
                // Only the Work in Progress leg carries the job, exactly as a
                // real stores issue does — the offsetting side is not job-specific.
                'project_enquiry_id' => $type === 'debit' ? $enquiryId : null,
            ]);
        }

        return $entry;
    }

    private function issueInvoice(ProjectEnquiry $enquiry, float $net): ProjectInvoice
    {
        $vat = VatTreatment::query()->effectiveOn('2026-09-08')->where('rate_percent', '>', 0)->firstOrFail();

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [['description' => 'Stand build', 'quantity' => 1, 'unit_price' => $net, 'vat_treatment_id' => $vat->id]],
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        return $invoice->fresh();
    }

    private function report(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/finance/reports/profit-and-loss?from={$from}&to={$to}")
            ->assertOk()
            ->json('data');
    }

    public function test_the_report_is_not_readable_without_the_reports_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/reports/profit-and-loss?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_an_empty_period_returns_zeroes_not_an_error(): void
    {
        $data = $this->report('2020-01-01', '2020-01-31');

        $this->assertSame('0.00', $data['totals']['revenue']);
        $this->assertSame('0.00', $data['totals']['direct_cost']);
        $this->assertSame('0.00', $data['totals']['gross_profit']);
        $this->assertSame('0.00', $data['totals']['net_profit']);
        $this->assertSame([], $data['sections']['revenue']);
        $this->assertFalse($data['coverage']['is_statutory_trial_balance']);
    }

    public function test_revenue_cost_of_sales_and_gross_profit_are_computed_for_a_period(): void
    {
        $enquiry = $this->enquiry(1160000);
        // 700,000 charged to the job's Direct Materials Work in Progress
        // before billing.
        $this->postJournal('1211', '1200', '700000.00', $enquiry->id);

        $this->issueInvoice($enquiry, 1000000);

        $data = $this->report();

        $this->assertSame('1000000.00', $data['totals']['revenue']);
        // Fully billed (the invoice's net covers the whole agreed price), so
        // the whole Work in Progress balance releases into Cost of Sales.
        $this->assertSame('700000.00', $data['totals']['direct_cost']);
        $this->assertSame('300000.00', $data['totals']['gross_profit']);
    }

    public function test_overhead_and_opex_reduce_net_profit_below_gross_profit(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->postJournal('1211', '1200', '700000.00', $enquiry->id);
        $this->issueInvoice($enquiry, 1000000);

        // Workshop electricity (overhead) and salaries (opex), neither tied
        // to a job.
        $this->postJournal('6100', '2150', '50000.00');
        $this->postJournal('7550', '2150', '80000.00');

        $data = $this->report();

        $this->assertSame('300000.00', $data['totals']['gross_profit']);
        $this->assertSame('50000.00', $data['totals']['overhead']);
        $this->assertSame('80000.00', $data['totals']['opex']);
        $this->assertSame('170000.00', $data['totals']['net_profit']);
    }

    public function test_an_account_with_no_account_type_is_unclassified_but_still_counted_in_net_profit(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->postJournal('1211', '1200', '700000.00', $enquiry->id);
        $this->issueInvoice($enquiry, 1000000);

        // An expense account with no account_type — exactly the gap found on
        // WNG's mnemonic-coded chart rows during this feature's research.
        ChartOfAccount::create([
            'name' => 'Unmapped Expense', 'code' => 'MYST-001',
            'category' => 'expense', 'account_type' => null,
            'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
        ]);
        $this->postJournal('MYST-001', '2150', '10000.00');

        $data = $this->report();

        $this->assertCount(1, $data['sections']['unclassified']);
        $this->assertSame('MYST-001', $data['sections']['unclassified'][0]['code']);
        $this->assertSame('10000.00', $data['totals']['unclassified_expense']);

        // The whole point: net_profit still reconciles to revenue minus
        // expense, degraded but never wrong.
        $expectedNet = bcsub($data['totals']['revenue'], bcadd('700000.00', '10000.00', 2), 2);
        $this->assertSame($expectedNet, $data['totals']['net_profit']);
    }

    public function test_reversed_original_entries_remain_counted_consistent_with_the_trial_balance(): void
    {
        // Same policy JournalEntryController::trialBalance() already applies:
        // a reversed original stays in the totals alongside its compensating
        // entry, so removing it would rewrite a historical month. If reversed
        // entries were wrongly excluded, only the compensating leg below
        // would count, and opex would show -20,000.00 instead of 0.00.
        $this->postJournal('7550', '2150', '20000.00');
        JournalEntry::where('total_debit', '20000.00')->latest('id')->first()
            ->update(['status' => 'reversed']);
        $this->postJournal('2150', '7550', '20000.00');

        $data = $this->report();

        $this->assertSame('0.00', $data['totals']['opex']);
    }
}
