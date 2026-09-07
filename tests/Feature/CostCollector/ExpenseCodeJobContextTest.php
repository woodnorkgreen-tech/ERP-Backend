<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the catalogue offers when there is a job, and when there is not.
 *
 * `job_context` was written as a mirrored pair — `!= not_allowed` with a job,
 * `= not_allowed` without one — and the second half is wrong. A rule of
 * `optional` or `conditional` says on its face that a job number is not
 * required, so those codes belong in the no-job list too. Excluding them left
 * an office requisition seven choices, four of which are not purchases, and
 * made two codes unreachable from the only screens that need them: a stores
 * replenishment could not be classified, and a non-project petty-cash spend
 * could not be coded to bank charges.
 *
 * The picker is the only thing standing between a requester and a code the
 * cost collector will later refuse to post, so what it offers is worth pinning.
 */
class ExpenseCodeJobContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceReferenceSeeder::class);
        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum');
    }

    /** @return array<int, string> */
    private function rulesOffered(bool $jobContext): array
    {
        $response = $this->getJson('/api/costs/expense-codes?limit=100&job_context='
            .($jobContext ? 'true' : 'false'))->assertOk();

        return collect($response->json('data'))->pluck('job_id_rule')->unique()->sort()->values()->all();
    }

    /** @return array<int, string> */
    private function codesOffered(bool $jobContext): array
    {
        $response = $this->getJson('/api/costs/expense-codes?limit=100&job_context='
            .($jobContext ? 'true' : 'false'))->assertOk();

        return collect($response->json('data'))->pluck('code')->all();
    }

    public function test_without_a_job_every_code_that_does_not_demand_one_is_offered(): void
    {
        $offered = $this->rulesOffered(false);

        sort($offered);
        $this->assertSame(
            [ExpenseCode::JOB_CONDITIONAL, ExpenseCode::JOB_NOT_ALLOWED, ExpenseCode::JOB_OPTIONAL],
            $offered,
            'A code whose own rule makes a job number optional is usable without one.',
        );
    }

    public function test_without_a_job_a_code_that_demands_one_is_never_offered(): void
    {
        $this->assertNotContains(ExpenseCode::JOB_REQUIRED, $this->rulesOffered(false));
    }

    public function test_with_a_job_only_the_codes_that_forbid_one_are_withheld(): void
    {
        $this->assertNotContains(ExpenseCode::JOB_NOT_ALLOWED, $this->rulesOffered(true));
        $this->assertContains(ExpenseCode::JOB_REQUIRED, $this->rulesOffered(true));
    }

    /**
     * The two codes the old filter hid, named individually because they are the
     * reason the bug was visible to users rather than only to a query.
     */
    public function test_the_codes_an_office_requisition_actually_needs_are_reachable(): void
    {
        $offered = $this->codesOffered(false);

        // Buying material into the store. It is `conditional`, so the old
        // `= not_allowed` filter withheld it from the one context that uses it.
        $this->assertContains('NE-008', $offered, 'A stores replenishment must be classifiable.');

        // Bank and mobile-money charges: `optional`, and likewise unreachable,
        // which left a non-project petty-cash spend with no honest code.
        $this->assertContains('OE-FIN-001', $offered);
    }

    /**
     * A purchase order can only carry things a supplier delivers.
     *
     * The catalogue must hold the rest — stores issues, VAT remitted, a
     * petty-cash float top-up — because the cost collector posts all of them,
     * and the capture form legitimately offers several. But the requisition
     * asked for the whole catalogue, so a purchase order could be coded to
     * "Client refund / credit note".
     */
    public function test_the_procurable_slice_holds_no_accounting_movements(): void
    {
        $response = $this->getJson('/api/costs/expense-codes?limit=100&procurable=true')->assertOk();
        $codes = collect($response->json('data'))->pluck('code');

        foreach (['NE-009', 'NE-013', 'NE-014', 'NE-016', 'NE-018', 'NE-019', 'NE-023', 'OE-FIN-001'] as $movement) {
            $this->assertNotContains($movement, $codes, "{$movement} is not something a supplier delivers.");
        }
    }

    public function test_the_procurable_slice_still_holds_everything_you_can_buy(): void
    {
        $response = $this->getJson('/api/costs/expense-codes?limit=100&procurable=true')->assertOk();
        $codes = collect($response->json('data'))->pluck('code');

        // Direct materials, a subcontract, a hire, and the stores replenishment
        // — one from each corner of what a requisition is actually raised for.
        foreach (['DM-WD-001', 'SC-FAB-001', 'EQ-HIR-001', 'NE-008'] as $purchase) {
            $this->assertContains($purchase, $codes);
        }
    }

    public function test_staff_allowances_stay_available_to_finance_but_not_procurement(): void
    {
        $purchaseCodes = collect($this->getJson('/api/costs/expense-codes?limit=100&procurable=true&job_context=true')
            ->assertOk()->json('data'))->pluck('code');
        $financeCodes = $this->codesOffered(true);

        foreach (['DL-ALW-001', 'PF-PDM-001'] as $code) {
            $this->assertNotContains($code, $purchaseCodes);
            $this->assertContains($code, $financeCodes);
        }
    }

    public function test_not_asking_for_the_procurable_slice_leaves_the_catalogue_whole(): void
    {
        $codes = collect($this->getJson('/api/costs/expense-codes?limit=100')->assertOk()->json('data'))
            ->pluck('code');

        // The capture form posts these, so the filter must be opt-in.
        $this->assertContains('NE-001', $codes, 'Petty cash still tops up its own float.');
        $this->assertContains('NE-009', $codes, 'Stores still issues material to a job.');
    }

    /**
     * Both filters at once — the office requisition's actual query. Everything it
     * offers must be buyable *and* legal without a job number.
     */
    public function test_an_office_requisition_is_offered_only_buyable_codes(): void
    {
        $offered = collect($this->getJson(
            '/api/costs/expense-codes?limit=100&procurable=true&job_context=false',
        )->assertOk()->json('data'));

        $this->assertNotEmpty($offered);

        foreach ($offered as $code) {
            $this->assertTrue($code['is_procurable']);
            $this->assertNotSame(ExpenseCode::JOB_REQUIRED, $code['job_id_rule']);
        }

        // The one it exists for: buying material into the store.
        $this->assertContains('NE-008', $offered->pluck('code'));
    }

    /**
     * The other half of the office-requisition problem.
     *
     * Fixing `job_context` corrected which codes the no-project list asks for.
     * It could not invent the ones that were never written: the answer was still
     * seven codes in four families, three of them office transport, airtime and
     * welfare, the rest balance-sheet rows. Stationery, drill bits, PPE,
     * detergent, a machine service and the electricity bill — everything a
     * company buys that is not against a job — had nothing to be classified as.
     *
     * Named individually because a count would pass on the wrong nine codes.
     */
    public function test_an_office_requisition_can_name_the_things_an_office_buys(): void
    {
        $offered = collect($this->getJson(
            '/api/costs/expense-codes?limit=100&procurable=true&job_context=false',
        )->assertOk()->json('data'))->pluck('code');

        foreach ([
            'OE-OFF-001',   // Office supplies and stationery
            'OE-OFF-002',   // Printer and IT consumables
            'OE-WSC-001',   // Workshop consumables and small tools
            'OE-MNT-001',   // Machinery repair and servicing
            'OE-PPE-001',   // Protective equipment and workshop safety
            'OE-CLN-001',   // Cleaning supplies and services
            'OE-WST-001',   // Waste collection and disposal
            'OE-UTL-001',   // Workshop electricity
            'OE-UTL-002',   // Office rent and electricity
        ] as $code) {
            $this->assertContains($code, $offered, "{$code} is bought without a job number.");
        }
    }

    /**
     * These are overhead by construction, not by convention. If one of them ever
     * became job-costable it would compete with the project code that already
     * covers that spend — EQ-TOL-001 site tools against OE-WSC-001 workshop
     * consumables, EQ-SAF-001 site safety against OE-PPE-001 — and the same
     * purchase could land in WIP or in overhead depending on who typed it.
     */
    public function test_nothing_bought_for_the_office_or_workshop_can_be_charged_to_a_job(): void
    {
        $withJob = collect($this->getJson(
            '/api/costs/expense-codes?limit=100&procurable=true&job_context=true',
        )->assertOk()->json('data'))->pluck('code');

        foreach (['OE-OFF-001', 'OE-WSC-001', 'OE-PPE-001', 'OE-CLN-001', 'OE-UTL-001'] as $code) {
            $this->assertNotContains($code, $withJob);
            $this->assertSame(
                ExpenseCode::JOB_NOT_ALLOWED,
                ExpenseCode::where('code', $code)->value('job_id_rule'),
            );
        }
    }

    /**
     * A code with no debit account seeds inactive, so the office-supplies pair
     * is the one place this change could fail silently: it is the only row that
     * needed a new account (7150) rather than one the chart already carried.
     */
    public function test_the_office_supplies_codes_post_somewhere_real(): void
    {
        foreach (['OE-OFF-001', 'OE-OFF-002'] as $code) {
            $expenseCode = ExpenseCode::where('code', $code)->firstOrFail();

            $this->assertTrue($expenseCode->is_active);
            $this->assertNotNull(
                $expenseCode->default_debit_account_id,
                "{$code} would seed inactive without 7150 Office Supplies & Stationery.",
            );
        }
    }

    /**
     * The picker's first screen is a list of family names, and four of them were
     * the account's name rather than the purchase's. A requester restocking a
     * shelf does not look under "Inventory purchase".
     */
    public function test_the_categories_are_named_for_the_purchase_not_the_account(): void
    {
        $families = collect($this->getJson(
            '/api/costs/expense-codes/families?procurable=true&job_context=false',
        )->assertOk()->json('data'))->pluck('expense_family');

        foreach (['Inventory purchase', 'Prepayments', 'Leasehold improvements', 'Administration'] as $ledgerName) {
            $this->assertNotContains($ledgerName, $families);
        }

        foreach ([
            'Stock replenishment',
            'Office supplies',
            'Workshop and maintenance',
            'Safety and protective equipment',
            'Cleaning and waste',
            'Premises and utilities',
            'Staff and administration',
        ] as $plainName) {
            $this->assertContains($plainName, $families);
        }
    }

    public function test_asking_for_no_context_filters_nothing(): void
    {
        $response = $this->getJson('/api/costs/expense-codes?limit=100')->assertOk();
        $rules = collect($response->json('data'))->pluck('job_id_rule')->unique();

        $this->assertContains(ExpenseCode::JOB_REQUIRED, $rules);
        $this->assertContains(ExpenseCode::JOB_NOT_ALLOWED, $rules);
    }

    /**
     * The families endpoint drives the picker's first screen, so a family the
     * list endpoint would offer must not be missing from it — otherwise the code
     * exists but nothing can navigate to it.
     */
    public function test_the_family_list_agrees_with_the_code_list(): void
    {
        foreach ([true, false] as $jobContext) {
            $families = collect($this->getJson('/api/costs/expense-codes/families?job_context='
                .($jobContext ? 'true' : 'false'))->assertOk()->json('data'))
                ->pluck('expense_family')->sort()->values();

            $fromCodes = ExpenseCode::active()
                ->whereIn('code', $this->codesOffered($jobContext))
                ->pluck('expense_family')->unique()->sort()->values();

            $this->assertEmpty(
                $fromCodes->diff($families)->all(),
                'Every family holding an offered code must be browsable.',
            );
        }
    }
}
