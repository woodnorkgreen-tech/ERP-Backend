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
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Refusing to post a VAT claim that cannot be defended.
 *
 * Verification is the last moment the evidence is cheap to obtain — the person
 * clicking Verify is holding the supplier's document. After that the cost is in
 * the books, the six-month window starts running, and recovering the eTIMS
 * number means chasing a supplier for paperwork about a delivery everyone has
 * forgotten.
 *
 * The gate is narrow on purpose, and half these tests are about what it must
 * NOT block: a rule that fires on ordinary spend gets worked around, and a
 * worked-around control is worse than none.
 */
class ClaimEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        foreach ([Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_VERIFY] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->reporter = User::factory()->create(['is_active' => true]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_VERIFY]);
    }

    private function treatmentId(string $code): int
    {
        return (int) VatTreatment::where('code', $code)->value('id');
    }

    /** The SUPPLIER payee type, resolved rather than assumed to be id 1. */
    private function supplierPayeeTypeId(): int
    {
        return (int) DB::table('payee_types')->where('code', 'SUPPLIER')->value('id');
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'supplier_name' => 'Test Supplier',
            'vat_status' => 'registered',
            'etims_default' => true,
            'residency' => 'resident',
            'contact_person' => 'Desk',
            'phone' => '0700000000',
            'email' => 'supplier@example.test',
            'status' => 'Active',
        ], $overrides));
    }

    private function submitted(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'job_number' => 'WNG-01-2026-004',
            'amount' => '11600.00',
            'tax_amount' => '0.00',
            'net_amount' => '11600.00',
            'base_net_amount' => '11600.00',
            'fx_rate' => '1',
            'incurred_at' => now()->subDays(3),
            'submitted_by_user_id' => $this->reporter->id,
        ], $overrides));
    }

    /** @param array<string, mixed> $payload */
    private function verify(CostLine $line, array $payload = [])
    {
        return $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", $payload);
    }

    public function test_recoverable_vat_without_an_etims_number_is_refused(): void
    {
        $line = $this->submitted();

        $this->verify($line, [
            'tax_amount' => '1600.00',
            'vat_treatment_id' => $this->treatmentId('STD16-REC'),
        ])->assertStatus(422)->assertJsonValidationErrors(['etims_invoice_no']);

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->fresh()->status);
    }

    public function test_the_claim_posts_once_the_evidence_is_supplied(): void
    {
        $supplier = $this->supplier(['supplier_name' => 'Timber Yard Ltd', 'kra_pin' => 'P051234567X']);

        $line = $this->submitted(['payee_type_id' => $this->supplierPayeeTypeId(), 'payee_id' => $supplier->id]);

        $this->verify($line, [
            'tax_amount' => '1600.00',
            'vat_treatment_id' => $this->treatmentId('STD16-REC'),
            'etims_invoice_no' => 'ETIMS-0001',
            'supplier_invoice_no' => 'INV-778',
        ])->assertOk();

        $line->refresh();
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame('ETIMS-0001', $line->etims_invoice_no);
        // Snapshotted from the supplier, not joined to it.
        $this->assertSame('P051234567X', $line->supplier_pin);
        $this->assertNotNull($line->tax_point_date);
    }

    public function test_a_supplier_with_no_kra_pin_cannot_have_a_claim_posted_against_them(): void
    {
        $supplier = $this->supplier(['supplier_name' => 'Informal Trader', 'kra_pin' => null]);

        $line = $this->submitted(['payee_type_id' => $this->supplierPayeeTypeId(), 'payee_id' => $supplier->id]);

        $this->verify($line, [
            'tax_amount' => '1600.00',
            'vat_treatment_id' => $this->treatmentId('STD16-REC'),
            'etims_invoice_no' => 'ETIMS-0002',
        ])->assertStatus(422)->assertJsonValidationErrors(['supplier_pin']);
    }

    public function test_spend_that_claims_nothing_is_never_asked_for_a_claim_reference(): void
    {
        // Non-recoverable VAT is a cost, not a claim.
        $this->verify($this->submitted(), [
            'tax_amount' => '1600.00',
            'vat_treatment_id' => $this->treatmentId('STD16-NONREC'),
        ])->assertOk();

        // Exempt and out-of-scope carry no VAT to reclaim.
        $this->verify($this->submitted(), ['vat_treatment_id' => $this->treatmentId('EXEMPT')])->assertOk();
        $this->verify($this->submitted(), ['vat_treatment_id' => $this->treatmentId('OOS')])->assertOk();

        // A recoverable treatment priced at zero VAT claims nothing either — a
        // zero-rated purchase from a registered supplier is the ordinary case.
        $this->verify($this->submitted(), [
            'tax_amount' => '0.00',
            'vat_treatment_id' => $this->treatmentId('ZERO'),
        ])->assertOk();
    }

    public function test_a_tax_point_is_recorded_even_when_no_claim_is_involved(): void
    {
        $line = $this->submitted();

        $this->verify($line)->assertOk();

        // Null here would drop the line out of every schedule silently, which is
        // the VAT error nobody notices until an assessment.
        $this->assertNotNull($line->fresh()->tax_point_date);
    }

    /**
     * The commonest claimable purchase in the business: a hardware shop paid
     * out of petty cash, printing its PIN on an eTIMS receipt and never once
     * becoming a vendor master. Requiring a supplier record here would have
     * made that spend unclaimable, and the workaround would have been to stop
     * claiming it.
     */
    public function test_a_pin_typed_off_the_receipt_substantiates_a_claim_with_no_supplier_record(): void
    {
        $line = $this->submitted(['payee_name' => 'Jogoo Road Hardware']);

        $this->verify($line, [
            'tax_amount' => '1600.00',
            'vat_treatment_id' => $this->treatmentId('STD16-REC'),
            'etims_invoice_no' => 'ETIMS-0044',
            'supplier_pin' => 'p051234567x',
        ])->assertOk();

        // Normalised, because a PIN is matched by KRA as an exact string.
        $this->assertSame('P051234567X', $line->fresh()->supplier_pin);
    }

    public function test_a_missing_reference_does_not_blank_the_numbers_it_is_attached_to(): void
    {
        $line = $this->submitted();

        $this->actingAs($this->verifier, 'sanctum')
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?" . http_build_query([
                'tax_amount' => '1600.00',
                'vat_treatment_id' => $this->treatmentId('STD16-REC'),
            ]))
            ->assertOk()
            // The split still prices. Hiding it behind the document error would
            // stop the verifier seeing the VAT they are being asked to evidence.
            ->assertJsonPath('split.net_amount', '10000.00')
            ->assertJsonPath('split.tax_amount', '1600.00')
            ->assertJsonPath('errors.etims_invoice_no.0', fn ($m) => $m !== null);
    }

    public function test_the_preview_announces_the_requirement_before_anyone_commits(): void
    {
        $line = $this->submitted();

        $this->actingAs($this->verifier, 'sanctum')
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?" . http_build_query([
                'tax_amount' => '1600.00',
                'vat_treatment_id' => $this->treatmentId('STD16-REC'),
            ]))
            ->assertOk()
            ->assertJsonPath('document.required', true)
            ->assertJsonPath('errors.etims_invoice_no.0', fn ($m) => str_contains((string) $m, 'eTIMS'));
    }

    public function test_the_preview_quotes_the_deadline_the_claim_dies_on(): void
    {
        $line = $this->submitted(['incurred_at' => '2026-03-10 09:00:00']);

        $this->actingAs($this->verifier, 'sanctum')
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?" . http_build_query([
                'tax_amount' => '1600.00',
                'vat_treatment_id' => $this->treatmentId('STD16-REC'),
                'etims_invoice_no' => 'ETIMS-0003',
            ]))
            ->assertOk()
            // Six-month window off the tax point, from vat_treatments.
            ->assertJsonPath('document.claim_deadline', '2026-09-10');
    }
}
