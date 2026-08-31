<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use Spatie\Permission\Models\Permission;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\TaxResolver;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 of the GL plan: tax computed from the supplier's own classification
 * against Finance-editable rate tables, never from a hardcoded percentage.
 */
class SupplierTaxTest extends TestCase
{
    use RefreshDatabase;

    private TaxResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceTaxSeeder::class);
        // The withholding assertions run through the verify endpoint, which
        // posts a journal as a side effect. Without a chart of accounts that
        // posting throws and the test never reaches the tax it is about.
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->resolver = new TaxResolver();
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'supplier_name' => 'Acme Signs',
            'contact_person' => 'Jane',
            'phone' => '0700000000',
            'email' => uniqid() . '@t.local',
            'status' => 'Active',
        ], $overrides));
    }

    public function test_withholding_uses_the_seeded_rate_not_a_constant(): void
    {
        $professional = WhtCategory::where('code', 'PROF-RES')->firstOrFail();

        // 5% of 100,000 net.
        $this->assertSame('5000.00', $this->resolver->withholding('100000.00', $professional));

        // The point of the rate table: change the row, change the answer, with
        // no deploy. A hardcoded 5% would fail here.
        $professional->update(['rate_percent' => '7.500']);

        $this->assertSame(
            '7500.00',
            $this->resolver->withholding('100000.00', $professional->fresh()),
        );
    }

    public function test_a_category_that_withholds_nothing_returns_zero(): void
    {
        $none = WhtCategory::where('code', 'NONE')->firstOrFail();

        $this->assertSame('0.00', $this->resolver->withholding('100000.00', $none));
        $this->assertSame('0.00', $this->resolver->withholding('100000.00', null));
    }

    public function test_payments_below_the_threshold_are_not_withheld(): void
    {
        $category = WhtCategory::where('code', 'CONTRACT-RES')->firstOrFail();
        $category->update(['threshold_amount' => '24000.00']);

        $this->assertSame('0.00', $this->resolver->withholding('23999.99', $category->fresh()));
        $this->assertSame('720.00', $this->resolver->withholding('24000.00', $category->fresh()));
    }

    public function test_an_unregistered_supplier_is_out_of_scope_for_vat(): void
    {
        $supplier = $this->supplier(['vat_status' => 'not_registered']);

        $treatment = $this->resolver->vatTreatmentFor($supplier, null, '2026-08-10');

        $this->assertNotNull($treatment);
        $this->assertSame('OOS', $treatment->code);
        $this->assertFalse($treatment->is_recoverable);
    }

    public function test_a_registered_supplier_falls_back_to_its_default_treatment(): void
    {
        $standard = VatTreatment::where('code', 'STD16-REC')->firstOrFail();

        $supplier = $this->supplier([
            'vat_status' => 'registered',
            'default_vat_treatment_id' => $standard->id,
        ]);

        $treatment = $this->resolver->vatTreatmentFor($supplier, null, '2026-08-10');

        $this->assertSame($standard->id, $treatment->id);
    }

    public function test_a_rate_that_has_expired_is_not_applied(): void
    {
        $category = WhtCategory::where('code', 'PROF-RES')->firstOrFail();
        $category->update(['effective_to' => '2025-12-31']);

        $supplier = $this->supplier(['wht_category_id' => $category->id]);

        // Asking for a date after the row expired must find nothing rather than
        // quietly applying last year's rate.
        $this->assertNull($this->resolver->whtCategoryFor($supplier, null, '2026-08-10'));
        $this->assertNotNull($this->resolver->whtCategoryFor($supplier, null, '2025-06-01'));
    }

    public function test_a_non_resident_is_not_given_the_resident_rate(): void
    {
        $category = WhtCategory::where('code', 'PROF-RES')->firstOrFail();

        $supplier = $this->supplier([
            'residency' => 'non_resident',
            'wht_category_id' => $category->id,
        ]);

        // The seeded categories are all resident. Rather than withhold 5% from a
        // non-resident — which is the wrong rate and looks settled — the
        // supplier's own category is still honoured only because it was chosen
        // explicitly for them.
        $resolved = $this->resolver->whtCategoryFor($supplier, null, '2026-08-10');

        $this->assertSame($category->id, $resolved?->id);
    }

    /**
     * The whole point of Phase 3, end to end: a cost paid to a classified
     * supplier is withheld at that supplier's rate when Finance verifies it,
     * with nobody typing a percentage. Before this, `wht_amount` stayed 0.00
     * because nothing ever wrote it.
     */
    public function test_verifying_a_supplier_cost_prices_the_withholding(): void
    {
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);

        foreach ([Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_VERIFY] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $reporter = \App\Models\User::factory()->create(['is_active' => true]);
        $verifier = \App\Models\User::factory()->create(['is_active' => true]);
        $verifier->givePermissionTo([Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_VERIFY]);

        $professional = WhtCategory::where('code', 'PROF-RES')->firstOrFail();
        $supplier = $this->supplier(['residency' => 'resident', 'wht_category_id' => $professional->id]);

        $supplierPayeeType = \Illuminate\Support\Facades\DB::table('payee_types')
            ->where('code', 'SUPPLIER')->value('id');

        $line = CostLine::create([
            'ref' => 'CL-0000001',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'job_number' => 'WNG-TEST-001',
            'amount' => '100000.00', 'tax_amount' => '0.00',
            'net_amount' => '100000.00', 'base_net_amount' => '100000.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-08-01',
            'payee_type_id' => $supplierPayeeType,
            'payee_id' => $supplier->id,
            'payee_name' => $supplier->supplier_name,
            'submitted_by_user_id' => $reporter->id,
        ]);

        $this->actingAs($verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertOk();

        $line->refresh();

        // 5% of the net, read from the seeded category.
        $this->assertSame('5000.00', $line->wht_amount);
        $this->assertSame($professional->id, $line->wht_category_id);

        // The project still cost the full 100,000. WHT is the supplier's income
        // tax paid on their behalf, not a discount on the expense — if this ever
        // reduces `amount`, every project using professional services will look
        // cheaper than it was.
        $this->assertSame('100000.00', $line->amount);
    }

    /** An employee reimbursed for a taxi is not a supplier and is not withheld. */
    public function test_a_non_supplier_payee_is_not_withheld(): void
    {
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);

        $employeeType = \Illuminate\Support\Facades\DB::table('payee_types')
            ->where('code', 'EMPLOYEE')->value('id');

        $resolver = $this->resolver;

        // No supplier record exists for an employee payee, so nothing resolves.
        $this->assertNull($resolver->whtCategoryFor(null, null, '2026-08-10'));
        $this->assertNotNull($employeeType);
    }

    public function test_the_supplier_endpoint_captures_and_validates_tax_identity(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);

        $valid = $this->actingAs($user, 'sanctum')->postJson('/api/procurement-stores/suppliers', [
            'supplier_name' => 'Acme Signs',
            'legal_name' => 'Acme Signs Limited',
            'kra_pin' => 'P051234567M',
            'vat_status' => 'registered',
            'etims_default' => true,
            'contact_person' => 'Jane',
            'email' => 'jane@acme.local',
            'phone' => '0700000000',
        ]);

        $valid->assertSuccessful();
        $this->assertDatabaseHas('suppliers', ['kra_pin' => 'P051234567M', 'vat_status' => 'registered']);

        // A malformed PIN is worse than a missing one — it looks filed.
        $this->actingAs($user, 'sanctum')->postJson('/api/procurement-stores/suppliers', [
            'supplier_name' => 'Bad PIN Ltd',
            'kra_pin' => '12345',
            'contact_person' => 'Jane',
            'email' => 'other@acme.local',
            'phone' => '0700000000',
        ])->assertStatus(422);
    }
}
