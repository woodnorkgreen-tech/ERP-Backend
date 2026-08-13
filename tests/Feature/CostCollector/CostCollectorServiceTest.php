<?php

namespace Tests\Feature\CostCollector;

use App\Modules\Finance\CostCollector\Contracts\CollectsCost;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The collector's behaviour is driven entirely by the expense catalogue, so
 * these tests build catalogue rows rather than asserting against hardcoded
 * knowledge of any particular expense type. If a rule here can only be satisfied
 * by changing PHP, the design has leaked.
 */
class CostCollectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private CollectsCost $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);

        $this->collector = app(CollectsCost::class);
    }

    private function code(array $overrides = []): ExpenseCode
    {
        return ExpenseCode::create(array_merge([
            'code' => 'TST-001',
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct materials',
            'expense_type' => 'Test material',
            'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating',
            'is_active' => true,
        ], $overrides));
    }

    public function test_it_records_a_cost_and_derives_the_dimensions(): void
    {
        $this->code();

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001',
            amount: '25000.00',
            jobNumber: 'WNG-TEST-001',
            description: 'Truck hire',
        ));

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->status);
        $this->assertSame(CostLine::NATURE_ACTUAL, $line->nature);
        $this->assertSame('WNG-TEST-001', $line->job_number);
        $this->assertMatchesRegularExpression('/^CL-\d{7}$/', $line->ref);

        // Cost cause defaults to the catalogue default rather than being asked for.
        $this->assertNotNull($line->cost_cause_id);
        // Every line carries a period from the moment it is captured.
        $this->assertNotNull($line->accounting_period_id);
    }

    public function test_gross_stays_gross_and_project_cost_is_net_of_tax(): void
    {
        $this->code();

        // The brief's own worked example: 11,600 inclusive of 1,600 VAT.
        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001',
            amount: '11600.00',
            taxAmount: '1600.00',
            jobNumber: 'WNG-TEST-001',
        ));

        $this->assertSame('11600.00', $line->amount);
        $this->assertSame('10000.00', $line->net_amount);
        $this->assertSame('10000.00', $line->base_net_amount);
    }

    public function test_it_converts_to_base_currency_for_reporting(): void
    {
        $this->code();

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001',
            amount: '100.00',
            jobNumber: 'WNG-TEST-001',
            currency: 'USD',
            fxRate: '129.50',
        ));

        $this->assertSame('100.00', $line->net_amount);
        $this->assertSame('12950.00', $line->base_net_amount);
    }

    public function test_it_enforces_the_catalogue_job_id_rule_both_ways(): void
    {
        $this->code(['code' => 'NEEDS-JOB', 'job_id_rule' => ExpenseCode::JOB_REQUIRED]);
        $this->code(['code' => 'NO-JOB', 'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED]);

        try {
            $this->collector->collect(new CostContext(expenseCode: 'NEEDS-JOB', amount: '100.00'));
            $this->fail('A Job-ID-required code accepted a cost with no project.');
        } catch (CostValidationException $e) {
            $this->assertArrayHasKey('jobNumber', $e->errors);
        }

        try {
            $this->collector->collect(new CostContext(
                expenseCode: 'NO-JOB', amount: '100.00', jobNumber: 'WNG-TEST-001',
            ));
            $this->fail('A Job-ID-forbidden code accepted a project charge.');
        } catch (CostValidationException $e) {
            $this->assertArrayHasKey('jobNumber', $e->errors);
        }
    }

    public function test_required_detail_fields_come_from_the_catalogue_row(): void
    {
        $this->code(['extra_operational_data' => [
            ['key' => 'item_code', 'label' => 'Item code', 'type' => 'text', 'required' => true],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'required' => false],
        ]]);

        try {
            $this->collector->collect(new CostContext(
                expenseCode: 'TST-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
                details: ['notes' => 'no item code supplied'],
            ));
            $this->fail('A required catalogue field was not enforced.');
        } catch (CostValidationException $e) {
            $this->assertArrayHasKey('details.item_code', $e->errors);
            $this->assertStringContainsString('Item code', $e->errors['details.item_code'][0]);
        }

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
            details: ['item_code' => 'MDF-18'],
        ));

        $this->assertSame('MDF-18', $line->details['item_code']);
    }

    public function test_required_evidence_comes_from_the_catalogue_row(): void
    {
        $this->code(['minimum_evidence' => [
            ['key' => 'etims_invoice', 'label' => 'eTIMS invoice', 'required' => true],
        ]]);

        try {
            $this->collector->collect(new CostContext(
                expenseCode: 'TST-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
            ));
            $this->fail('Mandatory evidence was not enforced.');
        } catch (CostValidationException $e) {
            $this->assertArrayHasKey('evidence.etims_invoice', $e->errors);
        }

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
            evidence: [['key' => 'etims_invoice', 'path' => 'receipts/abc.jpg']],
        ));

        $this->assertNotNull($line->evidence);
    }

    public function test_a_producer_that_retries_does_not_double_post(): void
    {
        $this->code();

        $context = new CostContext(
            expenseCode: 'TST-001',
            amount: '4500.00',
            jobNumber: 'WNG-TEST-001',
            sourceType: 'InventoryLog',
            sourceId: 99,
        );

        $first = $this->collector->collect($context);
        $second = $this->collector->collect($context);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CostLine::count());
    }

    public function test_a_source_that_carried_its_own_approval_lands_verified(): void
    {
        $this->code();

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001',
            amount: '4500.00',
            jobNumber: 'WNG-TEST-001',
            sourceType: 'GoodsReceiptNote',
            sourceId: 7,
            sourceApproved: true,
        ));

        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertNotNull($line->verified_at);
        // No verifier is attributed: the approval belonged to the source document,
        // and naming someone who never saw this line would be a false trail.
        $this->assertNull($line->verified_by);
        $this->assertNotNull($line->journal_entry_id);
        $this->assertNotNull($line->posted_at);
    }

    public function test_an_approved_purchase_commitment_reserves_budget_without_posting_gl(): void
    {
        $line = $this->collector->postFromSource(new CostContext(
            expenseCode: '', amount: '12500.00', nature: CostLine::NATURE_COMMITTED,
            jobNumber: 'WNG-TEST-001', sourceType: 'PurchaseOrderItem', sourceId: 77,
        ));

        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame(CostLine::NATURE_COMMITTED, $line->nature);
        $this->assertNull($line->journal_entry_id);
        $this->assertNull($line->posted_at);
    }

    public function test_a_locked_period_refuses_the_write(): void
    {
        $this->code();

        AccountingPeriod::forDate(now())->update(['status' => AccountingPeriod::STATUS_LOCKED]);

        $this->expectException(CostValidationException::class);

        $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
        ));
    }

    public function test_a_producer_cannot_post_into_a_locked_period(): void
    {
        AccountingPeriod::forDate(now())->update(['status' => AccountingPeriod::STATUS_LOCKED]);

        $this->expectException(CostValidationException::class);

        $this->collector->postFromSource(new CostContext(
            expenseCode: '',
            amount: '100.00',
            jobNumber: 'WNG-TEST-001',
            sourceType: 'GoodsReceiptNote',
            sourceId: 44,
        ));
    }

    public function test_it_snapshots_budget_remaining_around_the_spend(): void
    {
        $this->code();

        $planned = CostLine::create([
            'ref' => 'CL-0000001',
            'job_number' => 'WNG-TEST-001',
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '60000.00',
            'tax_amount' => '0.00',
            'net_amount' => '60000.00',
            'base_net_amount' => '60000.00',
        ]);

        $first = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '18000.00',
            jobNumber: 'WNG-TEST-001', consumesLineId: $planned->id,
        ));

        $this->assertSame('60000.00', $first->budget_remaining_before);
        $this->assertSame('42000.00', $first->budget_remaining_after);

        // Only verified lines draw the budget down, so a second submission still
        // sees the full remaining balance until the first is verified.
        $first->update(['status' => CostLine::STATUS_VERIFIED]);

        $second = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '2000.00',
            jobNumber: 'WNG-TEST-001', consumesLineId: $planned->id,
        ));

        $this->assertSame('42000.00', $second->budget_remaining_before);
        $this->assertSame('40000.00', $second->budget_remaining_after);
    }

    public function test_spend_with_no_budget_line_is_flagged_unbudgeted(): void
    {
        $this->code();

        $line = $this->collector->collect(new CostContext(
            expenseCode: 'TST-001', amount: '3500.00', jobNumber: 'WNG-TEST-001',
        ));

        $this->assertTrue($line->isUnbudgeted());
        $this->assertNull($line->consumes_line_id);
    }

    public function test_an_unknown_or_retired_expense_code_is_refused(): void
    {
        $this->code(['code' => 'RETIRED-001', 'is_active' => false]);

        $this->expectException(CostValidationException::class);

        $this->collector->collect(new CostContext(
            expenseCode: 'RETIRED-001', amount: '100.00', jobNumber: 'WNG-TEST-001',
        ));
    }
}
