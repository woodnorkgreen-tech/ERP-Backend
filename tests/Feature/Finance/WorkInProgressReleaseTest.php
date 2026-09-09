<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
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
 * Stage 2: a job's costs meet the revenue they earned.
 *
 * Every cost charged to a job debits Work in Progress — an asset meaning "spent
 * on a job that is not finished". Nothing ever moved it out, so the chart's nine
 * Cost of Sales accounts had never been touched and a completed job's costs sat
 * on the balance sheet indefinitely. No job could show a profit: revenue landed
 * when billed, cost stayed an asset for ever.
 *
 * These tests assert the matching property itself — that cost and revenue land
 * in the same place at the same time, that a part-billed job releases part of
 * its cost, and that repeated billing converges rather than compounding.
 */
class WorkInProgressReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_REVERSE,
            Permissions::FINANCE_REPORTS_VIEW,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->givePermissionTo([
            Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
            Permissions::FINANCE_RECEIVABLES_REVERSE,
            Permissions::FINANCE_REPORTS_VIEW,
        ]);
    }

    private function enquiry(float $agreedPrice): ProjectEnquiry
    {
        $client = Client::firstWhere('email', 'wip@test.local')
            ?? Client::factory()->create(['email' => 'wip@test.local']);

        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01',
            'expected_delivery_date' => '2026-09-30',
            'client_id' => $client->id,
            'title' => 'Exhibition stand',
            'description' => 'Work in progress release test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-WIP-' . uniqid(),
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

    /** Put real cost into a job's Work in Progress, the way a stores issue does. */
    private function chargeCostToJob(ProjectEnquiry $enquiry, string $wipCode, float $amount): void
    {
        $entry = JournalEntry::create([
            'entry_no' => 'JE-COST-' . uniqid(),
            'posting_date' => '2026-09-02',
            'accounting_period_id' => \App\Modules\Finance\CostCollector\Models\AccountingPeriod::forDate(now())->id,
            'source_type' => 'Test', 'source_id' => $enquiry->id,
            'description' => 'Cost charged to job',
            'total_debit' => $amount, 'total_credit' => $amount,
            'status' => 'posted', 'posted_at' => now(),
        ]);

        foreach ([[$wipCode, 'debit'], ['1200', 'credit']] as [$code, $type]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', $code)->value('id'),
                'entry_type' => $type, 'amount' => $amount, 'base_amount' => $amount,
                'currency' => 'KES', 'fx_rate' => 1,
                // Only the Work in Progress leg carries the job, exactly as a
                // real stores issue does — the inventory side is not job-specific.
                'project_enquiry_id' => $type === 'debit' ? $enquiry->id : null,
            ]);
        }
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

    private function balanceOn(string $code, int $enquiryId): float
    {
        $accountId = ChartOfAccount::where('code', $code)->value('id');
        $rows = JournalLine::where('account_id', $accountId)->where('project_enquiry_id', $enquiryId)->get();

        return (float) $rows->where('entry_type', 'debit')->sum(fn ($l) => (float) $l->amount)
            - (float) $rows->where('entry_type', 'credit')->sum(fn ($l) => (float) $l->amount);
    }

    public function test_billing_a_job_in_full_empties_its_work_in_progress(): void
    {
        // Agreed price 1,160,000 so that a 1,000,000 net invoice plus tax bills
        // the job exactly in full.
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 300000);
        $this->chargeCostToJob($enquiry, '1214', 40000);

        $this->assertSame(300000.0, $this->balanceOn('1211', $enquiry->id));

        $this->issueInvoice($enquiry, 1000000);

        // Everything spent on the job has become the cost of the revenue.
        $this->assertSame(0.0, $this->balanceOn('1211', $enquiry->id));
        $this->assertSame(0.0, $this->balanceOn('1214', $enquiry->id));
        $this->assertSame(300000.0, $this->balanceOn('5100', $enquiry->id));
        $this->assertSame(40000.0, $this->balanceOn('5400', $enquiry->id));
    }

    public function test_each_cost_family_releases_into_its_own_cost_of_sales_account(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 100000);   // materials
        $this->chargeCostToJob($enquiry, '1213', 50000);    // subcontractors
        $this->chargeCostToJob($enquiry, '1217', 25000);    // facilitation

        $this->issueInvoice($enquiry, 1000000);

        // A finished job must still say what it spent on materials as against
        // subcontractors, so the families cannot be collapsed on release.
        $this->assertSame(100000.0, $this->balanceOn('5100', $enquiry->id));
        $this->assertSame(50000.0, $this->balanceOn('5300', $enquiry->id));
        $this->assertSame(25000.0, $this->balanceOn('5700', $enquiry->id));
    }

    public function test_a_part_billed_job_releases_only_that_part_of_its_cost(): void
    {
        // Agreed 1,000,000; bill 250,000 net which, with tax, is well under half.
        $enquiry = $this->enquiry(1000000);
        $this->chargeCostToJob($enquiry, '1211', 400000);

        $invoice = $this->issueInvoice($enquiry, 250000);
        $fraction = (float) $invoice->total_amount / 1000000;

        $expected = round(400000 * $fraction, 2);

        $this->assertEqualsWithDelta($expected, $this->balanceOn('5100', $enquiry->id), 0.01);
        $this->assertEqualsWithDelta(400000 - $expected, $this->balanceOn('1211', $enquiry->id), 0.01);
    }

    public function test_billing_the_rest_releases_the_rest_and_nothing_more(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 400000);

        $this->issueInvoice($enquiry, 500000);
        $afterFirst = $this->balanceOn('5100', $enquiry->id);
        $this->assertGreaterThan(0, $afterFirst);
        $this->assertLessThan(400000, $afterFirst);

        $this->issueInvoice($enquiry, 500000);

        // Fully billed means fully released — exactly once, never twice.
        $this->assertEqualsWithDelta(400000.0, $this->balanceOn('5100', $enquiry->id), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOn('1211', $enquiry->id), 0.01);
    }

    public function test_a_job_with_no_costs_releases_nothing_rather_than_failing(): void
    {
        $enquiry = $this->enquiry(1160000);

        $this->issueInvoice($enquiry, 1000000);

        $this->assertSame(0, JournalEntry::where('entry_no', 'like', 'JE-WIP-%')->count());
    }

    public function test_a_job_billed_beyond_its_agreed_price_still_releases_only_what_it_spent(): void
    {
        /*
         * The over-billing cap on invoice creation should make this impossible,
         * so the invoice is inserted directly — this guards against historic
         * data that predates the cap, and against any future route that bills
         * without going through it. Releasing more cost than a job incurred
         * would drive Work in Progress negative and overstate cost of sales.
         */
        $enquiry = $this->enquiry(100000);
        $this->chargeCostToJob($enquiry, '1211', 80000);

        $invoice = ProjectInvoice::create([
            'invoice_number' => 'INV-OVER-1',
            'project_enquiry_id' => $enquiry->id,
            'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
            'subtotal' => 150000, 'tax_amount' => 0, 'total_amount' => 150000,
            'status' => 'issued', 'created_by' => $this->accountant->id,
            // The fraction counts only invoices that reached the ledger.
            'journal_entry_id' => JournalEntry::first()->id,
        ]);

        $release = app(\App\Modules\Finance\Services\WorkInProgressReleaseService::class);

        $this->assertSame('1.000000', $release->billedFraction($enquiry->id));

        $release->releaseForInvoice($invoice, $this->accountant->id);

        $this->assertEqualsWithDelta(80000.0, $this->balanceOn('5100', $enquiry->id), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOn('1211', $enquiry->id), 0.01);
    }

    public function test_billing_the_exact_agreed_price_releases_every_shilling(): void
    {
        /*
         * The full-release case with exact arithmetic. The agreed price is
         * treated as tax-inclusive by the over-billing cap, so this bills a
         * zero-rated line for precisely the agreed figure — no rounding, and no
         * tax to reason about.
         */
        $enquiry = $this->enquiry(100000);
        $this->chargeCostToJob($enquiry, '1211', 80000);

        $created = $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices", [
                'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08',
                'lines' => [['description' => 'Zero-rated supply', 'quantity' => 1, 'unit_price' => 100000]],
            ])->assertCreated();

        $invoice = ProjectInvoice::findOrFail($created->json('data.id'));

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/issue")
            ->assertOk();

        $this->assertSame('1.000000', app(\App\Modules\Finance\Services\WorkInProgressReleaseService::class)
            ->billedFraction($enquiry->id));
        $this->assertSame(80000.0, $this->balanceOn('5100', $enquiry->id));
        $this->assertSame(0.0, $this->balanceOn('1211', $enquiry->id));
    }

    public function test_the_release_entry_balances_and_names_its_invoice(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 300000);

        $invoice = $this->issueInvoice($enquiry, 1000000);

        $entry = JournalEntry::with('lines')->where('entry_no', 'JE-WIP-' . str_pad((string) $invoice->id, 7, '0', STR_PAD_LEFT))->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($invoice->invoice_number, $entry->source_ref);
        $this->assertSame(
            $invoice->invoice_date->toDateString(),
            $entry->posting_date->toDateString(),
            'Cost must land in the same month as the revenue it earned.',
        );
    }

    public function test_costs_on_one_job_are_never_released_by_another_jobs_invoice(): void
    {
        $billed = $this->enquiry(1160000);
        $untouched = $this->enquiry(1160000);

        $this->chargeCostToJob($billed, '1211', 100000);
        $this->chargeCostToJob($untouched, '1211', 250000);

        $this->issueInvoice($billed, 1000000);

        $this->assertSame(0.0, $this->balanceOn('1211', $billed->id));
        $this->assertSame(250000.0, $this->balanceOn('1211', $untouched->id));
        $this->assertSame(0.0, $this->balanceOn('5100', $untouched->id));
    }

    private function voidInvoice(ProjectEnquiry $enquiry, ProjectInvoice $invoice, string $reason = 'Billed in error'): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/invoices/{$invoice->id}/void", ['reason' => $reason])
            ->assertOk();
    }

    public function test_voiding_an_invoice_returns_its_costs_to_work_in_progress(): void
    {
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 300000);

        $invoice = $this->issueInvoice($enquiry, 1000000);
        $this->assertSame(0.0, $this->balanceOn('1211', $enquiry->id));
        $this->assertSame(300000.0, $this->balanceOn('5100', $enquiry->id));

        $this->voidInvoice($enquiry, $invoice);

        // The job is unbilled again, so its spending is an asset again. Leaving
        // the cost in Cost of Sales against revenue that has been reversed
        // would report a loss equal to the whole job.
        $this->assertSame(300000.0, $this->balanceOn('1211', $enquiry->id));
        $this->assertSame(0.0, $this->balanceOn('5100', $enquiry->id));
    }

    public function test_a_job_re_billed_after_a_void_releases_its_full_cost(): void
    {
        /*
         * The regression this exists for.
         *
         * `movementOn()` used to read gross debits on the Work in Progress
         * account as "everything this job cost". Reversing a release writes a
         * DEBIT back to that account, so the reversal was counted as new cost:
         * the base grew from 300,000 to 450,000, and the replacement invoice
         * released 75,000 where it owed 150,000. Half the cost of the job
         * silently stayed on the balance sheet.
         *
         * It only shows up at partial billing after a reversal, which is why
         * billing once and never correcting anything never revealed it.
         */
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 300000);

        // 500,000 net plus tax is 580,000 of a 1,160,000 agreed price — half.
        $first = $this->issueInvoice($enquiry, 500000);
        $this->assertSame(150000.0, $this->balanceOn('5100', $enquiry->id));

        $this->voidInvoice($enquiry, $first);
        $this->assertSame(0.0, $this->balanceOn('5100', $enquiry->id));

        $this->issueInvoice($enquiry, 500000);

        // Same job, same cost, same share of the agreed price — so the same
        // release. Anything less leaves cost stranded in Work in Progress.
        $this->assertSame(150000.0, $this->balanceOn('5100', $enquiry->id));
        $this->assertSame(150000.0, $this->balanceOn('1211', $enquiry->id));
    }

    public function test_a_reversed_cost_is_not_mistaken_for_a_release(): void
    {
        /*
         * The mirror of the case above. Reversing a COST writes a credit to Work
         * in Progress, which the old reading counted as cost already released —
         * so a half-billed job released 100,000 where it owed 200,000.
         */
        $enquiry = $this->enquiry(1160000);
        $this->chargeCostToJob($enquiry, '1211', 400000);
        $this->chargeCostToJob($enquiry, '1211', 200000);

        // Reverse one of the two cost entries, as a ledger correction would.
        $costEntry = JournalEntry::where('source_type', 'Test')
            ->where('source_id', $enquiry->id)->where('total_debit', 200000)->firstOrFail();
        app(\App\Modules\Finance\Services\JournalPostingService::class)
            ->reverseEntry($costEntry, $this->accountant->id, 'Charged to the wrong job');

        $this->assertSame(400000.0, $this->balanceOn('1211', $enquiry->id));

        // Half the agreed price, so half of the 400,000 that really was spent.
        $this->issueInvoice($enquiry, 500000);

        $this->assertSame(200000.0, $this->balanceOn('5100', $enquiry->id));
        $this->assertSame(200000.0, $this->balanceOn('1211', $enquiry->id));
    }
}
