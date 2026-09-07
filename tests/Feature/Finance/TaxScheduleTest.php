<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\TaxScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two returns WNG files, and the evidence they stand on.
 *
 * These pin behaviour that is expensive to get wrong in a direction nobody
 * notices: a claim schedule that quietly omits a line loses real money, and a
 * WHT return that under-remits is a penalty. Every assertion here is about
 * something a revenue authority would ask to see.
 */
class TaxScheduleTest extends TestCase
{
    use RefreshDatabase;

    private TaxScheduleService $schedules;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        $this->schedules = $this->app->make(TaxScheduleService::class);

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
    }

    /** The SUPPLIER payee type, resolved rather than assumed to be id 1. */
    private function supplierPayeeTypeId(): int
    {
        return (int) DB::table('payee_types')->where('code', 'SUPPLIER')->value('id');
    }

    private function treatment(string $code): VatTreatment
    {
        return VatTreatment::where('code', $code)->firstOrFail();
    }

    /** A cost line as the ledger would hold it once posted. */
    private function posted(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'job_number' => 'WNG-01-2026-004',
            'amount' => '11600.00',
            'tax_amount' => '1600.00',
            'net_amount' => '10000.00',
            'base_net_amount' => '10000.00',
            'wht_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-03-10 09:00:00',
            'tax_point_date' => '2026-03-10',
            'vat_treatment_id' => $this->treatment('STD16-REC')->id,
            'payee_name' => 'Timber Yard Ltd',
            'supplier_pin' => 'P051234567X',
            'etims_invoice_no' => 'ETIMS-0001',
            'supplier_invoice_no' => 'INV-778',
            'posted_at' => now(),
        ], $overrides));
    }

    public function test_the_claim_schedule_totals_only_what_can_be_substantiated(): void
    {
        $this->posted();
        $this->posted(['etims_invoice_no' => null, 'tax_amount' => '800.00', 'net_amount' => '5000.00']);

        $schedule = $this->schedules->vatInputSchedule('2026-03-01', '2026-03-31');

        $this->assertSame('1600.00', $schedule['totals']['claimable_vat']);
        $this->assertSame('800.00', $schedule['totals']['unsupported_vat']);
        $this->assertSame(2, $schedule['totals']['line_count']);
        $this->assertSame(1, $schedule['totals']['unsupported_count']);
    }

    public function test_a_claim_is_dated_by_the_supplier_document_not_by_consumption(): void
    {
        // Bought in March, consumed by the job in July. It is a March claim; a
        // schedule that filed it in July would have missed the window.
        $this->posted(['incurred_at' => '2026-07-04 09:00:00', 'tax_point_date' => '2026-03-10']);

        $this->assertSame('1600.00', $this->schedules->vatInputSchedule('2026-03-01', '2026-03-31')['totals']['claimable_vat']);
        $this->assertSame('0.00', $this->schedules->vatInputSchedule('2026-07-01', '2026-07-31')['totals']['claimable_vat']);
    }

    public function test_unposted_reversed_and_non_recoverable_lines_stay_out_of_the_return(): void
    {
        $this->posted(['posted_at' => null]);
        $this->posted(['status' => CostLine::STATUS_REVERSED]);
        $this->posted(['vat_treatment_id' => $this->treatment('STD16-NONREC')->id]);
        $this->posted(['nature' => CostLine::NATURE_COMMITTED]);

        $schedule = $this->schedules->vatInputSchedule('2026-03-01', '2026-03-31');

        $this->assertSame(0, $schedule['totals']['line_count']);
        $this->assertSame('0.00', $schedule['totals']['claimable_vat']);
    }

    public function test_the_etims_gap_separates_what_is_lost_from_what_can_still_be_saved(): void
    {
        // Window is six months. As of 1 Oct 2026: a March tax point expired on
        // 10 September; an August one has until 10 February.
        $this->posted(['etims_invoice_no' => null, 'tax_point_date' => '2026-03-10']);
        $this->posted(['etims_invoice_no' => null, 'tax_point_date' => '2026-08-20', 'tax_amount' => '400.00']);
        // Twelve days left: expiring, not merely unsupported.
        $this->posted(['etims_invoice_no' => null, 'tax_point_date' => '2026-04-13', 'tax_amount' => '250.00']);

        $gap = $this->schedules->etimsGap('2026-10-01');

        $this->assertSame('1600.00', $gap['totals']['vat_forfeited']);
        $this->assertSame('250.00', $gap['totals']['vat_at_risk']);
        $this->assertSame('2250.00', $gap['totals']['vat_unsupported_total']);

        // Ordered by urgency, not by value.
        $this->assertSame('expired', $gap['rows'][0]['claim_status']);
        $this->assertSame('expiring', $gap['rows'][1]['claim_status']);
    }

    public function test_a_missing_supplier_pin_makes_a_claim_unsupported_even_with_an_etims_number(): void
    {
        $this->posted(['supplier_pin' => null]);

        $gap = $this->schedules->etimsGap('2026-04-01');

        $this->assertCount(1, $gap['rows']);
        $this->assertSame(['supplier_pin'], $gap['rows'][0]['missing']);
    }

    public function test_the_wht_return_groups_a_supplier_month_into_one_remittable_row(): void
    {
        $category = WhtCategory::where('code', 'PROF-RES')->firstOrFail();

        foreach (['2026-03-04', '2026-03-19'] as $date) {
            $this->posted([
                'incurred_at' => $date . ' 09:00:00',
                'payee_name' => 'Kariuki & Associates',
                'payee_id' => 77,
                'payee_type_id' => $this->supplierPayeeTypeId(),
                'wht_category_id' => $category->id,
                'net_amount' => '100000.00',
                'wht_amount' => '5000.00',
            ]);
        }

        $schedule = $this->schedules->whtSchedule(2026, 3);

        $this->assertCount(1, $schedule['rows']);
        $this->assertSame('10000.00', $schedule['rows'][0]['wht_amount']);
        $this->assertSame(2, $schedule['rows'][0]['payment_count']);
        $this->assertSame('10000.00', $schedule['totals']['wht_remittable']);
    }

    /**
     * The under-withholding TaxResolver documents as a known gap.
     *
     * Four payments of 20,000 under a 50,000 per-payment threshold each withhold
     * nothing. The month is 80,000 and the category aggregates monthly, so 4,000
     * should have been withheld. Before there was a supplier-month view, this
     * was invisible.
     */
    public function test_the_wht_return_exposes_a_month_that_crossed_an_aggregate_threshold(): void
    {
        $category = WhtCategory::where('code', 'CONTRACT-RES')->firstOrFail();
        $category->update(['threshold_amount' => '50000.00', 'aggregate_monthly' => true]);

        foreach (range(1, 4) as $day) {
            $this->posted([
                'incurred_at' => "2026-03-0{$day} 09:00:00",
                'payee_name' => 'Site Crew Contractors',
                'payee_id' => 91,
                'payee_type_id' => $this->supplierPayeeTypeId(),
                'wht_category_id' => $category->id,
                'net_amount' => '20000.00',
                'wht_amount' => '0.00',
            ]);
        }

        $row = $this->schedules->whtSchedule(2026, 3)['rows'][0];

        $this->assertTrue($row['aggregation_exposure']);
        $this->assertSame('2400.00', $row['aggregation_shortfall']);
        $this->assertNotNull($row['aggregation_note']);
    }

    public function test_the_due_date_follows_the_finance_setting_rather_than_a_constant(): void
    {
        $this->assertSame(
            '2026-04-20',
            $this->schedules->vatInputSchedule('2026-03-01', '2026-03-31')['due_date'],
        );

        DB::table('finance_settings')->where('key', 'tax_return_due_day')->update(['value' => '15']);

        $this->assertSame(
            '2026-04-15',
            $this->schedules->vatInputSchedule('2026-03-01', '2026-03-31')['due_date'],
        );
    }

    public function test_wht_does_not_reuse_the_monthly_vat_deadline(): void
    {
        $schedule = $this->schedules->whtSchedule(2026, 3);
        $this->assertNull($schedule['due_date']);
        $this->assertStringContainsString('five working days after deduction', $schedule['remittance_rule']);
        $this->assertStringContainsString('kra.go.ke', $schedule['remittance_source']);
    }

    public function test_the_schedules_are_closed_to_anyone_without_the_reports_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/finance/tax/wht-schedule?year=2026&month=3')
            ->assertForbidden();

        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/tax/wht-schedule?year=2026&month=3')
            ->assertOk();
    }

    /**
     * The envelope the Vue screen binds to.
     *
     * Named keys rather than a loose smoke test: the schedules render straight
     * off these paths, so a rename here should break a test rather than a page
     * that nobody opens until the twentieth of the month.
     */
    public function test_the_json_envelope_matches_what_the_screen_binds_to(): void
    {
        $this->posted(['etims_invoice_no' => null]);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/tax/vat-input-schedule?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period' => ['from', 'to'],
                    'rows' => [['cost_line_id', 'ref', 'supplier_name', 'supplier_pin', 'etims_invoice_no',
                        'tax_point_date', 'treatment_code', 'net_amount', 'vat_amount', 'is_supported',
                        'missing', 'claim_deadline', 'days_to_deadline', 'claim_status']],
                    'totals' => ['claimable_vat', 'claimable_net', 'unsupported_vat', 'line_count', 'unsupported_count'],
                    'due_date', 'basis',
                ],
            ]);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/tax/etims-gap?as_of=2026-04-01')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'as_of', 'rows', 'action',
                    'totals' => ['vat_forfeited', 'forfeited_count', 'vat_at_risk', 'at_risk_count',
                        'vat_unsupported_total', 'line_count'],
                ],
            ]);

        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/tax/wht-schedule?year=2026&month=3')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period' => ['year', 'month', 'from', 'to'],
                    'rows', 'due_date', 'basis',
                    'totals' => ['wht_remittable', 'gross_subject', 'payee_count',
                        'under_withheld', 'exposed_payee_count'],
                ],
            ]);
    }

    /**
     * The disclosure travels with the numbers.
     *
     * The ledger holds no revenue, payroll or opening balances, so its account
     * summary is not a trial balance and the screen must not present it as one.
     * Shipping the caveat in the payload is what stops the two being separated.
     */
    public function test_the_account_summary_declares_that_it_is_not_a_statutory_trial_balance(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/journals/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.coverage.is_statutory_trial_balance', false)
            ->assertJsonStructure(['data' => ['coverage' => ['includes', 'excludes', 'note']]]);
    }

    public function test_a_schedule_downloads_as_csv_for_the_filing_pack(): void
    {
        $this->posted();

        $response = $this->actingAs($this->reader, 'sanctum')
            ->get('/api/finance/tax/vat-input-schedule?from=2026-03-01&to=2026-03-31&format=csv')
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('ETIMS-0001', $csv);
        $this->assertStringContainsString('P051234567X', $csv);
        $this->assertStringContainsString('1600.00', $csv);
    }
}
